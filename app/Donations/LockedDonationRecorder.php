<?php

namespace App\Donations;

use App\Models\Donation;
use App\Models\MatchingPool;
use App\Support\NamedLock;
use Illuminate\Database\ConnectionInterface;

/**
 * Serializes donations per pool with a named lock, so two requests can never
 * both read the same remaining balance and match it twice.
 *
 * The lock is per pool: donations to different pools still run in parallel.
 */
final class LockedDonationRecorder implements RecordsDonations
{
    use MatchesDonations;

    public function __construct(
        private readonly NamedLock $lock,
        private readonly ConnectionInterface $connection,
        private readonly int $lockTimeoutSeconds = 5,
    ) {}

    public function record(MatchingPool $pool, int $amountCents): Donation
    {
        return $this->lock->run(
            "matching_pool:{$pool->id}",
            $this->lockTimeoutSeconds,
            fn (): Donation => $this->connection->transaction(
                fn (): Donation => $this->matchAndWrite($pool, $amountCents),
            ),
        );
    }
}
