<?php

namespace App\Models;

use Database\Factories\CandidateTranscriptScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['candidate_transcript_id', 'subject_code', 'subject_name', 'grade_level', 'score'])]
class CandidateTranscriptScore extends Model
{
    /** @use HasFactory<CandidateTranscriptScoreFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['grade_level' => 'integer', 'score' => 'decimal:3'];
    }

    /** @return BelongsTo<CandidateTranscript, $this> */
    public function transcript(): BelongsTo
    {
        return $this->belongsTo(CandidateTranscript::class, 'candidate_transcript_id');
    }
}
