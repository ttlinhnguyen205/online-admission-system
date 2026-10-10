<?php

namespace App\Models;

use App\Concerns\ImmutableSubmissionRecord;
use Database\Factories\NativeResultEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['native_result_version_id', 'application_id', 'submission_snapshot_id', 'wish_method_binding_id', 'decision', 'score', 'payload', 'reason'])]
class NativeResultEntry extends Model
{
    /** @use HasFactory<NativeResultEntryFactory> */
    use HasFactory;

    use ImmutableSubmissionRecord;

    protected function casts(): array
    {
        return ['payload' => 'array', 'score' => 'decimal:3'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $version = NativeResultVersion::query()->lockForUpdate()->findOrFail($entry->native_result_version_id);
            if ($version->sealed_at !== null) {
                throw ValidationException::withMessages(['nativeResults' => 'Không thêm kết quả vào phiên bản đã niêm phong.']);
            }
        });
    }

    /** @return BelongsTo<NativeResultVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(NativeResultVersion::class, 'native_result_version_id');
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<WishMethodBinding, $this> */
    public function binding(): BelongsTo
    {
        return $this->belongsTo(WishMethodBinding::class, 'wish_method_binding_id');
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereHas('version', fn ($q) => $q->where('status', 'published')->where('publication_slot', 1)->whereNotNull('approved_at')->whereNotNull('approved_by')
            ->whereNotNull('published_by')->whereNotNull('sealed_at')->where('published_at', '<=', now())
            ->whereHas('admissionRound', fn ($round) => $round->where('status', 'published')));
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeVisibleToCandidate(Builder $query, User $user): Builder
    {
        if (! $user->isCandidate() || ! $user->isActive() || ! $user->hasVerifiedEmail()) {
            return $query->whereRaw('1=0');
        }

        return $query->whereHas('application.candidateProfile', fn ($q) => $q->where('user_id', $user->id))
            ->published();
    }
}
