<?php

namespace App\Models;

use App\Concerns\ImmutableSubmissionRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['submission_snapshot_id', 'application_id', 'candidate_major_offering_id', 'admission_round_id', 'major_id', 'priority', 'live_wish_id', 'payload', 'content_hash'])]
class SubmissionWishEntry extends Model
{
    use ImmutableSubmissionRecord;

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $snapshot = ApplicationSubmissionSnapshot::query()->lockForUpdate()->findOrFail($entry->submission_snapshot_id);
            if ($snapshot->sealed_at !== null) {
                throw ValidationException::withMessages(['submission' => 'Không thêm nguyện vọng vào snapshot đã niêm phong.']);
            }
        });
    }

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /** @return HasMany<WishMethodBinding, $this> */
    public function bindings(): HasMany
    {
        return $this->hasMany(WishMethodBinding::class);
    }
}
