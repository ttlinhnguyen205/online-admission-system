<?php

namespace Database\Factories;

use App\Enums\VerificationStatus;
use App\Models\CandidateProfile;
use App\Models\CandidateTranscript;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateTranscript>
 */
class CandidateTranscriptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_profile_id' => CandidateProfile::factory(),
            'school_name' => 'Trường THPT',
            'graduation_year' => 2026,
            'status' => VerificationStatus::Pending,
        ];
    }
}
