<?php

namespace App\Models;

use Database\Factories\MajorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'default_tuition_fee', 'is_active'])]
class Major extends Model
{
    /** @use HasFactory<MajorFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_tuition_fee' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<AdmissionProgram, $this> */
    public function programs(): HasMany
    {
        return $this->hasMany(AdmissionProgram::class);
    }

    /** @return HasMany<CandidateMajorOffering, $this> */
    public function candidateMajorOfferings(): HasMany
    {
        return $this->hasMany(CandidateMajorOffering::class);
    }
}
