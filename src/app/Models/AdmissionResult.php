<?php

namespace App\Models;

use App\Enums\AdmissionDecision;
use Database\Factories\AdmissionResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['admission_wish_id', 'final_score', 'rank', 'decision', 'decided_at', 'published_at', 'confirmed_at'])]
class AdmissionResult extends Model
{
    /** @param Builder<AdmissionResult> $query */
    #[Scope]
    protected function visibleToCandidate(Builder $query, User $user): void
    {
        $query->whereNotNull('published_at')->where('published_at', '<=', now())
            ->whereHas('admissionWish.application', fn ($q) => $q
                ->whereHas('candidateProfile', fn ($p) => $p->whereBelongsTo($user))
                ->whereHas('admissionRound', fn ($r) => $r->where('status', 'published')))
            ->whereHas('admissionWish', fn ($q) => $q->whereHas('admissionProgram', fn ($p) => $p
                ->where('admission_programs.admission_round_id', '=',
                    Application::query()->select('admission_round_id')->whereColumn('applications.id', 'admission_wishes.application_id')->limit(1))));
    }

    /** @use HasFactory<AdmissionResultFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'final_score' => 'decimal:3',
            'rank' => 'integer',
            'decision' => AdmissionDecision::class,
            'decided_at' => 'datetime',
            'published_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AdmissionWish, $this> */
    public function admissionWish(): BelongsTo
    {
        return $this->belongsTo(AdmissionWish::class);
    }
}
