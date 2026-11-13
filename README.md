# Wallet Ledger API

A money ledger over HTTP with three operations: `credit`, `debit` and `get_balance`. It is built with **Laravel 12 on Octane + FrankenPHP** and uses **PostgreSQL 17**. The design target is a ledger of 10M+ rows serving about 100 concurrent clients, without ever producing a negative balance or a balance that disagrees with the ledger.

---

## 1. Run it

```bash
docker compose up --build          # API on http://localhost:8080, migrations run automatically
```

The token comes from `API_TOKEN`, which defaults to `recruitment-test-token`. To override it, run `API_TOKEN=... docker compose up`.

```bash
TOKEN=recruitment-test-token
curl -s -X POST localhost:8080/credit -H "Authorization: Bearer $TOKEN" -d '{"user_id":"u123","amount":10000}'
curl -s -X POST localhost:8080/debit  -H "Authorization: Bearer $TOKEN" -d '{"user_id":"u123","amount":4000}'
curl -s         localhost:8080/balance/u123 -H "Authorization: Bearer $TOKEN"
```

### Seed: 10M+ rows across 100k users

```bash
docker compose exec app php artisan ledger:seed --force              # ≈10.75M rows
docker compose exec app php artisan ledger:seed --force --scale=0.1  # ≈1.1M rows (for the 1M vs 10M comparison)
```

| Users | Rows each | Rows |
|---|---|---|
| 25 "whales" (`user_000000`–`user_000024`) | 150,000 | 3,750,000 |
| 975 medium | 2,000 | 1,950,000 |
| 99,000 regular | 2–100 | ≈5,049,000 |
| **100,000** | | **≈10.75M** |

The seed runs entirely inside PostgreSQL (`generate_series`), in a single transaction that starts with `TRUNCATE`. Indexes are dropped before the load and rebuilt after it. With `wal_level=minimal` this lets PostgreSQL skip WAL for the bulk insert. Wallets are then built by aggregating the ledger. Before committing, the seed checks every wallet against an independent closed-form expected balance.

**Seed time on my machine: 90 s for 10.75M rows** (69 s insert, 9 s index rebuild, 3 s wallets, 6 s commit, 1 s vacuum), and 10 s for 1.16M rows. The command prints the total time and a per-step breakdown.

The seed meets the brief's "no balance below 0 at any point" rule by construction. Each user's history is a series of (credit, debit) pairs. User *i* has a fixed positive net per pair, *n*, and the debit of pair *k* is `credit_k − n`. The balance after pair *k* is therefore `k·n`, which is always positive, and `balance_after` can be written without a running-sum pass. Amounts come from a hash, so the seed is deterministic.

### Tests

```bash
# In-process: API contract, auth, validation, idempotency (uses the wallet_test DB)
docker compose exec app vendor/bin/phpunit --testsuite Feature

# Real concurrent HTTP against the running server, then SQL reconciliation of the live DB
docker compose exec app vendor/bin/phpunit --testsuite Concurrency
```

The concurrency suite ([tests/Concurrency/ConcurrencyTest.php](tests/Concurrency/ConcurrencyTest.php)) covers every rule in the brief:

| Rule | Test |
|---|---|
| Non-negative balance | Two debits of 60 against 100 → exactly one `200` and one `409`, repeated 30×. 200 debits of 10 against 1,000 → exactly 100 succeed, balance 0, and the reported post-debit balances are exactly {0, 10, …, 990}. |
| No lost updates | 1,000 concurrent credits of 100 → +100,000 and exactly 1,000 new ledger rows. 100 concurrent *first* credits to a brand-new user. |
| Atomicity / consistency | 2,000 random credits and debits over 8 users. Each final balance equals the sum of the operations that returned `200`. The ledger reconciles row by row (`balance_after` = running sum, never negative). |
| Read-your-writes | 200 parallel chains of credit → GET → debit → GET. Each read equals the balance the write returned. |
| Isolation across users | A hot user takes 75% of 1,200 requests while bystanders are read and written concurrently. Bystander p50 must stay low, not queue behind the hot user. |
| No silent failures | Every response must be one of the expected statuses. Transport errors fail the test. |
| Idempotency (extra) | 50 concurrent requests with the same `Idempotency-Key` → one ledger row, 50 identical responses. |

### Load test

