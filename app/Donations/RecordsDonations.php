<?php

namespace App\Donations;

use App\Models\Donation;
use App\Models\MatchingPool;

interface RecordsDonations
{
    /** Record a donation and match as much of it as the pool has left. */
    public function record(MatchingPool $pool, int $amountCents): Donation;
}
