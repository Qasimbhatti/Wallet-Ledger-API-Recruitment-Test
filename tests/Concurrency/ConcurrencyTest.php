<?php

namespace Tests\Concurrency;

/**
 * The six concurrency rules from the brief, exercised over real HTTP with
 * real parallelism (curl multi, up to 100-200 requests in flight).
 *
 * Run with: docker compose exec app vendor/bin/phpunit --testsuite Concurrency
 */
final class ConcurrencyTest extends LiveServerTestCase
{
    /** Rule 1: two concurrent debits of 60 against 100 → exactly one 200 and one 409. Repeated to catch races. */
    public function test_two_racing_debits_cannot_overdraw(): void
    {
        for ($round = 0; $round < 30; $round++) {
            $user = $this->freshUser('race');
            $this->assertSame(200, $this->http->post('/credit', ['json' => ['user_id' => $user, 'amount' => 100]])->getStatusCode());

            $responses = $this->concurrently([
                $this->op('/debit', $user, 60),
                $this->op('/debit', $user, 60),
            ]);

            $this->assertSame([200 => 1, 409 => 1], $this->statusCounts($responses), "Round {$round}");
            $this->assertSame(40, $this->balanceOf($user));
            $this->assertReconciles($user, 40);
        }
    }

    /** Rule 1, harder: 200 debits of 10 race for a balance of 1000 → exactly 100 succeed. */
    public function test_many_racing_debits_drain_to_exactly_zero(): void
    {
        $user = $this->freshUser('drain');
        $this->http->post('/credit', ['json' => ['user_id' => $user, 'amount' => 1000]]);

        $responses = $this->concurrently(array_fill(0, 200, $this->op('/debit', $user, 10)));

        $this->assertSame([200 => 100, 409 => 100], $this->statusCounts($responses));

        // Every successful debit reported a distinct post-debit balance 0..990.
        $balances = [];
        foreach ($responses as $r) {
            if ($r->getStatusCode() === 200) {
                $balances[] = $this->decode($r)['balance'];
            }
        }
        sort($balances);
        $this->assertSame(range(0, 990, 10), $balances);

        $this->assertReconciles($user, 0);
    }

    /** Rule 2: 1,000 concurrent credits of 100 raise the balance by exactly 100,000. */
    public function test_concurrent_credits_lose_no_updates(): void
    {
        $user = $this->freshUser('credits');
        $this->http->post('/credit', ['json' => ['user_id' => $user, 'amount' => 5]]);

        $responses = $this->concurrently(array_fill(0, 1000, $this->op('/credit', $user, 100)));

        $this->assertSame([200 => 1000], $this->statusCounts($responses));
        $this->assertSame(100_005, $this->balanceOf($user));
        $this->assertSame(1001, (int) $this->live()->selectOne('SELECT count(*) AS n FROM ledger_entries WHERE user_id = ?', [$user])->n);
        $this->assertReconciles($user, 100_005);
    }

    /** Rule 2 for brand-new users: concurrent first credits must not race on wallet creation. */
    public function test_concurrent_first_credits_create_the_user_once(): void
    {
        $user = $this->freshUser('first');

        $responses = $this->concurrently(array_fill(0, 100, $this->op('/credit', $user, 7)));

        $this->assertSame([200 => 100], $this->statusCounts($responses));
        $this->assertReconciles($user, 700);
    }

    /**
     * Rules 1-3 together: a random mix of credits and debits over a few users.
     * The final balance must equal the sum of the operations that returned 200,
     * and the ledger must reconcile.
     */
    public function test_random_mixed_traffic_reconciles(): void
    {
        $users = array_map(fn () => $this->freshUser('mix'), range(1, 8));
        foreach ($users as $user) {
            $this->http->post('/credit', ['json' => ['user_id' => $user, 'amount' => 500]]);
        }

        $ops = [];
        for ($i = 0; $i < 2000; $i++) {
            $ops[] = [
                'user' => $users[array_rand($users)],
                'type' => random_int(0, 99) < 45 ? 'credit' : 'debit',
                'amount' => random_int(1, 300),
            ];
        }

        $responses = $this->concurrently(array_map(fn ($op) => $this->op('/'.$op['type'], $op['user'], $op['amount']), $ops));

        $expected = array_fill_keys($users, 500);
        foreach ($ops as $i => $op) {
            $status = $responses[$i]->getStatusCode();
            $this->assertContains($status, [200, 409], 'Unexpected status '.$status.': '.$responses[$i]->getBody());
            if ($status === 200) {
                $expected[$op['user']] += $op['type'] === 'credit' ? $op['amount'] : -$op['amount'];
            }
            if ($status === 409) {
                $this->assertSame('debit', $op['type']);
            }
        }

        foreach ($users as $user) {
            $this->assertSame($expected[$user], $this->balanceOf($user));
            $this->assertReconciles($user, $expected[$user]);
        }
    }