```bash
docker compose --profile loadtest run --rm --no-deps k6   # VUS=100 DURATION=60s by default
```

`--no-deps` stops Compose from re-creating the running `app` container when your shell's variables differ from the ones it was started with (for example `OCTANE_WORKERS`).

[loadtest/ledger.js](loadtest/ledger.js) runs in two phases:

1. **mixed**: 100 VUs doing 40% credit, 20% debit and 40% balance against random seeded users.
2. **hot**: 70 VUs hammer `user_000000` (150k ledger rows) while 30 *bystander* VUs keep running mixed traffic on other users.

It writes p50/p95/p99 per endpoint and phase to `loadtest/results/summary.md`. The run fails if any response is not an expected `200`/`409`.

### Reconcile, as the reviewers will

```bash
docker compose exec app php artisan ledger:reconcile          # wallet vs SUM(ledger), per user
docker compose exec app php artisan ledger:reconcile --deep   # also replays every user's history row by row
```

Or in plain SQL (`docker compose exec db psql -U wallet wallet`):

```sql
SELECT count(*) AS mismatches
FROM wallets w
FULL JOIN (SELECT user_id, sum(CASE WHEN type='credit' THEN amount ELSE -amount END) AS b
           FROM ledger_entries GROUP BY user_id) l USING (user_id)
WHERE w.balance IS DISTINCT FROM l.b;
```

---

## 2. Results

**Machine:** Windows 11 laptop, Docker Desktop (WSL2) with 8 vCPUs and 16 GB. k6, the API and PostgreSQL all share those 8 vCPUs. The load is 100 VUs in a closed loop with no think time, 60 s per phase. Raw output is in [loadtest/results/](loadtest/results/).

| p50 / p99 (ms) | 1.16M rows (`--scale=0.1`) | 10.75M rows |
|---|---|---|
| **credit** (mixed phase) | 97.1 / 239.7 | 82.5 / 197.5 |
| **debit** (mixed phase) | 92.9 / 236.7 | 81.4 / 199.1 |
| **get_balance** (mixed phase) | 91.6 / 244.2 | 76.3 / 192.6 |
| hot user credit, 70 VUs on one user | 563 / 4,042 | 381 / 2,942 |
| hot user debit | 433 / 3,161 | 311 / 2,329 |
| bystander credit during hot phase | 39.7 / 84.8 | 35.6 / 83.8 |
| bystander get_balance during hot phase | 29.2 / 73.7 | 27.7 / 71.0 |
| throughput (both phases) | 912 req/s | 1,049 req/s |
| unexpected responses | **0** | **0** |
| `ledger:reconcile --deep` afterwards | 0 mismatches | 0 mismatches |
| seed duration | 10 s | 90 s |

**How to read these numbers:**

- **Latency does not grow with table size.** The 10M run is as fast as the 1M run; the gap between them is run-to-run noise. Every hot-path query is a primary-key probe or an append (see §5).
- **The mixed-phase p50 is queueing, not query time.** One request on an idle system takes **1.85 ms** end to end (p99 3.75 ms, `get_balance`, 1 VU), and the three SQL statements execute in 0.1–0.8 ms. With 100 VUs and no think time, the machine saturates its 8 shared vCPUs at about 1,000–1,800 req/s. By Little's law, latency ≈ 100 / throughput ≈ 80–95 ms. On a faster machine the same code scales with the cores.
- **Hot user:** every write to one user serialises on one row lock, which is released only after the WAL `fdatasync`. On this disk `pg_test_fsync` measures **2.7 ms per fdatasync**, so one user is bounded at roughly 300 writes/s on an idle machine, and fewer under full CPU load. With 70 VUs queued on that single row, a p50 of about 300–550 ms is the expected queue length.
- **Isolation holds.** While 70 VUs hammer the hot user, bystanders keep a p50 of 28–40 ms, *lower* than in the mixed phase, because fewer VUs compete for CPU.

**Worker-count experiment** (20 s phases, 1.16M rows):

| `OCTANE_WORKERS` | mixed p50 | bystander p50 during hot phase |
|---|---|---|
| 32 | 77 ms | **175 ms**: the hot user's waiting requests hold every worker |
| 64 | 84 ms | 65 ms |
| **128** (default) | 85 ms | **30 ms** |

Fewer workers do not make the mixed phase faster, but they let a hot user starve everyone else. That is why the default is 128, above the number of concurrent clients.

