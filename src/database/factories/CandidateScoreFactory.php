<?php

namespace Database\Factories;

use App\Models\CandidateProfile;
use App\Models\CandidateScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateScore>
 */
class CandidateScoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_profile_id' => CandidateProfile::factory(),
            'score_type' => 'thpt',
            'subject_code' => 'MATH',
            'subject_name' => 'Mathematics',
            'score' => '8.250',
            'exam_year' => 2026,
        ];
    }
}
