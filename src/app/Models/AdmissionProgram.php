<?php

namespace App\Models;

use Database\Factories\AdmissionProgramFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['admission_round_id', 'major_id', 'admission_method_id', 'quota', 'minimum_score', 'previous_cutoff_score', 'tuition_fee', 'status'])]
class AdmissionProgram extends Model
{
    /** @use HasFactory<AdmissionProgramFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quota' => 'integer',
            'minimum_score' => 'decimal:3',
            'previous_cutoff_score' => 'decimal:3',
            'tuition_fee' => 'decimal:2',
        ];
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

    /** @return BelongsTo<AdmissionMethod, $this> */
    public function admissionMethod(): BelongsTo
    {
        return $this->belongsTo(AdmissionMethod::class);
    }

    /** @return HasMany<AdmissionWish, $this> */
    public function wishes(): HasMany
    {
        return $this->hasMany(AdmissionWish::class);
    }
}
