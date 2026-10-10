<?php

namespace App\Models;

use Database\Factories\EvaluationRuleVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['admission_method_id', 'template_identifier', 'template_version', 'version', 'payload', 'ranking_contract', 'ranking_contract_version', 'status', 'approved_by', 'approved_at', 'content_hash'])]
class EvaluationRuleVersion extends Model
{
    /** @use HasFactory<EvaluationRuleVersionFactory> */
    use HasFactory;

    /** @return array{payload: 'array', approved_at: 'datetime', admission_method_id: 'integer', template_version: 'integer', version: 'integer', ranking_contract_version: 'integer'} */
    protected function casts(): array
    {
        return ['payload' => 'array', 'approved_at' => 'datetime', 'admission_method_id' => 'integer', 'template_version' => 'integer', 'version' => 'integer', 'ranking_contract_version' => 'integer'];
    }

    /** @return BelongsTo<AdmissionMethod, $this> */
    public function admissionMethod(): BelongsTo
    {
        return $this->belongsTo(AdmissionMethod::class);
    }

    /** @return HasMany<AdmissionProgram, $this> */
    public function programs(): HasMany
    {
        return $this->hasMany(AdmissionProgram::class, 'evaluation_rule_version_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $rule): void {
            $currentStatus = self::query()->whereKey($rule->getKey())->value('status');
            if ($currentStatus !== 'draft') {
                if ($currentStatus !== 'approved' || $rule->status !== 'retired'
                    || array_diff(array_keys($rule->getDirty()), ['status', 'updated_at']) !== []) {
                    throw ValidationException::withMessages(['rules' => 'Phiên bản quy tắc đã phê duyệt là bất biến.']);
                }
            }
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['rules' => 'Không xóa lịch sử quy tắc.']);
        });
    }
}
