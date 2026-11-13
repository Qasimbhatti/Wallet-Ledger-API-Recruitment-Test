<?php

namespace App\Ledger;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * All money movement goes through here.
 *
 * Data model
 * ----------
 * - ledger_entries: append-only source of truth (one row per credit/debit).
 * - wallets:        one row per user holding the current balance. It is a
 *                   materialised SUM over that user's ledger rows, kept in step
 *                   by writing both in the SAME statement / transaction.
 *
 * Concurrency
 * -----------
 * Each credit/debit is ONE SQL statement (data-modifying CTE) running in its
 * own implicit transaction at READ COMMITTED:
 *
 *   1. UPDATE/UPSERT the user's wallet row. This takes a row lock, so writes
 *      for the same user are serialised by PostgreSQL; writes for different
 *      users never touch the same row and never wait on each other.
 *   2. INSERT the ledger row using the balance RETURNED by step 1.
 *
 * Either both rows are written or neither (single statement = atomic), so the
 * wallet can never drift from the ledger, even if the process dies mid-call.
 *
 * Debit's guard `balance >= amount` sits in the UPDATE's WHERE clause. Under
 * READ COMMITTED, an UPDATE that waited on a row lock re-evaluates its WHERE
 * against the newly committed row version (EvalPlanQual), so two racing
 * debits of 60 against 100 cannot both succeed: the second re-checks
 * 40 >= 60, matches nothing and becomes a 409. A CHECK (balance >= 0)
 * constraint backs this up at the schema level.
 *
 * No statement ever locks more than one wallet row, so deadlocks are not
 * expected; 40P01/40001 are still retried defensively.
 */
final class LedgerService
{
    private const CREDIT_SQL = <<<'SQL'
        WITH wallet AS (
            INSERT INTO wallets AS w (user_id, balance)
            VALUES (?, ?::bigint)
            ON CONFLICT (user_id) DO UPDATE
                SET balance = w.balance + EXCLUDED.balance,
                    updated_at = now()
            RETURNING w.user_id, w.balance
        ), entry AS (
            INSERT INTO ledger_entries (user_id, type, amount, balance_after)
            SELECT user_id, 'credit'::ledger_entry_type, ?::bigint, balance FROM wallet
        )
        SELECT balance FROM wallet
        SQL;

    // `current_balance` is read from the statement's snapshot and is only
    // consulted when the UPDATE matched nothing: NULL means the user does not
    // exist (404), otherwise the balance was too low (409).
    private const DEBIT_SQL = <<<'SQL'
        WITH wallet AS (
            UPDATE wallets
               SET balance = balance - ?::bigint,
                   updated_at = now()
             WHERE user_id = ? AND balance >= ?::bigint
            RETURNING user_id, balance
        ), entry AS (
            INSERT INTO ledger_entries (user_id, type, amount, balance_after)
            SELECT user_id, 'debit'::ledger_entry_type, ?::bigint, balance FROM wallet
        )
        SELECT (SELECT balance FROM wallet) AS new_balance,
               (SELECT balance FROM wallets WHERE user_id = ?) AS current_balance
        SQL;

    private const BALANCE_SQL = 'SELECT balance FROM wallets WHERE user_id = ?';

    /** SQLSTATEs worth retrying: serialization_failure, deadlock_detected. */
    private const RETRYABLE_SQLSTATES = ['40001', '40P01'];

    public function __construct(private readonly DatabaseManager $db) {}

    /** Current balance, or null when the user has never been credited. */
    public function balance(string $userId): ?int
    {
        $row = $this->connection()->selectOne(self::BALANCE_SQL, [$userId]);

        return $row === null ? null : (int) $row->balance;
    }

    public function credit(string $userId, int $amount, ?string $idempotencyKey = null): LedgerResult
    {
        return $this->apply('credit', $userId, $amount, $idempotencyKey);
    }

    public function debit(string $userId, int $amount, ?string $idempotencyKey = null): LedgerResult
    {
        return $this->apply('debit', $userId, $amount, $idempotencyKey);
    }

