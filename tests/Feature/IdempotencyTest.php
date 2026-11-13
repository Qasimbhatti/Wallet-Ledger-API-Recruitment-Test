<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_retried_credit_is_applied_once_and_replayed(): void
    {
        $first = $this->send('/credit', 'u1', 500, 'key-1')->assertOk()->assertExactJson(['user_id' => 'u1', 'balance' => 500]);
        $this->assertFalse($first->headers->has('Idempotent-Replayed'));

        $this->send('/credit', 'u1', 500, 'key-1')
            ->assertOk()
            ->assertExactJson(['user_id' => 'u1', 'balance' => 500])
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_a_rejected_debit_is_replayed_as_rejected(): void
    {
        $this->send('/credit', 'u1', 100, 'c-1');
        $this->send('/debit', 'u1', 150, 'd-1')->assertStatus(409);

        // Funds arrive later, but the same key still returns the original outcome.
        $this->send('/credit', 'u1', 100, 'c-2');
        $this->send('/debit', 'u1', 150, 'd-1')->assertStatus(409)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_reusing_a_key_for_a_different_request_is_rejected(): void
    {
        $this->send('/credit', 'u1', 100, 'k');

        $this->send('/credit', 'u1', 101, 'k')->assertStatus(422)->assertJsonPath('error', 'idempotency_key_reused');
        $this->send('/debit', 'u1', 100, 'k')->assertStatus(422);
        $this->send('/credit', 'u2', 100, 'k')->assertStatus(422);

        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_requests_without_a_key_are_not_deduplicated(): void
    {
        $this->postJson('/credit', ['user_id' => 'u1', 'amount' => 5], $this->authHeaders());
        $this->postJson('/credit', ['user_id' => 'u1', 'amount' => 5], $this->authHeaders())->assertJsonPath('balance', 10);
    }

    public function test_malformed_keys_are_rejected(): void
    {
        $this->send('/credit', 'u1', 5, 'has space')->assertStatus(400)->assertJsonPath('error', 'invalid_request');
        $this->send('/credit', 'u1', 5, str_repeat('k', 256))->assertStatus(400);
    }

    private function send(string $uri, string $userId, int $amount, string $key)
    {
        return $this->postJson($uri, ['user_id' => $userId, 'amount' => $amount], $this->authHeaders() + ['Idempotency-Key' => $key]);
    }
}
