<?php

namespace Database\Factories;

use App\Enums\CertificateType;
use App\Enums\VerificationStatus;
use App\Models\CandidateCertificate;
use App\Models\CandidateProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateCertificate>
 */
class CandidateCertificateFactory extends Factory
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
            'certificate_type' => CertificateType::Ielts,
            'score' => '6.500',
            'status' => VerificationStatus::Pending,
        ];
    }
}
