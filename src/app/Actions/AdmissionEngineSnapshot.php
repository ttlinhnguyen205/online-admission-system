<?php

namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\CandidateScore;
use App\Models\Major;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdmissionEngineSnapshot
{
    public static function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $actor = User::query()->find($actor->getKey());
        abort_unless($actor instanceof User && $actor->isAdmin() && $actor->isActive() && $actor->hasVerifiedEmail(), 403);
        Auth::setUser($actor);
        Gate::authorize('process', AdmissionRound::class);

        return $actor;
    }

    /**
     * Locking calls require the caller's transaction. Hints are never authority.
     * Keep user/profile/application/child/program/round/parent ordering aligned
     * with candidate and staff mutations; retry deadlocks at the outer action.
     *
     * @return array<string, mixed>
     */
    public function load(int $id, bool $lock = false): array
    {
        $actor = self::actor();
        $hints = Application::query()->where('admission_round_id', $id)->orderBy('id')->get();
        $profileHints = CandidateProfile::query()->whereKey($hints->pluck('candidate_profile_id'))->orderBy('id')->get();
        $users = User::query()->whereKey([...$profileHints->pluck('user_id')->all(), $actor->id])->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $actor = $users->get($actor->id);
        abort_unless($actor instanceof User && $actor->isAdmin() && $actor->isActive() && $actor->hasVerifiedEmail(), 403);
        Auth::setUser($actor);
        Gate::authorize('process', AdmissionRound::class);
        $profiles = CandidateProfile::query()->whereKey($hints->pluck('candidate_profile_id'))->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $applications = Application::query()->where('admission_round_id', $id)->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        if ($applications->map->only(['id', 'candidate_profile_id'])->values()->all() !== $hints->map->only(['id', 'candidate_profile_id'])->values()->all()
            || $profiles->map->only(['id', 'user_id'])->values()->all() !== $profileHints->map->only(['id', 'user_id'])->values()->all()) {
            self::fail('The round cohort changed. Preview again.');
        }
        $scores = CandidateScore::query()->whereIn('candidate_profile_id', $profiles->modelKeys())->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $wishes = AdmissionWish::query()->whereIn('application_id', $applications->modelKeys())->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $programs = AdmissionProgram::query()->where(fn ($q) => $q->where('admission_round_id', $id)->orWhereIn('id', $wishes->pluck('admission_program_id')))
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $round = AdmissionRound::query()->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        $majors = Major::query()->whereKey($programs->pluck('major_id'))->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $methods = AdmissionMethod::query()->whereKey($programs->pluck('admission_method_id'))->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $results = AdmissionResult::query()->where(fn ($q) => $q->whereIn('admission_wish_id', $wishes->modelKeys())
            ->orWhereHas('admissionWish.admissionProgram', fn ($p) => $p->where('admission_round_id', $id)))
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $logs = ActivityLog::query()->where('subject_type', $round->getMorphClass())->where('subject_id', $id)
            ->whereIn('action', ['admission_engine.started', 'admission_engine.completed'])->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        if ($lock && Application::query()->where('admission_round_id', $id)->orderBy('id')->lockForUpdate()->pluck('id')->all() !== $applications->keys()->all()) {
            self::fail('The round cohort changed. Preview again.');
        }

        return compact('actor', 'round', 'applications', 'profiles', 'scores', 'wishes', 'programs', 'majors', 'methods', 'results', 'logs');
    }

    /** @param array<string, mixed> $data */
    public function fingerprint(array $data): string
    {
        $payload = ['contract' => config('admission_engine')];
        foreach ([
            'applications' => ['id', 'candidate_profile_id', 'admission_round_id', 'status', 'submitted_at', 'reviewed_at', 'reviewed_by'],
            'profiles' => ['id', 'user_id'],
            'scores' => ['id', 'candidate_profile_id', 'score_type', 'subject_code', 'exam_year', 'score', 'verified', 'verified_by'],
            'wishes' => ['id', 'application_id', 'admission_program_id', 'priority', 'status', 'calculated_score'],
            'programs' => ['id', 'admission_round_id', 'major_id', 'admission_method_id', 'quota', 'minimum_score'],
            'majors' => ['id'], 'methods' => ['id', 'code', 'score_config'],
            'results' => ['id', 'admission_wish_id', 'final_score', 'rank', 'decision', 'decided_at', 'published_at', 'confirmed_at'],
            'logs' => ['id', 'action'],
        ] as $key => $fields) {
            $payload[$key] = $data[$key]->map->only($fields)->values()->all();
        }
        $payload['round'] = $data['round']->only(['id', 'year', 'status']);

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /**
     * Integrity of persisted outcomes, not full historical input replay.
     * Live catalog/profile inputs are deliberately not archived as private data.
     *
     * @param  array<string, mixed>  $data
     */
    public function outcomeFingerprint(array $data): string
    {
        $applications = $data['applications']->filter(fn ($a) => $a->getRawOriginal('status') !== 'draft');
        $payload = [
            $applications->map->only(['id', 'candidate_profile_id', 'admission_round_id', 'status'])->values()->all(),
            $data['wishes']->whereIn('application_id', $applications->modelKeys())->map->only(['id', 'application_id', 'admission_program_id', 'priority', 'calculated_score', 'status'])->values()->all(),
            $data['results']->map->only(['id', 'admission_wish_id', 'final_score', 'rank', 'decision', 'decided_at', 'published_at', 'confirmed_at'])->values()->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['engine' => __($message)]);
    }
}
