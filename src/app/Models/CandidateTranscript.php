<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use Database\Factories\CandidateTranscriptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['candidate_profile_id', 'school_name', 'graduation_year', 'evidence_path', 'status', 'verified_by', 'verified_at', 'rejection_reason'])]
class CandidateTranscript extends Model
{
    /** @use HasFactory<CandidateTranscriptFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['graduation_year' => 'integer', 'status' => VerificationStatus::class, 'verified_at' => 'datetime'];
    }

    /** @return BelongsTo<CandidateProfile, $this> */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }

    /** @return HasMany<CandidateTranscriptScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(CandidateTranscriptScore::class);
    }

    /** @return HasMany<CandidateTranscriptEvidence, $this> */
    public function evidenceImages(): HasMany
    {
        return $this->hasMany(CandidateTranscriptEvidence::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
