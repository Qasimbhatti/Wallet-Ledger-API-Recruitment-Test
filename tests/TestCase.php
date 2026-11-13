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

    /**
     * Guard rail, checked as soon as the app boots and therefore BEFORE
     * RefreshDatabase runs migrate:fresh: a misconfigured environment must
     * never wipe the live database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $database = $app['config']->get('database.connections.pgsql.database');
        if ($database !== 'wallet_test') {
            throw new RuntimeException("Tests must run against the wallet_test database, not [{$database}].");
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

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
