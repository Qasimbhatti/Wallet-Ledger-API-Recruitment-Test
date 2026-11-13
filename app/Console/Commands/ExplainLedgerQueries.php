<?php

namespace App\Console\Commands;

use App\Ledger\LedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prints EXPLAIN (ANALYZE, BUFFERS) for the exact SQL the API runs.
 * Writes happen inside a transaction that is rolled back.
 */
final class ExplainLedgerQueries extends Command
{
    protected $signature = 'ledger:explain {user=user_000000 : An existing user (default is a 150k-row whale)}';

    protected $description = 'Show the query plans of the credit, debit and get_balance queries.';

    public function handle(): int
    {
        $userId = (string) $this->argument('user');
        $queries = LedgerService::queries();
        $db = DB::connection();

        $plans = [
            'get_balance' => [$queries['balance'], [$userId]],
            'credit' => [$queries['credit'], [$userId, 100, 100]],
            'debit' => [$queries['debit'], [1, $userId, 1, 1, $userId]],
        ];

        $db->beginTransaction();

        try {
            foreach ($plans as $name => [$sql, $bindings]) {
                $rows = $db->select('EXPLAIN (ANALYZE, BUFFERS, COSTS, TIMING) '.$sql, $bindings);

                $this->newLine();
                $this->line("<info>== {$name} ({$userId}) ==</info>");
                foreach ($rows as $row) {
                    $this->line(((array) $row)['QUERY PLAN']);
                }
            }
        } finally {
            $db->rollBack();
        }

        return self::SUCCESS;
    }
}
