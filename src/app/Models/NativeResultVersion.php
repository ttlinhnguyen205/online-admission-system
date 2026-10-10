<?php

namespace App\Models;

use Database\Factories\NativeResultVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['admission_round_id', 'version', 'status', 'algorithm_version', 'policy_reference', 'input_manifest', 'input_hash', 'content_hash', 'sealed_at', 'created_by', 'approved_by', 'approved_at', 'published_by', 'published_at', 'rejected_by', 'rejected_at', 'reason', 'rejection_reason', 'publication_slot'])]
class NativeResultVersion extends Model
{
    /** @use HasFactory<NativeResultVersionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['input_manifest' => 'array', 'publication_slot' => 'integer', 'sealed_at' => 'datetime', 'approved_at' => 'datetime', 'published_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            $old = $version->getRawOriginal('status');
            $operation = match ([$old, $version->status]) {
                ['draft', 'approved'] => 'approved', ['draft', 'rejected'] => 'rejected', ['approved', 'published'] => 'published', default => null,
            };
            if ($version->getRawOriginal('sealed_at') === null && $version->sealed_at !== null
                && array_diff(array_keys($version->getDirty()), ['sealed_at', 'updated_at']) === []) {
                return;
            }
            $extra = match ($operation) {
                'rejected' => ['rejection_reason'], 'published' => ['publication_slot'], default => []
            };
            if ($operation === null || ($operation === 'published' && $version->publication_slot !== 1)
                || array_diff(array_keys($version->getDirty()), ['status', $operation.'_by', $operation.'_at', 'updated_at', ...$extra]) !== []) {
                throw ValidationException::withMessages(['nativeResults' => 'Phiên bản kết quả bất biến; hãy tạo phiên bản mới.']);
            }
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['nativeResults' => 'Không xóa lịch sử kết quả.']);
        });
    }

    /** @return HasMany<NativeResultEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(NativeResultEntry::class);
    }

    /** @return BelongsTo<AdmissionRound, $this> */
    public function admissionRound(): BelongsTo
    {
        return $this->belongsTo(AdmissionRound::class);
    }
}
