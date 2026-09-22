<?php

namespace App\Models;

use Database\Factories\CandidateExamSubjectScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['candidate_exam_result_id', 'subject_code', 'subject_name', 'score'])]
class CandidateExamSubjectScore extends Model
{
    /** @use HasFactory<CandidateExamSubjectScoreFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['score' => 'decimal:3'];
    }

    /** @return BelongsTo<CandidateExamResult, $this> */
    public function examResult(): BelongsTo
    {
        return $this->belongsTo(CandidateExamResult::class, 'candidate_exam_result_id');
    }
}
