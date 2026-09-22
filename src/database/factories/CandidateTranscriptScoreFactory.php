<?php

namespace Database\Factories;

use App\Models\CandidateTranscript;
use App\Models\CandidateTranscriptScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateTranscriptScore>
 */
class CandidateTranscriptScoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_transcript_id' => CandidateTranscript::factory(),
            'subject_code' => 'MATH',
            'subject_name' => 'Toán',
            'grade_level' => '10',
            'score' => '8.25',
        ];
    }
}
