<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $matching_pool_id
 * @property int $amount_cents
 * @property int $matched_cents
 */
class Donation extends Model
{
    protected $fillable = ['matching_pool_id', 'amount_cents', 'matched_cents'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'matched_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<MatchingPool, $this> */
    public function matchingPool(): BelongsTo
    {
        return $this->belongsTo(MatchingPool::class);
    }
}
