<?php

namespace App\Models;

use App\Concerns\ImmutableSubmissionRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['submission_wish_entry_id', 'admission_round_id', 'major_id', 'admission_program_id', 'admission_method_id', 'evaluation_rule_version_id', 'catalog_reference', 'binding_hash'])]
class WishMethodBinding extends Model
{
    use ImmutableSubmissionRecord;

    protected static function booted(): void
    {
        static::creating(function (self $binding): void {
            $entry = SubmissionWishEntry::query()->findOrFail($binding->submission_wish_entry_id);
            $snapshot = ApplicationSubmissionSnapshot::query()->lockForUpdate()->findOrFail($entry->submission_snapshot_id);
            if ($snapshot->sealed_at !== null) {
                throw ValidationException::withMessages(['submission' => 'Không thêm phương thức vào snapshot đã niêm phong.']);
            }
        });
    }

    protected function casts(): array
    {
        return ['catalog_reference' => 'array'];
    }

    /** @return BelongsTo<EvaluationRuleVersion, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(EvaluationRuleVersion::class, 'evaluation_rule_version_id');
    }
}
