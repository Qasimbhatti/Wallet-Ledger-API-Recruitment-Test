<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LedgerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_credit_creates_the_user_and_later_credits_accumulate(): void
    {
        $this->credit('u123', 15000)->assertOk()->assertExactJson(['user_id' => 'u123', 'balance' => 15000]);
        $this->credit('u123', 10000)->assertOk()->assertExactJson(['user_id' => 'u123', 'balance' => 25000]);

        $this->balance('u123')->assertOk()->assertExactJson(['user_id' => 'u123', 'balance' => 25000]);
    }

    public function test_debit_subtracts_and_returns_the_new_balance(): void
    {
        $this->credit('u123', 25000);

        $this->debit('u123', 4000)->assertOk()->assertExactJson(['user_id' => 'u123', 'balance' => 21000]);
        $this->balance('u123')->assertExactJson(['user_id' => 'u123', 'balance' => 21000]);
    }

    public function test_debit_down_to_exactly_zero_is_allowed(): void
    {
        $this->credit('u1', 500);

        $this->debit('u1', 500)->assertOk()->assertJsonPath('balance', 0);
        $this->balance('u1')->assertJsonPath('balance', 0);
    }

    public function test_overdraft_is_a_409_and_writes_nothing(): void
    {
        $this->credit('u1', 100);

        $this->debit('u1', 101)
            ->assertStatus(409)
            ->assertExactJson([
                'error' => 'insufficient_funds',
                'message' => 'The balance is too low for this debit; nothing was written.',
            ]);

        $this->balance('u1')->assertJsonPath('balance', 100);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_unknown_users_are_404_for_debit_and_balance(): void
    {
        $this->debit('ghost', 1)->assertStatus(404)->assertJsonPath('error', 'user_not_found');
        $this->balance('ghost')->assertStatus(404)->assertJsonPath('error', 'user_not_found');

        // A failed debit must not create the user.
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_users_are_isolated_and_ids_are_case_sensitive(): void
    {
        $this->credit('alice', 100);
        $this->credit('Alice', 7);

        $this->balance('alice')->assertJsonPath('balance', 100);
        $this->balance('Alice')->assertJsonPath('balance', 7);
    }

    public function test_every_operation_writes_one_ledger_row_with_its_running_balance(): void
    {
        $this->credit('u1', 1000);
        $this->debit('u1', 300);
        $this->debit('u1', 5000); // rejected
        $this->credit('u1', 50);

        $rows = DB::select('SELECT user_id, type::text AS type, amount, balance_after FROM ledger_entries ORDER BY id');

        $this->assertSame(
            [
                ['u1', 'credit', 1000, 1000],
                ['u1', 'debit', 300, 700],
                ['u1', 'credit', 50, 750],
            ],
            array_map(fn ($r) => [$r->user_id, $r->type, (int) $r->amount, (int) $r->balance_after], $rows),
        );

        $this->assertWalletMatchesLedger('u1');
    }

    public function test_ledger_rows_cannot_be_updated_or_deleted(): void
    {
        $this->credit('u1', 1000);

        foreach (['UPDATE ledger_entries SET amount = 1', 'DELETE FROM ledger_entries'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql)); // savepoint, so the test transaction survives
                $this->fail("Expected [{$sql}] to be rejected.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->assertDatabaseHas('ledger_entries', ['user_id' => 'u1', 'amount' => 1000]);
    }

    public function test_schema_rejects_negative_balances_even_outside_the_api(): void
    {
        $this->credit('u1', 10);

        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::update("UPDATE wallets SET balance = -1 WHERE user_id = 'u1'"));
    }

    public function test_health_reports_database_status(): void
    {
        $this->getJson('/health', $this->authHeaders())->assertOk()->assertExactJson(['status' => 'ok', 'database' => 'up']);
    }

    private function assertWalletMatchesLedger(string $userId): void
    {
        $row = DB::selectOne(
            "SELECT (SELECT balance FROM wallets WHERE user_id = ?) AS wallet,
                    (SELECT sum(CASE WHEN type = 'credit' THEN amount ELSE -amount END) FROM ledger_entries WHERE user_id = ?) AS ledger",
            [$userId, $userId],
        );

        $this->assertSame((int) $row->ledger, (int) $row->wallet);
    }

    private function credit(string $userId, int $amount)
    {
        return $this->postJson('/credit', ['user_id' => $userId, 'amount' => $amount], $this->authHeaders());
    }

    private function debit(string $userId, int $amount)
    {
        return $this->postJson('/debit', ['user_id' => $userId, 'amount' => $amount], $this->authHeaders());
    }

    private function balance(string $userId)
    {
        return $this->getJson('/balance/'.$userId, $this->authHeaders());
    }
}
