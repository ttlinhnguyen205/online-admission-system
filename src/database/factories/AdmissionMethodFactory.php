<?php

namespace Database\Factories;

use App\Models\AdmissionMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionMethod>
 */
class AdmissionMethodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('METHOD-########'),
            'name' => fake()->words(3, true),
        ];
    }
}
