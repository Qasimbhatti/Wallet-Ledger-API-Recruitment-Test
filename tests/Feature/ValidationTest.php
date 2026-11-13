<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ValidationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function invalidBodies(): array
    {
        $body = fn (string $userId, string $amount) => sprintf('{"user_id": %s, "amount": %s}', $userId, $amount);

        return [
            // Malformed / wrong top-level type
            'empty body' => [''],
            'truncated json' => ['{"user_id": "u1", "amount": 10'],
            'json null' => ['null'],
            'json array' => ['[{"user_id": "u1", "amount": 10}]'],
            'json string' => ['"u1"'],
            'trailing garbage' => ['{"user_id": "u1", "amount": 10} x'],
            // Missing fields
            'empty object' => ['{}'],
            'missing amount' => ['{"user_id": "u1"}'],
            'missing user_id' => ['{"amount": 10}'],
            // user_id rules
            'user_id empty' => [$body('""', '10')],
            'user_id too long' => [$body('"'.str_repeat('a', 65).'"', '10')],
            'user_id with space' => [$body('"u 1"', '10')],
            'user_id with dot' => [$body('"u.1"', '10')],
            'user_id with slash' => [$body('"u/1"', '10')],
            'user_id unicode' => [$body('"ü1"', '10')],
            'user_id trailing newline' => [$body('"u1\n"', '10')],
            'user_id number' => [$body('123', '10')],
            'user_id null' => [$body('null', '10')],
            'user_id array' => [$body('["u1"]', '10')],
            // amount rules
            'amount zero' => [$body('"u1"', '0')],
            'amount negative' => [$body('"u1"', '-5')],
            'amount negative zero' => [$body('"u1"', '-0')],
            'amount decimal' => [$body('"u1"', '10.5')],
            'amount float with zero fraction' => [$body('"u1"', '10.0')],
            'amount exponent' => [$body('"u1"', '1e3')],
            'amount string' => [$body('"u1"', '"100"')],
            'amount too large' => [$body('"u1"', '1000000001')],
            'amount beyond int64' => [$body('"u1"', '99999999999999999999')],
            'amount boolean' => [$body('"u1"', 'true')],
            'amount null' => [$body('"u1"', 'null')],
            'amount object' => [$body('"u1"', '{"value": 10}')],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_credit_rejects_invalid_input(string $body): void
    {
        $this->postRaw('/credit', $body)
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request')
            ->assertJsonStructure(['error', 'message']);

        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('wallets', 0);
    }

    #[DataProvider('invalidBodies')]
    public function test_debit_rejects_invalid_input(string $body): void
    {
        $this->postRaw('/credit', '{"user_id": "u1", "amount": 1000}')->assertOk();

        $this->postRaw('/debit', $body)
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');

        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_boundary_values_are_accepted(): void
    {
        $longId = str_repeat('Az0_-', 12).'abcd'; // 64 chars

        $this->postRaw('/credit', sprintf('{"user_id": "%s", "amount": 1}', $longId))
            ->assertOk()->assertExactJson(['user_id' => $longId, 'balance' => 1]);

        $this->postRaw('/credit', sprintf('{"user_id": "%s", "amount": 1000000000}', $longId))
            ->assertOk()->assertExactJson(['user_id' => $longId, 'balance' => 1_000_000_001]);
    }

    public function test_unknown_fields_are_ignored(): void
    {
        $this->postRaw('/credit', '{"user_id": "u1", "amount": 10, "currency": "KES", "note": {"a": 1}}')
            ->assertOk()
            ->assertExactJson(['user_id' => 'u1', 'balance' => 10]);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidBalancePaths(): array
    {
        return [
            'missing id' => ['/balance'],
            'too long' => ['/balance/'.str_repeat('a', 65)],
            'bad characters' => ['/balance/u%201'],
            'dot' => ['/balance/u.1'],
        ];
    }

    #[DataProvider('invalidBalancePaths')]
    public function test_balance_rejects_invalid_user_ids(string $uri): void
    {
        $this->getJson($uri, $this->authHeaders())
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    public function test_wrong_http_method_is_a_json_405(): void
    {
        $this->getJson('/credit', $this->authHeaders())
            ->assertStatus(405)
            ->assertJsonPath('error', 'method_not_allowed');
    }
}
