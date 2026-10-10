<?php

namespace App\Concerns;

use Illuminate\Validation\ValidationException;

trait ImmutableSubmissionRecord
{
    protected static function bootImmutableSubmissionRecord(): void
    {
        static::updating(function (self $record): void {
            if ($record->immutableUpdateIsAllowed()) {
                return;
            }
            throw ValidationException::withMessages(['submission' => 'Không được sửa lịch sử đã nộp.']);
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['submission' => 'Không được xóa lịch sử đã nộp.']);
        });
    }

    protected function immutableUpdateIsAllowed(): bool
    {
        return false;
    }
}
