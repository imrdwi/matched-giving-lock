<?php

namespace App\Donations;

use App\Models\Donation;
use App\Models\MatchingPool;
use Illuminate\Database\ConnectionInterface;

/**
 * The naive version: a transaction and nothing else.
 *
 * A transaction does not stop two requests from reading the same remaining
 * balance before either one writes, so concurrent donations can match more
 * than the cap. It is kept so the concurrency test can show that it catches
 * this race. The application binds LockedDonationRecorder; nothing uses this.
 */
final class UnlockedDonationRecorder implements RecordsDonations
{
    use MatchesDonations;

    public function __construct(private readonly ConnectionInterface $connection) {}

    public function record(MatchingPool $pool, int $amountCents): Donation
    {
        return $this->connection->transaction(
            fn (): Donation => $this->matchAndWrite($pool, $amountCents),
        );
    }
}
