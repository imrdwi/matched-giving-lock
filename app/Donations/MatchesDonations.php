<?php

namespace App\Donations;

use App\Models\Donation;
use App\Models\MatchingPool;

/**
 * The read-then-write step both recorders share: read what the pool has
 * left, match up to that, write the donation.
 */
trait MatchesDonations
{
    /**
     * Milliseconds to pause between reading the balance and writing the
     * donation. Zero in normal use. The concurrency tests raise it to stand in
     * for the payment-gateway call and other work a real request does in that
     * gap, so a race shows up on every run instead of occasionally.
     */
    public int $raceWindowMs = 0;

    private function matchAndWrite(MatchingPool $pool, int $amountCents): Donation
    {
        $matchedCents = min($amountCents, $pool->remainingCents());

        if ($this->raceWindowMs > 0) {
            usleep($this->raceWindowMs * 1000);
        }

        return $pool->donations()->create([
            'amount_cents' => $amountCents,
            'matched_cents' => $matchedCents,
        ]);
    }
}
