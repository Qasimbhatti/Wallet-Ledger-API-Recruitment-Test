<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The same checks a reviewer would run after a load test.
 * Exit code is non-zero if anything disagrees.
 */
final class ReconcileLedger extends Command
{
    protected $signature = 'ledger:reconcile
        {--deep : Also replay every user\'s history and check balance_after row by row (full window scan)}';

    protected $description = 'Check every wallet balance against SUM(credits) - SUM(debits) in the ledger.';

    public function handle(): int
    {
        $db = DB::connection();
        $db->unprepared('SET statement_timeout = 0');
        $ok = true;

        $summary = $db->selectOne(<<<'SQL'
            WITH ledger AS (
                SELECT user_id,
                       sum(CASE WHEN type = 'credit' THEN amount ELSE -amount END) AS balance,
                       count(*) AS entries
                  FROM ledger_entries
                 GROUP BY user_id
            )
            SELECT count(*)                                                   AS users,
                   coalesce(sum(l.entries), 0)                                AS entries,
                   count(*) FILTER (WHERE w.user_id IS NULL)                  AS ledger_users_without_wallet,
                   count(*) FILTER (WHERE l.user_id IS NULL)                  AS wallets_without_ledger,
                   count(*) FILTER (WHERE w.balance IS DISTINCT FROM l.balance) AS balance_mismatches,
                   count(*) FILTER (WHERE w.balance < 0)                      AS negative_balances
              FROM wallets w
              FULL OUTER JOIN ledger l ON l.user_id = w.user_id
            SQL);

        $this->table(['check', 'value'], collect((array) $summary)->map(fn ($v, $k) => [$k, $v])->values()->all());

        foreach (['ledger_users_without_wallet', 'wallets_without_ledger', 'balance_mismatches', 'negative_balances'] as $check) {
            $ok = $ok && (int) $summary->{$check} === 0;
        }

        if ($this->option('deep')) {
            $deep = $db->selectOne(<<<'SQL'
                SELECT count(*) FILTER (WHERE running <> balance_after) AS balance_after_mismatches,
                       count(*) FILTER (WHERE running < 0)              AS negative_points
                  FROM (
                    SELECT balance_after,
                           sum(CASE WHEN type = 'credit' THEN amount ELSE -amount END)
                               OVER (PARTITION BY user_id ORDER BY id) AS running
                      FROM ledger_entries
                  ) t
                SQL);

            $this->table(['deep check', 'value'], [
                ['balance_after_mismatches', $deep->balance_after_mismatches],
                ['negative_points_in_history', $deep->negative_points],
            ]);

            $ok = $ok && (int) $deep->balance_after_mismatches === 0 && (int) $deep->negative_points === 0;
        }

        $ok ? $this->info('Ledger reconciles.') : $this->error('Ledger does NOT reconcile.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
