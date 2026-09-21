<?php

namespace App\Models;

use App\Enums\ProfileStatus;
use Database\Factories\CandidateProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'candidate_code',
    'date_of_birth',
    'gender',
    'ethnicity',
    'religion',
    'citizen_id',
    'citizen_id_issued_date',
    'citizen_id_issued_place',
    'phone',
    'address',
    'province_code',
    'high_school_code',
    'high_school_name',
    'graduation_year',
    'priority_area',
    'priority_object',
    'photo_path',
    'profile_status',

    // Thêm 5 field mới ở đây
    'citizen_id_front_path',
    'citizen_id_back_path',
    'verified_by',
    'verified_at',
    'verification_note',
])]
class CandidateProfile extends Model
{
    /** @use HasFactory<CandidateProfileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'citizen_id_issued_date' => 'date',
            'graduation_year' => 'integer',
            'profile_status' => ProfileStatus::class,
            'verified_at' => 'datetime',
        ];

    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /** @return HasMany<CandidateScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(CandidateScore::class);
    }

    /** @return HasMany<CandidateExamResult, $this> */
    public function examResults(): HasMany
    {
        return $this->hasMany(CandidateExamResult::class);
    }

    /** @return HasMany<CandidateTranscript, $this> */
    public function transcripts(): HasMany
    {
        return $this->hasMany(CandidateTranscript::class);
    }

    /** @return HasMany<CandidateCertificate, $this> */
    public function certificates(): HasMany
    {
        return $this->hasMany(CandidateCertificate::class);
    }

    /** @return HasMany<CandidateAdmissionClaim, $this> */
    public function admissionClaims(): HasMany
    {
        return $this->hasMany(CandidateAdmissionClaim::class);
    }
}
