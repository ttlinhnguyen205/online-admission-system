<?php

namespace App\Models;

use App\Enums\AdmissionRoundStatus;
use Database\Factories\AdmissionRoundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'year', 'start_date', 'end_date', 'result_date', 'status'])]
class AdmissionRound extends Model
{
    /** @use HasFactory<AdmissionRoundFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'result_date' => 'datetime',
            'status' => AdmissionRoundStatus::class,
        ];
    }

    /** @return HasMany<AdmissionProgram, $this> */
    public function programs(): HasMany
    {
        return $this->hasMany(AdmissionProgram::class);
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