    /** @return array<string, string> The three hot-path queries (used by ledger:explain). */
    public static function queries(): array
    {
        return ['credit' => self::CREDIT_SQL, 'debit' => self::DEBIT_SQL, 'balance' => self::BALANCE_SQL];
    }

    private function apply(string $operation, string $userId, int $amount, ?string $idempotencyKey): LedgerResult
    {
        return $this->withRetries(fn () => $idempotencyKey === null
            ? $this->execute($operation, $userId, $amount)
            : $this->executeIdempotently($operation, $userId, $amount, $idempotencyKey));
    }

    private function execute(string $operation, string $userId, int $amount): LedgerResult
    {
        $connection = $this->connection();

        if ($operation === 'credit') {
            $row = $connection->selectOne(self::CREDIT_SQL, [$userId, $amount, $amount]);

            return LedgerResult::ok((int) $row->balance);
        }

        $row = $connection->selectOne(self::DEBIT_SQL, [$amount, $userId, $amount, $amount, $userId]);

        if ($row->new_balance !== null) {
            return LedgerResult::ok((int) $row->new_balance);
        }

        return new LedgerResult($row->current_balance === null
            ? LedgerResult::USER_NOT_FOUND
            : LedgerResult::INSUFFICIENT_FUNDS);
    }

    /**
     * Optional Idempotency-Key support. The key is claimed with
     * INSERT ... ON CONFLICT DO NOTHING in the same transaction as the money
     * movement, and the outcome (including 404/409) is stored before COMMIT:
     *
     * - first request: claims the key, moves money, stores the outcome;
     * - a concurrent duplicate blocks on the unique index until the first
     *   commits, then finds the stored outcome and replays it;
     * - if the first request rolls back (crash, error) the claim disappears
     *   with it and a retry can proceed.
     */
    private function executeIdempotently(string $operation, string $userId, int $amount, string $key): LedgerResult
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $claimed = $connection->selectOne(
                'INSERT INTO idempotency_keys (idempotency_key, user_id, operation, amount)
                 VALUES (?, ?, ?::ledger_entry_type, ?::bigint)
                 ON CONFLICT (idempotency_key) DO NOTHING
                 RETURNING idempotency_key',
                [$key, $userId, $operation, $amount],
            );

            if ($claimed === null) {
                $stored = $connection->selectOne(
                    'SELECT user_id, operation::text AS operation, amount, status_code, balance
                       FROM idempotency_keys WHERE idempotency_key = ?',
                    [$key],
                );
                $connection->commit();

                if ($stored === null || $stored->status_code === null) {
                    throw new RuntimeException('Idempotency key vanished or has no stored outcome.');
                }

                if ($stored->user_id !== $userId || $stored->operation !== $operation || (int) $stored->amount !== $amount) {
                    return new LedgerResult(LedgerResult::IDEMPOTENCY_KEY_REUSED);
                }

                $balance = $stored->balance === null ? null : (int) $stored->balance;

                return (new LedgerResult((int) $stored->status_code, $balance))->asReplay();
            }

            $result = $this->execute($operation, $userId, $amount);

            $connection->update(
                'UPDATE idempotency_keys SET status_code = ?, balance = ?::bigint WHERE idempotency_key = ?',
                [$result->status, $result->balance, $key],
            );
            $connection->commit();

            return $result;
        } catch (Throwable $e) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function withRetries(callable $operation): mixed
    {
        $maxAttempts = max(1, (int) config('wallet.max_attempts', 5));

        for ($attempt = 1; ; $attempt++) {
            try {
                return $operation();
            } catch (QueryException $e) {
                if ($attempt >= $maxAttempts || ! in_array((string) $e->getCode(), self::RETRYABLE_SQLSTATES, true)) {
                    throw $e;
                }

                // Small jittered backoff so retried transactions do not collide again.
                usleep(random_int(500, 2000) * $attempt);
            }
        }
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
