<?php

namespace App\Models;

use App\Enums\ExamType;
use App\Enums\VerificationStatus;
use Database\Factories\CandidateExamResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['candidate_profile_id', 'exam_type', 'exam_year', 'exam_date', 'exam_session', 'registration_number', 'overall_score', 'evidence_path', 'status', 'verified_by', 'verified_at', 'rejection_reason'])]
class CandidateExamResult extends Model
{
    /** @use HasFactory<CandidateExamResultFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['exam_type' => ExamType::class, 'exam_year' => 'integer', 'exam_date' => 'date',
            'overall_score' => 'decimal:0', 'status' => VerificationStatus::class, 'verified_at' => 'datetime'];
    }

    /** @return BelongsTo<CandidateProfile, $this> */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }

    /** @return HasMany<CandidateExamSubjectScore, $this> */
    public function subjectScores(): HasMany
    {
        return $this->hasMany(CandidateExamSubjectScore::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
