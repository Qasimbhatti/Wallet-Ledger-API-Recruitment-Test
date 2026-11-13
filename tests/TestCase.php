<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected const TOKEN = 'test-suite-token';

    /** migrate:fresh must also drop the ledger_entry_type enum. */
    protected bool $dropTypes = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Guard rail: the in-process suite runs migrate:fresh; it must never
        // run against the live database.
        if (DB::connection()->getDatabaseName() !== 'wallet_test') {
            throw new RuntimeException('Feature tests must run against the wallet_test database.');
        }

        config(['wallet.api_token' => self::TOKEN]);
    }

    /** @return array<string, string> */
    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN];
    }

    /**
     * POST a raw body (so tests control the exact JSON bytes).
     */
    protected function postRaw(string $uri, string $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers + $this->authHeaders() + ['Content-Type' => 'application/json']);

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }
}
