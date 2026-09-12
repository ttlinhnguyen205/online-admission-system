<?php

namespace Database\Factories;

use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_code' => fake()->unique()->bothify('APP-############'),
            'candidate_profile_id' => CandidateProfile::factory(),
            'admission_round_id' => AdmissionRound::factory(),
        ];
    }
}
