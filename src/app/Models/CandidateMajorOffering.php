<?php

namespace App\Models;

use Database\Factories\CandidateMajorOfferingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['admission_round_id', 'major_id', 'is_selectable', 'admission_program_id'])]
class CandidateMajorOffering extends Model
{
    /** @use HasFactory<CandidateMajorOfferingFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_selectable' => 'boolean'];
    }

    /** @return BelongsTo<AdmissionRound, $this> */
    public function admissionRound(): BelongsTo
    {
        return $this->belongsTo(AdmissionRound::class);
    }

    /** @return BelongsTo<Major, $this> */
    public function major(): BelongsTo
    {
        return $this->belongsTo(Major::class);
    }

    /** @return BelongsTo<AdmissionProgram, $this> */
    public function admissionProgram(): BelongsTo
    {
        return $this->belongsTo(AdmissionProgram::class);
    }

    /** @return HasMany<AdmissionWish, $this> */
    public function wishes(): HasMany
    {
        return $this->hasMany(AdmissionWish::class);
    }
}
