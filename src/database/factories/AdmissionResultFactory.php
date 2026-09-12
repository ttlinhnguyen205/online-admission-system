<?php

namespace Database\Factories;

use App\Models\AdmissionResult;
use App\Models\AdmissionWish;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionResult>
 */
class AdmissionResultFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_wish_id' => AdmissionWish::factory(),
            'final_score' => '25.500',
        ];
    }
}
