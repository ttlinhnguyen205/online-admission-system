<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\NativeAllocationPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** @property CarbonImmutable|null $approved_at */
#[Fillable(['admission_round_id', 'version', 'status', 'payload', 'content_hash', 'created_by', 'approved_by', 'approved_at', 'approval_slot'])]
class NativeAllocationPolicy extends Model
{
    /** @use HasFactory<NativeAllocationPolicyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['payload' => 'array', 'approved_at' => 'datetime', 'approval_slot' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $policy): void {
            if ($policy->getRawOriginal('status') !== 'draft' || $policy->status !== 'approved'
                || array_diff(array_keys($policy->getDirty()), ['status', 'approved_by', 'approved_at', 'approval_slot', 'updated_at']) !== []) {
                throw ValidationException::withMessages(['allocation' => 'Không sửa nội dung hoặc lịch sử chính sách phối hợp.']);
            }
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['allocation' => 'Không xóa lịch sử chính sách phối hợp.']);
        });
    }
}
