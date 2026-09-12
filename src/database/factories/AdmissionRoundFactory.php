<?php

namespace Database\Factories;

use App\Models\AdmissionRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionRound>
 */
class AdmissionRoundFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('ROUND-########'),
            'name' => fake()->words(3, true),
            'year' => 2026,
            'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2026-12-31 23:59:59',
        ];
    }
}