---

## 3. Design

### Schema ([migration](database/migrations/2026_01_01_000001_create_ledger_schema.php))

```
ledger_entries                                   wallets  (fillfactor 80)
──────────────────────────────────────           ───────────────────────────────────
id            bigint identity  PK                user_id    varchar(64) "C"  PK
user_id       varchar(64) COLLATE "C"            balance    bigint  CHECK (balance >= 0)
type          enum('credit','debit')             created_at, updated_at
amount        bigint  CHECK (amount > 0)
balance_after bigint  CHECK (balance_after >= 0)
created_at    timestamptz default now()
INDEX (user_id, id)
TRIGGER: UPDATE/DELETE → exception (append-only)
```

- **`ledger_entries` is the source of truth.** It is append-only, and a trigger rejects `UPDATE` and `DELETE`.
- **`wallets` holds one row per user with the current balance.** The balance is a materialised `SUM` over that user's ledger rows. It is never computed from the ledger on the request path.
- **`balance_after`** records the user's balance right after each entry. It costs nothing to write, because it comes from the same statement that moves the wallet. It gives an audit trail that proves the balance never dipped below zero and that each wallet equals its last entry.
- **`COLLATE "C"`**: user ids are ASCII by contract, so byte-wise comparison is correct and cheaper than a locale-aware one.
- **`fillfactor = 80` on wallets** leaves free space on each page. Balance updates then become HOT updates that do not touch the primary-key index, which keeps index bloat flat under constant writes.
- **Amounts are `bigint` minor units.** No floats anywhere.

### How each operation works ([LedgerService](app/Ledger/LedgerService.php))

Each request runs **one SQL statement**, which costs one round trip and runs as its own implicit transaction.

**credit**: an upsert on the wallet plus a ledger insert, in one data-modifying CTE:

```sql
WITH wallet AS (
    INSERT INTO wallets AS w (user_id, balance) VALUES ($1, $2)
    ON CONFLICT (user_id) DO UPDATE SET balance = w.balance + EXCLUDED.balance, updated_at = now()
    RETURNING w.user_id, w.balance
), entry AS (
    INSERT INTO ledger_entries (user_id, type, amount, balance_after)
    SELECT user_id, 'credit', $3, balance FROM wallet
)
SELECT balance FROM wallet;
```

**debit**: a conditional update plus a ledger insert. The guard is part of the `UPDATE`:

```sql
WITH wallet AS (
    UPDATE wallets SET balance = balance - $1, updated_at = now()
     WHERE user_id = $2 AND balance >= $3
    RETURNING user_id, balance
), entry AS (
    INSERT INTO ledger_entries (user_id, type, amount, balance_after)
    SELECT user_id, 'debit', $4, balance FROM wallet
)
SELECT (SELECT balance FROM wallet)                         AS new_balance,     -- NULL → not applied
       (SELECT balance FROM wallets WHERE user_id = $5)     AS current_balance; -- NULL → 404, else 409
```

**get_balance**: `SELECT balance FROM wallets WHERE user_id = $1`. This is a single primary-key lookup, independent of table size and of the user's history length.

### Why this is safe under concurrency

| Requirement | Mechanism |
|---|---|
| **Non-negative balance** | The `balance >= amount` check and the subtraction happen in the same `UPDATE`, on a row PostgreSQL has locked. Under READ COMMITTED, a concurrent `UPDATE` that waited for the lock re-evaluates its `WHERE` against the newly committed row version (EvalPlanQual). With two debits of 60 against 100, the second one sees 40, matches nothing, and becomes a 409. `CHECK (balance >= 0)` is a second line of defence. |
| **No lost updates** | `balance = balance + x` is computed from the locked, latest row version, not from a value the application read earlier. Concurrent first credits converge through `INSERT … ON CONFLICT DO UPDATE`, which is atomic. |
| **Atomicity** | The wallet change and the ledger row are in one statement, so both happen or neither does. A crash at any point leaves both unchanged. |
| **Balance = ledger** | Both rows are written by the same statement, and the ledger row's `balance_after` is the wallet's `RETURNING` value. They cannot drift apart. |
| **Read-your-writes** | There is a single primary and synchronous commit, and the response is sent only after `COMMIT`. |
| **Isolation across users** | Each statement locks exactly **one** wallet row. Different users never contend. See "Hot users" below for the worker-pool side of this. |
| **No silent failures** | A statement locks at most one row, so deadlocks cannot happen by construction. SQLSTATE `40P01`/`40001` are still retried up to 5 times with jittered backoff. Anything else is a logged `500`; it is never swallowed. |

