<?php

namespace App\Models;

use App\Concerns\ImmutableSubmissionRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['application_id', 'submission_version', 'submitted_at', 'sealed_at', 'registration_mode', 'readiness', 'catalog_fingerprint', 'manifest', 'content_hash', 'amendment_metadata'])]
class ApplicationSubmissionSnapshot extends Model
{
    use ImmutableSubmissionRecord;

    protected function casts(): array
    {
        return ['manifest' => 'array', 'amendment_metadata' => 'array', 'submitted_at' => 'datetime', 'sealed_at' => 'datetime'];
    }

    protected function immutableUpdateIsAllowed(): bool
    {
        return $this->getRawOriginal('sealed_at') === null && $this->sealed_at !== null
            && array_diff(array_keys($this->getDirty()), ['sealed_at', 'updated_at']) === [];
    }

    /** @return HasMany<SubmissionWishEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(SubmissionWishEntry::class, 'submission_snapshot_id');
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
