<?php

namespace Database\Factories;

use App\Models\CandidateExamResult;
use App\Models\CandidateExamSubjectScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateExamSubjectScore>
 */
class CandidateExamSubjectScoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_exam_result_id' => CandidateExamResult::factory(),
            'subject_code' => 'MATH',
            'subject_name' => 'Toán',
            'score' => '8.250',
        ];
    }
}
