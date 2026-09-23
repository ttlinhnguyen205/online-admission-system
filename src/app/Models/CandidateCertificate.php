<?php

namespace App\Models;

use App\Enums\CertificateType;
use App\Enums\VerificationStatus;
use Database\Factories\CandidateCertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['candidate_profile_id', 'certificate_type', 'score', 'certificate_number', 'issued_at', 'expires_at', 'evidence_path', 'status', 'verified_by', 'verified_at', 'rejection_reason'])]
class CandidateCertificate extends Model
{
    /** @use HasFactory<CandidateCertificateFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['certificate_type' => CertificateType::class, 'score' => 'decimal:2', 'issued_at' => 'date',
            'expires_at' => 'date', 'status' => VerificationStatus::class, 'verified_at' => 'datetime'];
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