    /** Rule 4: once credit/debit returns 200, an immediately following read reflects it. */
    public function test_read_your_writes(): void
    {
        $factories = [];
        for ($i = 0; $i < 200; $i++) {
            $user = $this->freshUser('ryw');
            $factories[] = fn () => $this->http
                ->postAsync('/credit', ['json' => ['user_id' => $user, 'amount' => 1000]])
                ->then(function ($credit) use ($user) {
                    $this->assertSame(200, $credit->getStatusCode());

                    return $this->http->getAsync('/balance/'.$user)->then(fn ($read) => [$this->decode($credit), $read]);
                })
                ->then(function (array $pair) use ($user) {
                    [$credit, $read] = $pair;
                    $this->assertSame($credit['balance'], $this->decode($read)['balance']);

                    return $this->http->postAsync('/debit', ['json' => ['user_id' => $user, 'amount' => 250]]);
                })
                ->then(fn ($debit) => $this->http->getAsync('/balance/'.$user)->then(fn ($read) => [$this->decode($debit), $this->decode($read)]));
        }

        foreach ($this->concurrently($factories) as [$debit, $read]) {
            $this->assertSame(750, $debit['balance']);
            $this->assertSame(750, $read['balance']);
        }
    }

    /**
     * Rule 5: heavy traffic on one user must not slow down others. Hammer a hot
     * user with writes while other users are read and written concurrently;
     * bystanders must not queue behind the hot user's row lock.
     */
    public function test_hot_user_does_not_block_other_users(): void
    {
        $hot = $this->freshUser('hot');
        $this->http->post('/credit', ['json' => ['user_id' => $hot, 'amount' => 1_000_000]]);

        $bystanders = array_map(fn () => $this->freshUser('by'), range(1, 50));
        foreach ($bystanders as $user) {
            $this->http->post('/credit', ['json' => ['user_id' => $user, 'amount' => 1000]]);
        }

        $hotTimes = [];
        $byTimes = [];
        $timed = function (callable $factory, array &$sink) {
            return function () use ($factory, &$sink) {
                $start = hrtime(true);

                return $factory()->then(function ($response) use ($start, &$sink) {
                    $sink[] = (hrtime(true) - $start) / 1e9;

                    return $response;
                });
            };
        };

        $factories = [];
        for ($i = 0; $i < 1200; $i++) {
            if ($i % 4 === 3) {
                $user = $bystanders[$i % count($bystanders)];
                $factories[] = $i % 8 === 3
                    ? $timed(fn () => $this->http->getAsync('/balance/'.$user), $byTimes)
                    : $timed($this->op('/credit', $user, 1), $byTimes);
            } else {
                $factories[] = $timed($this->op($i % 2 ? '/credit' : '/debit', $hot, 1), $hotTimes);
            }
        }

        $responses = $this->concurrently($factories, 100);

        $this->assertSame([200 => 1200], $this->statusCounts($responses));

        $hotP50 = $this->percentile($hotTimes, 50);
        $byP50 = $this->percentile($byTimes, 50);
        $byP99 = $this->percentile($byTimes, 99);

        fwrite(STDERR, sprintf("\n  hot user p50 %.1f ms | bystander p50 %.1f ms, p99 %.1f ms\n", $hotP50 * 1000, $byP50 * 1000, $byP99 * 1000));

        // Bystanders are either fast in absolute terms or clearly faster than the hot user.
        $this->assertLessThan(max(0.25, $hotP50), $byP50, 'Bystander requests are queuing behind the hot user.');
        $this->assertReconciles($hot);
    }

    /** Optional extra: concurrent retries with the same Idempotency-Key move money once. */
    public function test_concurrent_duplicate_idempotency_keys_apply_once(): void
    {
        $user = $this->freshUser('idem');
        $key = 'idem-'.bin2hex(random_bytes(8));

        $responses = $this->concurrently(array_fill(0, 50, $this->op('/credit', $user, 300, ['Idempotency-Key' => $key])));

        $this->assertSame([200 => 50], $this->statusCounts($responses));
        foreach ($responses as $response) {
            $this->assertSame(['user_id' => $user, 'balance' => 300], $this->decode($response));
        }
        $this->assertSame(1, (int) $this->live()->selectOne('SELECT count(*) AS n FROM ledger_entries WHERE user_id = ?', [$user])->n);
        $this->assertReconciles($user, 300);
    }
}
