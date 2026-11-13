<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function endpoints(): array
    {
        return [
            'credit' => ['POST', '/credit'],
            'debit' => ['POST', '/debit'],
            'balance' => ['GET', '/balance/u123'],
            'health' => ['GET', '/health'],
            'unknown route' => ['GET', '/does-not-exist'],
        ];
    }

    /** @return array<string, array{0: ?string}> */
    public static function badAuthorizationHeaders(): array
    {
        return [
            'missing header' => [null],
            'empty header' => [''],
            'wrong scheme' => ['Basic '.base64_encode('user:'.self::TOKEN)],
            'token without scheme' => [self::TOKEN],
            'scheme without token' => ['Bearer '],
            'wrong token' => ['Bearer not-the-token'],
            'token prefix' => ['Bearer '.substr(self::TOKEN, 0, -1)],
            'token with suffix' => ['Bearer '.self::TOKEN.'x'],
            'two tokens' => ['Bearer '.self::TOKEN.' '.self::TOKEN],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_every_endpoint_rejects_a_missing_token(string $method, string $uri): void
    {
        $this->json($method, $uri, ['user_id' => 'u123', 'amount' => 100])
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized', 'message' => 'Missing or invalid bearer token.']);
    }

    #[DataProvider('badAuthorizationHeaders')]
    public function test_bad_authorization_headers_are_rejected(?string $header): void
    {
        $headers = $header === null ? [] : ['Authorization' => $header];

        $this->postJson('/credit', ['user_id' => 'u123', 'amount' => 100], $headers)
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthorized');

        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_rejection_happens_before_any_database_access(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->postJson('/credit', ['user_id' => 'u123', 'amount' => 100], ['Authorization' => 'Bearer wrong'])->assertStatus(401);
        $this->getJson('/balance/u123')->assertStatus(401);

        $this->assertSame(0, $queries);
    }

    public function test_an_unset_server_token_fails_closed(): void
    {
        config(['wallet.api_token' => '']);

        $this->getJson('/balance/u123', ['Authorization' => 'Bearer '])->assertStatus(401);
        $this->getJson('/balance/u123', ['Authorization' => 'Bearer anything'])->assertStatus(401);
    }

    public function test_the_correct_token_is_accepted_and_never_echoed(): void
    {
        $response = $this->postJson('/credit', ['user_id' => 'u123', 'amount' => 100], $this->authHeaders())
            ->assertOk();

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString(self::TOKEN, (string) $response->headers);
    }

    public function test_scheme_name_is_case_insensitive(): void
    {
        $this->getJson('/health', ['Authorization' => 'bearer '.self::TOKEN])->assertOk();
    }

    public function test_unknown_routes_with_a_valid_token_are_json_404s(): void
    {
        $this->getJson('/nope', $this->authHeaders())
            ->assertStatus(404)
            ->assertJsonPath('error', 'not_found');
    }
}
