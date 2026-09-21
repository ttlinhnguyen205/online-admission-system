<?php

namespace Database\Factories;

use App\Enums\ExamType;
use App\Enums\VerificationStatus;
use App\Models\CandidateExamResult;
use App\Models\CandidateProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateExamResult>
 */
class CandidateExamResultFactory extends Factory
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
            'exam_type' => ExamType::Dgnl,
            'exam_year' => 2026,
            'overall_score' => '850.000',
            'status' => VerificationStatus::Pending,
        ];
    }
}
