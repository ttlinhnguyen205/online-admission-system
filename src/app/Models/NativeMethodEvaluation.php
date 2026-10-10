<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['wish_method_binding_id', 'evaluation_rule_version_id', 'algorithm_version', 'input_fingerprint', 'status', 'score', 'provenance', 'reasons', 'evaluated_by', 'evaluated_at'])]
class NativeMethodEvaluation extends Model
{
    protected function casts(): array
    {
        return ['score' => 'decimal:3', 'provenance' => 'array', 'reasons' => 'array', 'evaluated_at' => 'datetime'];
    }
}
