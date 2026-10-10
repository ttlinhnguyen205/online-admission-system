<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['application_code', 'candidate_profile_id', 'admission_round_id', 'status', 'submitted_at', 'reviewed_by', 'reviewed_at', 'revision_reason', 'registration_mode'])]
class Application extends Model
{
    protected static function booted(): void
    {
        static::updating(function (self $application): void {
            if ($application->isDirty('registration_mode')) {
                throw ValidationException::withMessages(['submission' => 'Không tự chuyển chế độ hồ sơ legacy/native.']);
            }
        });
    }

    /** @return HasMany<NativeAdmissionWish, $this> */
    public function nativeWishes(): HasMany
    {
        return $this->hasMany(NativeAdmissionWish::class);
    }

    /** @return HasMany<ApplicationSubmissionSnapshot, $this> */
    public function submissionSnapshots(): HasMany
    {
        return $this->hasMany(ApplicationSubmissionSnapshot::class);
    }

    /** @use HasFactory<ApplicationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CandidateProfile, $this> */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }

    /** @return BelongsTo<AdmissionRound, $this> */
    public function admissionRound(): BelongsTo
    {
        return $this->belongsTo(AdmissionRound::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<CandidateDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(CandidateDocument::class);
    }

    /** @return HasMany<AdmissionWish, $this> */
    public function wishes(): HasMany
    {
        return $this->hasMany(AdmissionWish::class);
    }
}