**Isolation level: READ COMMITTED** (the PostgreSQL default), deliberately. Correctness comes from row locks plus a guard written into the `UPDATE`, not from snapshot isolation. SERIALIZABLE would add predicate-lock overhead and serialization failures that need retries, without buying any extra safety for single-row operations.

### Hot users

All writes for one user serialise on that user's wallet row. That is the price of an exact, synchronously maintained balance. A hot user's throughput is therefore bounded by the row-lock hold time, which is roughly one commit (one WAL flush).

The real risk to *other* users is the worker pool. If 100 requests for the hot user occupy every PHP worker while they wait on the row lock, bystanders queue for a worker even though the database is idle for them. To prevent that:

- each worker holds the lock for exactly one statement and commit, with no application round trips while it holds the lock;
- the API runs **128 FrankenPHP workers**, above the ~100 concurrent clients. Waiting hot-user requests can never exhaust the pool, so bystanders always get a worker and a database connection immediately. This is configurable with `OCTANE_WORKERS`, and Postgres `max_connections=300`.

### Authentication ([middleware](app/Http/Middleware/AuthenticateBearerToken.php))

- It is **global middleware** that runs before routing, so every URL, including unknown ones and `/health`, needs the token. A rejected request performs zero queries, and a test asserts this.
- The token is read from `API_TOKEN` only. It is never in source, never logged (`APP_DEBUG=false`; Caddy redacts the `Authorization` header in its logs), and never returned. An empty `API_TOKEN` fails closed.
- **Constant-time comparison:** both values are hashed with SHA-256 and compared with `hash_equals`, so the timing leaks neither content nor length.
- The scheme name `Bearer` is matched case-insensitively, as RFC 9110 specifies. The token is compared exactly.

### Input validation ([LedgerRequest](app/Http/LedgerRequest.php))

Validation is hand-written instead of using Laravel's validator, because the contract is about **JSON types**. `"100"`, `100.0`, `1e2`, `true`, `-0` and integers beyond int64 must all be rejected, and the `integer` rule accepts some of them. `user_id` is checked with `/^[A-Za-z0-9_-]{1,64}$/D`; the `D` flag stops a trailing `\n` from slipping through. Unknown fields are ignored.

### Runtime

- **Octane + FrankenPHP worker mode:** Laravel boots once per worker instead of once per request.
- **Trimmed Octane reset listeners** ([config/octane.php](config/octane.php)): only the 13 listeners for services this API actually uses run on each request (config, DB, log, router, kernel). Octane's default is about 40. This raised `get_balance` throughput from about 1,075 to about 1,280 req/s under 100 VUs.
- **One query, one round trip:** `PDO::PGSQL_ATTR_DISABLE_PREPARES` makes pdo_pgsql use `PQexecParams`. Parameters are still bound server-side, but the separate Parse/Describe round trip of a named prepared statement is gone.
- The global middleware stack is cut down to one middleware. There are no sessions, cookies, CSRF or views.

### Optional extras

- **`Idempotency-Key`** on `credit` and `debit`. The key is claimed with `INSERT … ON CONFLICT DO NOTHING` in the *same transaction* as the money movement, and the outcome is stored before commit, including a `404` or `409`.
  - A retry or a concurrent duplicate gets the original response, with an `Idempotent-Replayed: true` header.
  - Reusing a key for a different request gives `422 idempotency_key_reused`.
  - If the original request rolled back, the key is free again.
- **`GET /health`** (authenticated) checks the database.
- **Graceful shutdown:** `init: true` and `exec` deliver SIGTERM to Octane, which tells FrankenPHP to stop accepting connections and drain in-flight requests (`stop_grace_period: 30s`).

---

## 4. Alternatives considered and rejected

