<?php

namespace App\Console\Commands;

use App\Donations\LockedDonationRecorder;
use App\Donations\UnlockedDonationRecorder;
use App\Models\MatchingPool;
use App\Support\LockTimeoutException;
use Illuminate\Console\Command;

/**
 * One donation attempt in its own PHP process, with its own database
 * session. The concurrency tests start many of these at once.
 */
class AttemptDonation extends Command
{
    public const LOCK_TIMEOUT = 3;

    protected $signature = 'donations:attempt
        {pool : Matching pool ID}
        {amount : Donation amount in cents}
        {--unlocked : Use the naive recorder, without the named lock}
        {--race-window-ms=0 : Pause between reading the balance and writing the donation}
        {--lock-timeout=5 : Seconds to wait for the pool lock}
        {--start-at= : Unix time in microseconds to wait for, so every process starts together}';

    protected $description = 'Record one donation against a matching pool and print the result as JSON';

    public function handle(): int
    {
        $pool = MatchingPool::findOrFail($this->argument('pool'));

        $recorder = $this->option('unlocked')
            ? app(UnlockedDonationRecorder::class)
            : app(LockedDonationRecorder::class, ['lockTimeoutSeconds' => (int) $this->option('lock-timeout')]);
        $recorder->raceWindowMs = (int) $this->option('race-window-ms');

        // The barrier: booting takes a different time in each process, so each
        // one waits for the same moment before touching the database.
        if ($startAt = (int) $this->option('start-at')) {
            usleep(max(0, $startAt - (int) (microtime(true) * 1_000_000)));
        }

        try {
            $donation = $recorder->record($pool, (int) $this->argument('amount'));
        } catch (LockTimeoutException) {
            $this->line(json_encode(['error' => 'lock_timeout'], JSON_THROW_ON_ERROR));

            return self::LOCK_TIMEOUT;
        }

        $this->line(json_encode(['id' => $donation->id, 'matched_cents' => $donation->matched_cents], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
