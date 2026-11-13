<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-loads 10M+ ledger rows across 100k users, entirely inside PostgreSQL
 * (generate_series), so no rows cross the network.
 *
 * Distribution at --scale=1 (default):
 *   - 25 "whale" users   x 150,000 rows = 3,750,000
 *   - 975 "medium" users x   2,000 rows = 1,950,000
 *   - 99,000 users       x 2..100 rows  ≈ 5,049,000   (2 x (1 + i % 50))
 *   ≈ 10.75M rows in total.
 *
 * Every user's history is (credit, debit) pairs. Each user i has a fixed
 * positive net per pair, n = 1 + (i*7919 mod 1000), and the debit of pair k
 * is (credit_k - n). Hence:
 *   - balance after the credit of pair k = (k-1)*n + credit_k  > 0
 *   - balance after the debit  of pair k = k*n                 > 0
 * so the balance is never negative at any point, and balance_after can be
 * written in closed form without a running-sum window over 10M rows.
 * Amounts come from a multiplicative hash, so the seed is deterministic.
 *
 * Speed: everything runs in ONE transaction that starts with TRUNCATE; with
 * wal_level=minimal (see docker-compose.yml) PostgreSQL skips WAL for the
 * bulk insert. Indexes are dropped before the load and rebuilt after.
 */
final class SeedLedger extends Command
{
    protected $signature = 'ledger:seed
        {--users=100000 : Number of users}
        {--scale=1 : Multiplier for rows per user (0.1 gives ~1.1M rows, for the 1M vs 10M comparison)}
        {--force : Wipe existing ledger data without asking}';

    protected $description = 'Load the deterministic 10M+ row seed (wipes ledger_entries, wallets, idempotency_keys).';

    public function handle(): int
    {
        $users = max(1000, (int) $this->option('users'));
        $scale = max(0.001, (float) $this->option('scale'));

        $whales = 25;
        $mediums = 975;
        $whalePairs = max(1, (int) round(75_000 * $scale));
        $mediumPairs = max(1, (int) round(1_000 * $scale));
        $smallMaxPairs = max(1, (int) round(50 * $scale));
        $idWidth = max(6, strlen((string) ($users - 1)));

        /** @var Connection $db */
        $db = DB::connection();

        $existing = $db->selectOne('SELECT EXISTS (SELECT 1 FROM ledger_entries) AS present')->present;
        if ($existing && ! $this->option('force')
            && ! $this->confirm('ledger_entries is not empty. Wipe ledger_entries, wallets and idempotency_keys and reseed?')) {
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Seeding %s users: %d whales x %s rows, %d medium x %s rows, rest 2..%d rows.',
            number_format($users), $whales, number_format($whalePairs * 2), $mediums, number_format($mediumPairs * 2), $smallMaxPairs * 2,
        ));

        $started = microtime(true);

        $db->unprepared("SET statement_timeout = 0; SET maintenance_work_mem = '1GB'; SET work_mem = '256MB'; SET synchronous_commit = off;");

        $db->beginTransaction();

        $this->step('Truncate + drop indexes', fn () => $db->unprepared(<<<'SQL'
            TRUNCATE ledger_entries, wallets, idempotency_keys RESTART IDENTITY;
            ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_pkey;
            DROP INDEX IF EXISTS ledger_entries_user_id_id_idx;
            SQL));

        $this->step('Plan users', fn () => $db->unprepared(<<<SQL
            CREATE TEMP TABLE seed_users ON COMMIT DROP AS
            SELECT s.user_id, s.pairs, s.net,
                   1 + 2 * (sum(s.pairs) OVER (ORDER BY s.i) - s.pairs) AS first_id
              FROM (
                SELECT i,
                       'user_' || lpad(i::text, {$idWidth}, '0') AS user_id,
                       (CASE
                            WHEN i < {$whales} THEN {$whalePairs}
                            WHEN i < {$whales} + {$mediums} THEN {$mediumPairs}
                            ELSE 1 + (i % {$smallMaxPairs})
                        END)::bigint AS pairs,
                       1 + (i * 7919) % 1000 AS net
                  FROM generate_series(0::bigint, {$users} - 1) AS i
              ) s;
            SQL));

        $this->step('Insert ledger rows', fn () => $db->unprepared(<<<'SQL'
            INSERT INTO ledger_entries (id, user_id, type, amount, balance_after, created_at)
            SELECT e.id, u.user_id, e.type, e.amount, e.balance_after,
                   timestamptz '2025-01-01 00:00:00+00' + e.id * interval '1 second'
              FROM seed_users u
             CROSS JOIN LATERAL generate_series(1::bigint, u.pairs) AS k
             CROSS JOIN LATERAL (
                   SELECT u.net + 1 + (((u.first_id + 2 * k) * 2654435761) % 99991) AS credit
             ) c
             CROSS JOIN LATERAL (VALUES
                   (u.first_id + 2 * (k - 1),     'credit'::ledger_entry_type, c.credit,         (k - 1) * u.net + c.credit),
                   (u.first_id + 2 * (k - 1) + 1, 'debit'::ledger_entry_type,  c.credit - u.net, k * u.net)
             ) AS e(id, type, amount, balance_after);
            SQL));

        $this->step('Rebuild primary key + (user_id, id) index', fn () => $db->unprepared(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_pkey PRIMARY KEY (id);
            CREATE INDEX ledger_entries_user_id_id_idx ON ledger_entries (user_id, id);
            SELECT setval(pg_get_serial_sequence('ledger_entries', 'id'), (SELECT max(id) FROM ledger_entries));
            SQL));

        $this->step('Build wallets from the ledger', fn () => $db->unprepared(<<<'SQL'
            INSERT INTO wallets (user_id, balance, created_at, updated_at)
            SELECT user_id,
                   sum(CASE WHEN type = 'credit' THEN amount ELSE -amount END),
                   min(created_at),
                   max(created_at)
              FROM ledger_entries
             GROUP BY user_id;
            SQL));

        // Cross-check: the aggregate over the ledger must equal the closed form.
        $mismatches = (int) $db->selectOne(
            'SELECT count(*) AS n FROM wallets w JOIN seed_users u USING (user_id) WHERE w.balance <> u.pairs * u.net'
        )->n;

        if ($mismatches !== 0) {
            $db->rollBack();
            $this->error("Seed self-check failed: {$mismatches} wallets disagree with the ledger. Rolled back.");

            return self::FAILURE;
        }

        $this->step('Commit', fn () => $db->commit());

        // Outside the transaction: sets hint bits / visibility map and stats.
        $this->step('VACUUM ANALYZE', fn () => $db->unprepared('VACUUM (ANALYZE) ledger_entries; VACUUM (ANALYZE) wallets;'));

        $stats = $db->selectOne(<<<'SQL'
            SELECT (SELECT count(*) FROM ledger_entries) AS rows,
                   (SELECT count(*) FROM wallets) AS users,
                   (SELECT max(c) FROM (SELECT count(*) AS c FROM ledger_entries GROUP BY user_id) t) AS max_rows_per_user
            SQL);

        $this->newLine();
        $this->info(sprintf(
            'Done in %.1fs: %s ledger rows, %s users, max %s rows for one user.',
            microtime(true) - $started,
            number_format((int) $stats->rows),
            number_format((int) $stats->users),
            number_format((int) $stats->max_rows_per_user),
        ));

        return self::SUCCESS;
    }

    private function step(string $label, callable $callback): void
    {
        $started = microtime(true);
        $this->output->write(str_pad("  {$label} ", 48, '.'));
        $callback();
        $this->output->writeln(sprintf(' %.1fs', microtime(true) - $started));
    }
}
