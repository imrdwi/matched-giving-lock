<?php

namespace Database\Factories;

use App\Models\MatchingPool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchingPool>
 */
class MatchingPoolFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' matching pool',
            'cap_cents' => 10_000,
        ];
    }
}
