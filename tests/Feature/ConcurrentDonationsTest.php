<?php

namespace Tests\Feature;

use App\Console\Commands\AttemptDonation;
use App\Models\MatchingPool;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real concurrency: each donation runs in its own PHP process with its own
 * MySQL session, and every process waits for the same start time.
 *
 * DatabaseTruncation instead of RefreshDatabase: the child processes can only
 * see committed rows, and RefreshDatabase never commits.
 */
class ConcurrentDonationsTest extends TestCase
{
    use DatabaseTruncation;

    private const ATTEMPTS = 20;

    private const AMOUNT_CENTS = 1_000;

    private const CAP_CENTS = 10_000;

    /** Long enough that every unlocked process reads the balance before any writes. */
    private const RACE_WINDOW_MS = 50;

    public function test_concurrent_donations_never_match_more_than_the_cap(): void
    {
        $pool = MatchingPool::factory()->create(['cap_cents' => self::CAP_CENTS]);

        // A generous lock wait: this test is about the cap, not about timeouts,
        // and twenty serialized donations can queue for a while on a slow CI box.
        $results = $this->donateConcurrently($pool, self::ATTEMPTS, ['--lock-timeout=30']);

        $this->assertSame(array_fill(0, self::ATTEMPTS, 0), $results->pluck('exit')->all(), $results->pluck('output')->implode("\n"));
        $this->assertSame(self::CAP_CENTS, $pool->matchedCents());
        // Ten donations fill the pool exactly; the other ten arrive after it is spent.
        $this->assertSame(10, $pool->donations()->where('matched_cents', self::AMOUNT_CENTS)->count());
        $this->assertSame(10, $pool->donations()->where('matched_cents', 0)->count());
    }

    /**
     * The control: the same load without the lock. If this ever stops
     * overrunning, the test above has stopped proving anything.
     */
    public function test_without_the_lock_the_same_donations_overrun_the_cap(): void
    {
        $pool = MatchingPool::factory()->create(['cap_cents' => self::CAP_CENTS]);

        $this->donateConcurrently($pool, self::ATTEMPTS, ['--unlocked']);

        $this->assertGreaterThan(self::CAP_CENTS, $pool->matchedCents());
    }

    public function test_a_donation_that_cannot_get_the_lock_fails_without_being_recorded(): void
    {
        $pool = MatchingPool::factory()->create();
        $this->holdLock("matching_pool:{$pool->id}");

        $result = $this->donateConcurrently($pool, 1, ['--lock-timeout=1'])->sole();

        $this->assertSame(AttemptDonation::LOCK_TIMEOUT, $result['exit']);
        $this->assertSame(0, $pool->donations()->count());
    }

    public function test_a_locked_pool_does_not_block_donations_to_another_pool(): void
    {
        $busy = MatchingPool::factory()->create();
        $other = MatchingPool::factory()->create();
        $this->holdLock("matching_pool:{$busy->id}");

        $result = $this->donateConcurrently($other, 1, ['--lock-timeout=1'])->sole();

        $this->assertSame(0, $result['exit']);
        $this->assertSame(self::AMOUNT_CENTS, $other->matchedCents());
    }

    /**
     * @param  list<string>  $options
     * @return Collection<int, array{exit: int|null, output: string}>
     */
    private function donateConcurrently(MatchingPool $pool, int $attempts, array $options = []): Collection
    {
        // Far enough ahead that every process has booted before it arrives.
        $startAt = (int) ((microtime(true) + 3) * 1_000_000);

        $processes = collect(range(1, $attempts))->map(fn () => new Process(
            [
                PHP_BINARY, 'artisan', 'donations:attempt', (string) $pool->id, (string) self::AMOUNT_CENTS,
                '--race-window-ms='.self::RACE_WINDOW_MS, "--start-at={$startAt}", ...$options,
            ],
            base_path(),
            $this->databaseEnvironment(),
            timeout: 60,
        ));

        $processes->each->start();

        return $processes->map(function (Process $process) {
            $process->wait();

            return ['exit' => $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput()];
        });
    }

    /**
     * Point the child processes at the database this test is using.
     *
     * @return array<string, string>
     */
    private function databaseEnvironment(): array
    {
        $connection = config('database.connections.mysql');

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'],
        ];
    }

    /** Take the pool's lock in this test's own session, as another request would. */
    private function holdLock(string $name): void
    {
        $this->assertSame(1, (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired);
        $this->beforeApplicationDestroyed(fn () => DB::select('SELECT RELEASE_LOCK(?)', [$name]));
    }
}
