<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['admission_quota_version_id', 'admission_program_id', 'admission_round_id', 'major_id', 'quota'])]
class AdmissionQuotaMethodLimit extends Model
{
    protected function casts(): array
    {
        return ['quota' => 'integer'];
    }

    protected static function booted(): void
    {
        $guard = function (self $limit): void {
            $ids = array_filter([$limit->admission_quota_version_id, $limit->getRawOriginal('admission_quota_version_id')]);
            if (AdmissionQuotaVersion::query()->whereKey($ids)->where('status', '!=', 'draft')->exists()) {
                throw ValidationException::withMessages(['quota' => 'Không sửa chỉ tiêu của phiên bản đã phê duyệt.']);
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }

    /** @return BelongsTo<AdmissionProgram, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(AdmissionProgram::class, 'admission_program_id');
    }
}