| Option | Why not |
|---|---|
| **Compute the balance as `SUM(amount)` over the ledger** | O(history) per read. A whale has 150k rows, so it fails "must not scan a user's whole history". |
| **Balance from the latest `balance_after` only, with no wallets table** | Reads are O(log n) through `(user_id, id DESC)`. But writes need a lock to serialise per user, and there is no row to lock for the first credit (gap locking needs advisory locks). The wallet row is a simpler and faster lock target. |
| **Periodic snapshots plus the delta since the snapshot** | Reads still scan a variable tail, and there are more moving parts. Useful for statements, not needed for balances. |
| **`SELECT … FOR UPDATE`, then compute in PHP, then `UPDATE` + `INSERT` in a transaction** | Correct, but 4–5 round trips while holding the lock. The lock hold time is what limits hot-user throughput. |
| **SERIALIZABLE / optimistic version column with retries** | Under contention (the hot-user test) most attempts fail and retry, so latency collapses exactly where it is measured. Pessimistic row locks queue fairly instead. |
| **Advisory locks per user** | Same serialisation as the row lock, but it is a second mechanism to keep in sync, and it is easy to leak with pooled connections. |
| **Application-level locks (Redis/mutex)** | Another system that can fail, and it does not protect against writers that bypass the API. The database row lock is authoritative. |
| **`synchronous_commit = off`** | It would roughly 5–10× hot-user throughput by removing the flush from the lock hold time, and the data would stay consistent after a crash. But an *acknowledged* credit or debit could be lost on a DB crash. For money, durability wins. |
| **PHP-FPM + nginx** | Laravel's bootstrap on every request costs several milliseconds, which dominates a ~1 ms query. |
| **SQLite / MySQL** | SQLite is not allowed by the brief. MySQL would work, but PostgreSQL's data-modifying CTEs let each operation be a single statement. |

---

## 5. Query plans

Regenerate the plans against the seeded database (writes are rolled back):

```bash
docker compose exec app php artisan ledger:explain               # user_000000, a 150k-row whale
docker compose exec app php artisan ledger:explain user_054321   # a small user
```

Real `EXPLAIN (ANALYZE, BUFFERS)` output on the **10.75M-row** database, for `user_000000` (the user with the longest history, 150k ledger rows). Also saved in [loadtest/results/explain-10M.txt](loadtest/results/explain-10M.txt).

**get_balance**: one primary-key probe, 4 buffers, 0.08 ms:
```
Index Scan using wallets_pkey on wallets  (cost=0.42..2.64 rows=1 width=8) (actual time=0.042..0.043 rows=1 loops=1)
  Index Cond: ((user_id)::text = 'user_000000'::text)
  Buffers: shared hit=4
Planning:
  Buffers: shared hit=67
Planning Time: 0.629 ms
Execution Time: 0.082 ms
```

**credit**: an upsert through the primary key plus a single-row append to the ledger, 0.8 ms:
```
CTE Scan on wallet  (cost=0.04..0.06 rows=1 width=8) (actual time=0.176..0.178 rows=1 loops=1)
  Buffers: shared hit=12
  CTE wallet
    ->  Insert on wallets w  (cost=0.00..0.01 rows=1 width=170) (actual time=0.173..0.175 rows=1 loops=1)
          Conflict Resolution: UPDATE
          Conflict Arbiter Indexes: wallets_pkey
          Tuples Inserted: 0
          Conflicting Tuples: 1
          Buffers: shared hit=12
          ->  Result  (cost=0.00..0.01 rows=1 width=170) (actual time=0.002..0.002 rows=1 loops=1)
  CTE entry
    ->  Insert on ledger_entries  (cost=0.00..0.03 rows=0 width=0) (actual time=0.513..0.513 rows=0 loops=1)
          Buffers: shared hit=56
          ->  CTE Scan on wallet wallet_1  (cost=0.00..0.03 rows=1 width=182) (actual time=0.138..0.139 rows=1 loops=1)
                Buffers: shared hit=10
Planning Time: 0.076 ms
Execution Time: 0.842 ms
```

