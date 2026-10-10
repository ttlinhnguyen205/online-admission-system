<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['candidate_major_offering_id', 'admission_round_id', 'major_id', 'version', 'total_quota', 'status', 'catalog_fingerprint', 'previous_version_id', 'approved_by', 'approved_at', 'retired_at', 'reason', 'content_hash'])]
class AdmissionQuotaVersion extends Model
{
    protected function casts(): array
    {
        return ['total_quota' => 'integer', 'version' => 'integer', 'approved_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getRawOriginal('status') !== 'draft' && $version->isDirty()) {
                $changes = array_diff(array_keys($version->getDirty()), ['status', 'retired_at', 'updated_at']);
                if ($changes !== [] || ! ($version->getRawOriginal('status') === 'approved' && $version->status === 'retired')) {
                    throw ValidationException::withMessages(['quota' => 'Phiên bản đã phê duyệt không được sửa. Hãy tạo bản nháp mới.']);
                }
            }
        });
        static::deleting(function (self $version): void {
            throw ValidationException::withMessages(['quota' => 'Không xóa lịch sử chỉ tiêu.']);
        });
    }

    /** @return BelongsTo<CandidateMajorOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(CandidateMajorOffering::class, 'candidate_major_offering_id');
    }

    /** @return HasMany<AdmissionQuotaMethodLimit, $this> */
    public function limits(): HasMany
    {
        return $this->hasMany(AdmissionQuotaMethodLimit::class);
    }
}
