<?php

namespace App\Models;

use Database\Factories\MatchingPoolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sponsor's offer to match donations, up to a fixed cap.
 *
 * @property int $id
 * @property string $name
 * @property int $cap_cents
 */
class MatchingPool extends Model
{
    /** @use HasFactory<MatchingPoolFactory> */
    use HasFactory;

    protected $fillable = ['name', 'cap_cents'];

    protected function casts(): array
    {
        return ['cap_cents' => 'integer'];
    }

    /** @return HasMany<Donation, $this> */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    public function matchedCents(): int
    {
        return (int) $this->donations()->sum('matched_cents');
    }

    public function remainingCents(): int
    {
        return max(0, $this->cap_cents - $this->matchedCents());
    }
}
