<?php

namespace App\Models;

use App\Enums\AdmissionDecision;
use Database\Factories\AdmissionResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['admission_wish_id', 'final_score', 'rank', 'decision', 'decided_at', 'published_at', 'confirmed_at'])]
class AdmissionResult extends Model
{
    /** @use HasFactory<AdmissionResultFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'final_score' => 'decimal:3',
            'rank' => 'integer',
            'decision' => AdmissionDecision::class,
            'decided_at' => 'datetime',
            'published_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AdmissionWish, $this> */
    public function admissionWish(): BelongsTo
    {
        return $this->belongsTo(AdmissionWish::class);
    }
}