**debit**: a guarded update through the primary key plus a single-row append, 0.2 ms:
```
Result  (cost=5.32..5.33 rows=1 width=16) (actual time=0.069..0.070 rows=1 loops=1)
  Buffers: shared hit=10
  CTE wallet
    ->  Update on wallets  (cost=0.42..2.64 rows=1 width=22) (actual time=0.054..0.055 rows=1 loops=1)
          Buffers: shared hit=6
          ->  Index Scan using wallets_pkey on wallets  (cost=0.42..2.64 rows=1 width=22) (actual time=0.022..0.023 rows=1 loops=1)
                Index Cond: ((user_id)::text = 'user_000000'::text)
                Filter: (balance >= '1'::bigint)
                Buffers: shared hit=4
  CTE entry
    ->  Insert on ledger_entries  (cost=0.00..0.03 rows=0 width=0) (actual time=0.033..0.033 rows=0 loops=1)
          Buffers: shared hit=7
          ->  CTE Scan on wallet  (cost=0.00..0.03 rows=1 width=182) (actual time=0.005..0.006 rows=1 loops=1)
                Buffers: shared hit=1
  InitPlan 3
    ->  CTE Scan on wallet wallet_1  (cost=0.00..0.02 rows=1 width=8) (actual time=0.056..0.057 rows=1 loops=1)
          Buffers: shared hit=6
  InitPlan 4
    ->  Index Scan using wallets_pkey on wallets wallets_1  (cost=0.42..2.64 rows=1 width=8) (actual time=0.010..0.010 rows=1 loops=1)
          Index Cond: ((user_id)::text = 'user_000000'::text)
          Buffers: shared hit=4
Planning:
  Buffers: shared hit=8
Planning Time: 0.202 ms
Execution Time: 0.195 ms
```

None of the three plans touches more than one wallet row or reads any ledger row. The only cost that grows with table size is the B-tree depth of the inserts into `ledger_entries_pkey` and `(user_id, id)`, which is logarithmic. That is why the measured latency at 1M and 10M rows is the same.

---

## 6. Limits: what breaks first

**At 10× the load (~1,000 concurrent clients):**
1. **Hot-user throughput** breaks first. One row lock means one commit at a time per user, roughly 1–3k ops/s per user, bounded by fsync latency. Requests for a hot user queue, and their p99 grows linearly with the queue length.
2. **Workers and connections.** 1,000 workers means 1,000 Postgres backends, which is far past what one Postgres handles well. The fix is PgBouncer in transaction mode in front of Postgres. Each request is a single statement, so it is pooler-friendly; the one exception is the idempotency path, which uses a short transaction and still works in transaction mode.
3. **Single-primary write throughput / WAL volume**, eventually. The `(user_id, id)` index adds about 30% to every insert. It could be dropped if per-user history queries are not needed.

**Running several API instances:** nothing changes for correctness. All coordination happens in PostgreSQL (row locks, constraints, unique keys), and the API keeps no state. What changes is the connection budget (instances × workers), which again calls for PgBouncer, and the need for a load balancer. Read-your-writes holds as long as reads go to the primary. If `get_balance` moved to replicas, it would need session stickiness or LSN-based waits.

**Other known limits:**
- Laravel automatically re-runs a statement when the connection drops *outside* a transaction. If the drop happens after `COMMIT` but before the reply, the operation is applied twice. The ledger and wallet stay consistent with each other, but the client's intent is duplicated. `Idempotency-Key` is the fix, and clients should send it.
- The `idempotency_keys` table has no retention job yet (there is an index on `created_at` for one).
- A balance would overflow at about 9.2 × 10¹⁸ minor units.

## 7. With more time

- **Hot-user sharding:** split a hot user's wallet into N sub-rows. A credit picks any sub-row, so credits stop contending. A debit locks the sub-rows in a fixed order, or uses a rebalancing scheme. `get_balance` sums N rows.
- **PgBouncer** plus a smaller, tuned worker pool, and measure the trade-off.
- **Partition `ledger_entries`** by time range or by hash of `user_id` for maintenance (vacuum, archival) at 100M+ rows.
- A retention job for idempotency keys; per-user history and statement endpoints using `(user_id, id)`; Prometheus metrics.
- Run the load test in CI against a fixed-size machine so regressions show up.

---

## Project layout

```
app/Http/Middleware/AuthenticateBearerToken.php   auth
app/Http/LedgerRequest.php                        strict input parsing
app/Http/Controllers/LedgerController.php         HTTP ⇄ service mapping
app/Ledger/LedgerService.php                      the SQL and concurrency logic
app/Console/Commands/                             ledger:seed, ledger:reconcile, ledger:explain
database/migrations/                              schema, indexes, constraints, trigger
tests/Feature/                                    contract, auth, validation, idempotency
tests/Concurrency/                                live concurrent HTTP tests
loadtest/ledger.js                                k6 load test
docker-compose.yml, Dockerfile, docker/           one-command setup
```
