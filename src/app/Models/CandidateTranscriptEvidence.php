<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['candidate_transcript_id', 'path', 'original_name', 'mime_type', 'size', 'sort_order'])]
class CandidateTranscriptEvidence extends Model
{
    protected $table = 'candidate_transcript_evidence';

    /** @return BelongsTo<CandidateTranscript, $this> */
    public function transcript(): BelongsTo
    {
        return $this->belongsTo(CandidateTranscript::class, 'candidate_transcript_id');
    }
}
