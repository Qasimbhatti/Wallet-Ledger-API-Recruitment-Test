<?php

namespace Tests\Concurrency;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;
use Throwable;

/**
 * Base class for tests that fire real concurrent HTTP requests at the running
 * server (docker compose up) and then reconcile the live database with SQL.
 *
 * Each test uses fresh random user ids, so it can run against a seeded
 * database without interfering with existing data.
 */
abstract class LiveServerTestCase extends TestCase
{
    protected Client $http;

    protected function setUp(): void
    {
        parent::setUp();

        $token = (string) env('API_TOKEN', '');
        if ($token === '') {
            $this->markTestSkipped('API_TOKEN is not set; concurrency tests need the live server token.');
        }

        $this->http = new Client([
            'base_uri' => rtrim((string) env('TEST_BASE_URL', 'http://127.0.0.1:8080'), '/'),
            'http_errors' => false,
            'timeout' => 60,
            'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'],
        ]);

        try {
            $status = $this->http->get('/health', ['timeout' => 5])->getStatusCode();
        } catch (Throwable $e) {
            $this->markTestSkipped('Live server not reachable: '.$e->getMessage());
        }

        $this->assertSame(200, $status, 'GET /health on the live server did not return 200.');
    }

    protected function freshUser(string $prefix = 't'): string
    {
        return $prefix.'_'.bin2hex(random_bytes(8));
    }

    protected function live(): Connection
    {
        return DB::connection('pgsql_live');
    }

    /**
     * Run request factories with at most $concurrency in flight. Each factory
     * returns a promise. Results are keyed like the input.
     *
     * @param  array<int|string, callable(): PromiseInterface>  $factories
     * @return array<int|string, mixed>
     */
    protected function concurrently(array $factories, int $concurrency = 100): array
    {
        $results = [];
        $errors = [];

        $pool = new Pool($this->http, (function () use ($factories) {
            foreach ($factories as $key => $factory) {
                yield $key => $factory;
            }
        })(), [
            'concurrency' => $concurrency,
            'fulfilled' => function ($value, $key) use (&$results) {
                $results[$key] = $value;
            },
            'rejected' => function ($reason, $key) use (&$errors) {
                $errors[$key] = $reason instanceof Throwable ? $reason->getMessage() : (string) $reason;
            },
        ]);

        $pool->promise()->wait();

        $this->assertSame([], $errors, 'Transport errors under concurrency.');
        ksort($results);

        return $results;
    }

    protected function op(string $uri, string $userId, int $amount, array $headers = []): callable
    {
        return fn () => $this->http->postAsync($uri, ['json' => ['user_id' => $userId, 'amount' => $amount], 'headers' => $headers]);
    }

    protected function balanceOf(string $userId): int
    {
        $response = $this->http->get('/balance/'.$userId);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response)['balance'];
    }

    protected function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param  ResponseInterface[]  $responses */
    protected function statusCounts(array $responses): array
    {
        $counts = [];
        foreach ($responses as $response) {
            $counts[$response->getStatusCode()] = ($counts[$response->getStatusCode()] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * The reviewer's check, per user: wallet == SUM(credits) - SUM(debits),
     * and the running balance recorded on every ledger row is consistent and
     * never negative.
     */
    protected function assertReconciles(string $userId, ?int $expectedBalance = null): void
    {
        $row = $this->live()->selectOne(<<<'SQL'
            SELECT (SELECT balance FROM wallets WHERE user_id = ?) AS wallet,
                   (SELECT coalesce(sum(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0)
                      FROM ledger_entries WHERE user_id = ?) AS ledger,
                   (SELECT count(*) FROM (
                        SELECT balance_after,
                               sum(CASE WHEN type = 'credit' THEN amount ELSE -amount END) OVER (ORDER BY id) AS running
                          FROM ledger_entries WHERE user_id = ?
                    ) t WHERE running <> balance_after OR running < 0) AS bad_rows
            SQL, [$userId, $userId, $userId]);

        $this->assertNotNull($row->wallet, "No wallet for {$userId}");
        $this->assertSame((int) $row->ledger, (int) $row->wallet, "Wallet disagrees with ledger for {$userId}");
        $this->assertSame(0, (int) $row->bad_rows, "Inconsistent running balance in the ledger for {$userId}");

        if ($expectedBalance !== null) {
            $this->assertSame($expectedBalance, (int) $row->wallet, "Unexpected final balance for {$userId}");
        }
    }

    protected function percentile(array $values, float $p): float
    {
        sort($values);

        return $values[(int) max(0, ceil($p / 100 * count($values)) - 1)];
    }
}
