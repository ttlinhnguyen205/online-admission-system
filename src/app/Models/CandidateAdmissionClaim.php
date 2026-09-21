<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use Database\Factories\CandidateAdmissionClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['candidate_profile_id', 'claim_type', 'claim_code', 'description', 'evidence_path', 'status', 'verified_by', 'verified_at', 'rejection_reason'])]
class CandidateAdmissionClaim extends Model
{
    /** @use HasFactory<CandidateAdmissionClaimFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => VerificationStatus::class, 'verified_at' => 'datetime'];
    }

    /** @return BelongsTo<CandidateProfile, $this> */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
