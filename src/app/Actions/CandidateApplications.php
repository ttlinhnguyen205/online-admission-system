<?php

namespace App\Actions;

use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Enums\ProfileStatus;
use App\Livewire\Candidate\Profile;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CandidateApplications
{
    /** @param array<string, mixed> $form */
    public function create(array $form): Application
    {
        $validated = Validator::make(['form' => $form], [
            'form' => ['required', 'array:admission_round_id'],
            'form.admission_round_id' => ['required', 'integer', 'min:1'],
        ])->validate();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($validated): Application {
                    $profile = self::lockProfile();
                    Gate::authorize('create', [Application::class, $profile]);
                    Gate::authorize('browseForCandidate', [AdmissionRound::class, $profile]);
                    $round = AdmissionRound::query()->lockForUpdate()->find((int) $validated['form']['admission_round_id']);
                    if ($round === null || ! self::roundIsOpen($round)) {
                        throw ValidationException::withMessages(['form.admission_round_id' => __('This round is not accepting applications.')]);
                    }
                    if ($profile->applications()->whereBelongsTo($round)->exists()) {
                        throw ValidationException::withMessages(['form.admission_round_id' => __('You already have an application for this round. Open it from your application list.')]);
                    }
                    $application = $profile->applications()->make([
                        'application_code' => 'APP-'.Str::ulid(),
                        'admission_round_id' => $round->getKey(),
                        'status' => ApplicationStatus::Draft,
                    ]);
                    if (! $application->save()) {
                        throw ValidationException::withMessages(['form' => __('The application could not be saved.')]);
                    }

                    return $application;
                }, 3);
            } catch (UniqueConstraintViolationException $exception) {
                $message = (string) ($exception->errorInfo[2] ?? '');
                if (str_contains($message, 'applications_candidate_profile_id_admission_round_id_unique')
                    || str_contains($message, 'applications.candidate_profile_id, applications.admission_round_id')) {
                    throw ValidationException::withMessages(['form.admission_round_id' => __('You already have an application for this round. Open it from your application list.')]);
                }
                if (! str_contains($message, 'applications_application_code_unique')
                    && ! str_contains($message, 'UNIQUE constraint failed: applications.application_code')) {
                    throw $exception;
                }
            }
        }

        throw ValidationException::withMessages(['form' => __('An application code could not be generated. Please try again.')]);
    }

    public function submit(int $applicationId): void
    {
        DB::transaction(function () use ($applicationId): void {
            $profile = self::lockProfile();
            $application = self::lockApplication($profile, $applicationId);
            Gate::authorize('submit', $application);
            self::requireEditable($application);
            $wishes = $application->wishes()->orderBy('id')->lockForUpdate()->get();
            $programs = CandidateWishes::lockPrograms($application, array_values($wishes->map(fn ($wish): int => $wish->getAttribute('admission_program_id'))->all()));
            $round = $application->admissionRound()->lockForUpdate()->firstOrFail();
            CandidateWishes::lockProgramParents($programs);

            $errors = self::submissionErrors($profile, $application, $round);
            if ($wishes->isEmpty()) {
                $errors['wishes'] = __('Add at least one admission wish before submitting.');
            } elseif ($wishes->sortBy('priority')->pluck('priority')->values()->all() !== range(1, $wishes->count())
                || $wishes->pluck('admission_program_id')->unique()->count() !== $wishes->count()) {
                $errors['wishes'] = __('The wish priorities or programs are inconsistent. Reload and reorder your wishes.');
            }
            foreach ($wishes as $wish) {
                $program = $programs->get($wish->getAttribute('admission_program_id'));
                if ($program === null || CandidateWishes::unavailableReason($program, $round) !== null) {
                    $errors['wishes'] = __('One or more wishes are unavailable or belong to another round. Review your wishes before submitting.');
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            self::requireOpenRound($round);
            $application->fill(['status' => ApplicationStatus::Submitted, 'submitted_at' => now(config('app.timezone'))]);
            if (! $application->save()) {
                throw ValidationException::withMessages(['submission' => __('The application could not be submitted.')]);
            }
        }, 3);
    }

    /** Locks must be acquired inside the caller's transaction. */
    public static function lockProfile(): CandidateProfile
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $user = $actor->newQuery()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
        abort_unless($user->isActive() && $user->isCandidate() && $user->hasVerifiedEmail(), 403);
        Auth::setUser($user);
        $profile = $user->candidateProfile()->lockForUpdate()->firstOrFail();
        Gate::authorize('view', $profile);

        return $profile;
    }

    public static function lockApplication(CandidateProfile $profile, int $id): Application
    {
        $application = $profile->applications()->lockForUpdate()->findOrFail($id);
        Gate::authorize('view', $application);

        return $application;
    }

    /** Check the locked row as well as policies that may query a repeatable-read snapshot. */
    public static function requireEditable(Application $application): void
    {
        Gate::authorize('update', $application);
        abort_unless(in_array($application->getAttribute('status'), [ApplicationStatus::Draft, ApplicationStatus::NeedsRevision], true), 403);
    }

    public static function roundIsOpen(AdmissionRound $round): bool
    {
        $timezone = config('app.timezone');
        $start = CarbonImmutable::parse($round->getRawOriginal('start_date'), $timezone);
        $end = CarbonImmutable::parse($round->getRawOriginal('end_date'), $timezone);

        return $round->getAttribute('status') === AdmissionRoundStatus::Open
            && $end->greaterThan($start)
            && CarbonImmutable::now($timezone)->betweenIncluded($start, $end);
    }

    public static function requireOpenRound(AdmissionRound $round): void
    {
        if (! self::roundIsOpen($round)) {
            throw ValidationException::withMessages(['round' => __('This round is outside its open application window. Applications and wishes are read-only.')]);
        }
    }

    public static function profileIsComplete(CandidateProfile $profile): bool
    {
        return in_array($profile->getAttribute('profile_status'), [ProfileStatus::Complete, ProfileStatus::Verified], true)
            && collect(Profile::COMPLETION)->every(fn (string $field): bool => filled($profile->getAttribute($field)));
    }

    /** @return array<string, string> */
    public static function submissionErrors(CandidateProfile $profile, Application $application, AdmissionRound $round): array
    {
        $errors = [];
        if (! self::profileIsComplete($profile)) {
            $errors['profile'] = __('Complete and save all required profile fields before submitting. Staff verification is not required.');
        }
        if (! self::roundIsOpen($round)) {
            $errors['round'] = __('This round is outside its open application window. Applications and wishes are read-only.');
        }
        if (Gate::denies('submit', $application)) {
            $errors['submission'] = __('This application is read-only in its current status.');
        }

        return $errors;
    }
}
