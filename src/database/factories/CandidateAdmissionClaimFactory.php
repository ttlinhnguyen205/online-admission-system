<?php

namespace Database\Factories;

use App\Enums\VerificationStatus;
use App\Models\CandidateAdmissionClaim;
use App\Models\CandidateProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateAdmissionClaim>
 */
class CandidateAdmissionClaimFactory extends Factory
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
            'claim_type' => 'test_claim',
            'status' => VerificationStatus::Pending,
        ];
    }
}
