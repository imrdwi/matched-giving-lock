# Matched giving with a MySQL named lock

A sponsor offers to match donations up to a cap, say $100. Twenty donors give $10 at the same moment. Without coordination, every request reads "$100 left", matches its $10, and the sponsor ends up owing $200.

This repository reproduces that race in a small Laravel API, fixes it with a MySQL named lock, and proves the fix with a test that fires real concurrent requests. It is a from-scratch rebuild of a pattern I used in production on a fundraising platform ([case study](https://rizkik.me/work/funraisin)). No client code is included.

## The race

```php
$matched = min($amount, $pool->remainingCents()); // read: SUM of matched donations
// ... payment gateway call, other work ...
$pool->donations()->create([...]);                // write
```

A database transaction around this does not help. Two transactions can both read the same sum before either inserts, and neither conflicts with the other, because they insert different rows. The remaining balance is derived from many rows, so there is no single row to lock either.

## The fix

[`NamedLock`](app/Support/NamedLock.php) wraps `GET_LOCK` / `RELEASE_LOCK`, and [`LockedDonationRecorder`](app/Donations/LockedDonationRecorder.php) takes one lock per pool (`matching_pool:{id}`) around the read and the write. Donations to the same pool run one at a time. Donations to different pools still run in parallel.

Two ordering rules matter, and the code enforces the first:

1. **Lock before the transaction starts.** Under MySQL's default REPEATABLE READ isolation, a transaction reads from a snapshot taken at its first read. If the transaction had already read before acquiring the lock, it would keep seeing the balance from before the previous holder committed. `NamedLock` throws if it is called inside a transaction.
2. **Release after commit.** Releasing first would let the next request read the balance before this donation is visible.

If the lock cannot be acquired within the timeout, the API returns `409` with `Retry-After: 1` and records nothing, so the client can safely retry.

If `RELEASE_LOCK` itself fails, the error is reported, not thrown. Thrown from the `finally` block, it would hide the callback's own exception, or, after a successful commit, tell the client a recorded donation failed and invite a duplicate retry. MySQL frees the lock when the session ends either way.

## The proof

[`ConcurrentDonationsTest`](tests/Feature/ConcurrentDonationsTest.php) starts 20 separate PHP processes. Each has its own MySQL session, and all of them wait for the same start time before donating $10 to a $100 pool. A 50 ms pause between the read and the write stands in for the gateway call, so the race shows up on every run instead of occasionally.

| Test | Asserts |
|---|---|
| With the lock | Exactly $100 matched: 10 donations matched in full, 10 matched $0 |
| Without the lock (control) | More than $100 matched, so the test can detect the race at all |
| Lock held elsewhere | The donation fails with a lock timeout and nothing is written |
| Another pool locked | Donations to a different pool are not blocked |

The control test matters most. A concurrency test that passes against the broken code proves nothing.

```
PASS  Tests\Feature\ConcurrentDonationsTest
✓ concurrent donations never match more than the cap
✓ without the lock the same donations overrun the cap
✓ a donation that cannot get the lock fails without being recorded
✓ a locked pool does not block donations to another pool

PASS  Tests\Feature\DonationApiTest       (5 tests: full, partial and zero matches, validation, 409)
PASS  Tests\Feature\NamedLockTest         (5 tests: refuses inside a transaction, releases on exception,
                                           a failed release never hides or replaces the result)

Tests: 14 passed
```

The tests use `DatabaseTruncation`, not `RefreshDatabase`. Child processes only see committed rows, and `RefreshDatabase` wraps each test in a transaction that is never committed. It would also trip the lock-before-transaction rule.

## Run it

You need Docker. Nothing else has to be installed.

```sh
cp .env.example .env
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan test
```

Static analysis (PHPStan level 8 via Larastan) and code style (Pint):

```sh
docker compose run --rm --no-deps app vendor/bin/phpstan analyse --memory-limit=512M
docker compose run --rm --no-deps app vendor/bin/pint --test
```

To try the API:

```sh
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan tinker --execute="App\Models\MatchingPool::create(['name' => 'Demo', 'cap_cents' => 10000]);"
docker compose run --rm --service-ports app php artisan serve --host=0.0.0.0
curl -X POST localhost:8000/api/matching-pools/1/donations -H 'Accept: application/json' -d amount_cents=2500
```

CI runs the same checks against MySQL 8.4 on every push ([workflow](.github/workflows/tests.yml)).

## Trade-offs and limits

- **Why not `SELECT ... FOR UPDATE` on the pool row?** That also works, and it is the better choice when every code path that changes the balance goes through the pool row. A named lock fits when several code paths touch the balance (one-off gifts, recurring gifts, imports, refunds), because each one only has to agree on the lock name, not on a row to lock first.
- **A running total column** on the pool, updated with a conditional `UPDATE`, would avoid the lock for the simple case by turning the cap into a single-row write. The cost is that the total can drift from the donations it summarizes if any code path forgets to update it.
- **Named locks are per MySQL server.** They do not coordinate across a multi-primary cluster, and a lock taken on a read replica protects nothing. Behind a proxy or connection pooler, the lock and the queries must stay on the same session.
- **The lock serializes per pool.** That suits matched giving, where one pool sees bursts of donations, not sustained thousands per second. At higher volume I would move to the running-total approach.
- **Not covered here:** idempotency for retried requests (a client that retries after a timeout could donate twice), and keeping the payment gateway call outside the lock so a slow gateway does not hold the pool.
