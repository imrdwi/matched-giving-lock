<?php

namespace Tests\Feature;

use App\Donations\LockedDonationRecorder;
use App\Models\MatchingPool;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DonationApiTest extends TestCase
{
    // Not RefreshDatabase: it wraps each test in a transaction, and the named
    // lock refuses to be taken inside one.
    use DatabaseTruncation;

    public function test_a_donation_is_matched_in_full_while_the_pool_has_room(): void
    {
        $pool = MatchingPool::factory()->create(['cap_cents' => 10_000]);

        $this->postJson("/api/matching-pools/{$pool->id}/donations", ['amount_cents' => 2_500])
            ->assertCreated()
            ->assertJson(['amount_cents' => 2_500, 'matched_cents' => 2_500]);
    }

    public function test_a_donation_larger_than_the_remaining_balance_is_matched_only_up_to_the_cap(): void
    {
        $pool = MatchingPool::factory()->create(['cap_cents' => 10_000]);
        $pool->donations()->create(['amount_cents' => 8_000, 'matched_cents' => 8_000]);

        $this->postJson("/api/matching-pools/{$pool->id}/donations", ['amount_cents' => 5_000])
            ->assertCreated()
            ->assertJson(['amount_cents' => 5_000, 'matched_cents' => 2_000]);
    }

    public function test_a_donation_to_a_spent_pool_is_recorded_unmatched(): void
    {
        $pool = MatchingPool::factory()->create(['cap_cents' => 10_000]);
        $pool->donations()->create(['amount_cents' => 10_000, 'matched_cents' => 10_000]);

        $this->postJson("/api/matching-pools/{$pool->id}/donations", ['amount_cents' => 1_000])
            ->assertCreated()
            ->assertJson(['matched_cents' => 0]);
    }

    public function test_the_amount_must_be_a_positive_whole_number_of_cents(): void
    {
        $pool = MatchingPool::factory()->create();

        $this->postJson("/api/matching-pools/{$pool->id}/donations", ['amount_cents' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_cents');
    }

    public function test_a_busy_pool_returns_409_and_records_nothing(): void
    {
        $pool = MatchingPool::factory()->create();
        $this->app->when(LockedDonationRecorder::class)->needs('$lockTimeoutSeconds')->give(fn () => 0);

        // Another session holds the pool's lock, as a concurrent request would.
        config(['database.connections.other_request' => config('database.connections.mysql')]);
        $other = DB::connection('other_request');
        $other->select('SELECT GET_LOCK(?, 0)', ["matching_pool:{$pool->id}"]);

        try {
            $this->postJson("/api/matching-pools/{$pool->id}/donations", ['amount_cents' => 1_000])
                ->assertConflict()
                ->assertHeader('Retry-After', '1');
        } finally {
            $other->select('SELECT RELEASE_LOCK(?)', ["matching_pool:{$pool->id}"]);
        }

        $this->assertSame(0, $pool->donations()->count());
    }
}
