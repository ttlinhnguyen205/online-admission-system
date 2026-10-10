<?php

namespace App\Models;

use App\Enums\AdmissionRoundStatus;
use Database\Factories\AdmissionRoundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

#[Fillable(['code', 'name', 'year', 'start_date', 'end_date', 'result_date', 'status'])]
class AdmissionRound extends Model
{
    public function nativeRegistrationState(): string
    {
        return $this->getAttribute('native_registration_state') ?? 'legacy';
    }

    protected static function booted(): void
    {
        static::updating(function (self $round): void {
            if ($round->isDirty('native_registration_state')) {
                $current = self::query()->whereKey($round->getKey())->firstOrFail()->nativeRegistrationState();
                $allowed = ['legacy' => ['native_draft'], 'native_draft' => ['native_open'],
                    'native_open' => ['native_closed'], 'native_closed' => ['native_open']];
                if (! in_array($round->nativeRegistrationState(), $allowed[$current] ?? [], true)) {
                    throw ValidationException::withMessages(['native' => 'Không chuyển ngược round native về legacy hoặc bỏ qua bước chuẩn bị.']);
                }
            }
            $nativePublication = $round->getAttribute('status') === AdmissionRoundStatus::Published && $round->nativeRegistrationState() === 'native_closed'
                && Schema::hasTable('native_result_versions')
                && NativeResultVersion::query()->where('admission_round_id', $round->id)->where('status', 'published')->where('publication_slot', 1)
                    ->whereNotNull('sealed_at')->whereNotNull('approved_by')->whereNotNull('approved_at')
                    ->whereNotNull('published_by')->whereNotNull('published_at')->exists();
            if ($round->nativeRegistrationState() !== 'legacy' && in_array($round->getAttribute('status'), [AdmissionRoundStatus::Processing, AdmissionRoundStatus::Published], true) && ! $nativePublication) {
                throw ValidationException::withMessages(['native' => 'Round native chưa hỗ trợ xử lý hoặc công bố kết quả.']);
            }
        });
    }

    /** @use HasFactory<AdmissionRoundFactory> */
    use HasFactory;

    /** @return array{year: 'integer', start_date: 'datetime', end_date: 'datetime', result_date: 'datetime', status: class-string<AdmissionRoundStatus>, native_activated_at: 'datetime'} */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'result_date' => 'datetime',
            'status' => AdmissionRoundStatus::class,
            'native_activated_at' => 'datetime',
        ];
    }

    /** @return HasMany<AdmissionProgram, $this> */
    public function programs(): HasMany
    {
        return $this->hasMany(AdmissionProgram::class);
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /** @return HasMany<CandidateMajorOffering, $this> */
    public function candidateMajorOfferings(): HasMany
    {
        return $this->hasMany(CandidateMajorOffering::class);
    }
}
