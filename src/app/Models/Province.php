<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'name',
])]
class Province extends Model
{
    /** @return HasMany<HighSchool, $this> */
    public function highSchools(): HasMany
    {
        return $this->hasMany(HighSchool::class);
    }
}
