<?php

namespace App\Models;

use Database\Factories\AdmissionMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'score_config', 'is_active'])]
class AdmissionMethod extends Model
{
    /** @use HasFactory<AdmissionMethodFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score_config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<AdmissionProgram, $this> */
    public function programs(): HasMany
    {
        return $this->hasMany(AdmissionProgram::class);
    }
}
