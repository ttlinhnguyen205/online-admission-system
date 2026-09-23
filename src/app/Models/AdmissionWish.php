<?php

namespace App\Models;

use App\Enums\WishStatus;
use Database\Factories\AdmissionWishFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['application_id', 'admission_program_id', 'candidate_major_offering_id', 'priority', 'calculated_score', 'status'])]
class AdmissionWish extends Model
{
    /** @use HasFactory<AdmissionWishFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'calculated_score' => 'decimal:3',
            'status' => WishStatus::class,
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<AdmissionProgram, $this> */
    public function admissionProgram(): BelongsTo
    {
        return $this->belongsTo(AdmissionProgram::class);
    }

    /** @return BelongsTo<CandidateMajorOffering, $this> */
    public function candidateMajorOffering(): BelongsTo
    {
        return $this->belongsTo(CandidateMajorOffering::class);
    }

    /** @return HasOne<AdmissionResult, $this> */
    public function result(): HasOne
    {
        return $this->hasOne(AdmissionResult::class);
    }
}
