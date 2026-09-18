<?php

namespace App\Actions;

use App\Models\AdmissionProgram;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConfirmAdmissionResult
{
    public function __construct(private RecordAdmissionResultActivity $audit) {}

    public function confirm(int $resultId): AdmissionResult
    {
        return DB::transaction(function () use ($resultId): AdmissionResult {
            $actor = User::query()->lockForUpdate()->find(Auth::id());
            abort_unless($actor instanceof User && $actor->isActive() && $actor->isCandidate() && $actor->hasVerifiedEmail(), 403);
            Auth::setUser($actor);
            $profile = CandidateProfile::query()->whereBelongsTo($actor)->lockForUpdate()->firstOrFail();
            $hint = AdmissionResult::query()->visibleToCandidate($actor)->findOrFail($resultId);
            $wishHint = AdmissionWish::query()->findOrFail($hint->admission_wish_id);
            $application = Application::query()->whereBelongsTo($profile)->whereKey($wishHint->application_id)->lockForUpdate()->firstOrFail();
            $wish = AdmissionWish::query()->whereBelongsTo($application)->whereKey($wishHint->id)->lockForUpdate()->firstOrFail();
            $program = AdmissionProgram::query()->whereKey($wish->admission_program_id)->lockForUpdate()->firstOrFail();
            $round = AdmissionRound::query()->whereKey($application->admission_round_id)->lockForUpdate()->firstOrFail();
            $result = AdmissionResult::query()->whereKey($resultId)->where('admission_wish_id', $wish->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('confirm', $result);
            abort_unless($program->admission_round_id === $round->id && $round->getRawOriginal('status') === 'published'
                && $application->getRawOriginal('status') === 'completed' && $wish->getRawOriginal('status') === 'admitted'
                && $program->major()->exists() && $program->admissionMethod()->exists(), 404);
            if ($result->confirmed_at !== null) {
                return $result;
            }
            $timestamp = now(config('app.timezone'))->startOfSecond();
            $result->confirmed_at = $timestamp;
            if (! $result->save()) {
                AdmissionEngineSnapshot::fail('Confirmation could not be saved.');
            }
            $this->audit->record($actor, $result, 'admission_result.confirmed', [
                'result_id' => $result->id, 'round_id' => $round->id,
                'confirmed_at' => $timestamp->format('Y-m-d H:i:s'),
            ]);

            return $result;
        }, 3);
    }
}
