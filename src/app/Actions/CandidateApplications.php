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
use App\Notifications\ApplicationSubmitted;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
                        throw ValidationException::withMessages(['form.admission_round_id' => __('Đợt tuyển sinh này hiện không nhận hồ sơ.')]);
                    }
                    if ($profile->applications()->whereBelongsTo($round)->exists()) {
                        throw ValidationException::withMessages(['form.admission_round_id' => __('Bạn đã có hồ sơ cho đợt tuyển sinh này. Hãy mở trong danh sách hồ sơ.')]);
                    }
                    $application = $profile->applications()->make([
                        'application_code' => 'APP-'.Str::ulid(),
                        'admission_round_id' => $round->getKey(),
                        'status' => ApplicationStatus::Draft,
                    ]);
                    if (! $application->save()) {
                        throw ValidationException::withMessages(['form' => __('Không thể lưu hồ sơ xét tuyển.')]);
                    }

                    return $application;
                }, 3);
            } catch (UniqueConstraintViolationException $exception) {
                $message = (string) ($exception->errorInfo[2] ?? '');
                if (str_contains($message, 'applications_candidate_profile_id_admission_round_id_unique')
                    || str_contains($message, 'applications.candidate_profile_id, applications.admission_round_id')) {
                    throw ValidationException::withMessages(['form.admission_round_id' => __('Bạn đã có hồ sơ cho đợt tuyển sinh này. Hãy mở trong danh sách hồ sơ.')]);
                }
                if (! str_contains($message, 'applications_application_code_unique')
                    && ! str_contains($message, 'UNIQUE constraint failed: applications.application_code')) {
                    throw $exception;
                }
            }
        }

        throw ValidationException::withMessages(['form' => __('Không thể tạo mã hồ sơ. Vui lòng thử lại.')]);
    }

    public function submit(int $applicationId): void
    {
        DB::transaction(function () use ($applicationId): void {
            $profile = self::lockProfile();
            $application = self::lockApplication($profile, $applicationId);
            Gate::authorize('submit', $application);
            self::requireEditable($application);
            $wishes = $application->wishes()->orderBy('id')->lockForUpdate()->get();
            $offerings = CandidateMajorOfferings::lockForWishes($wishes);
            $programs = CandidateWishes::lockPrograms($application, array_values($wishes->map(fn ($wish): int => $wish->getAttribute('admission_program_id'))->all()));
            $round = $application->admissionRound()->lockForUpdate()->firstOrFail();
            CandidateWishes::lockProgramParents($programs);

            $errors = self::submissionErrors($profile, $application, $round);
            $majorCounts = $wishes->countBy(fn ($wish): int => (int) $programs->get($wish->getAttribute('admission_program_id'))?->getAttribute('major_id'));
            if ($wishes->isEmpty()) {
                $errors['wishes'] = __('Cần có ít nhất một nguyện vọng trước khi nộp hồ sơ.');
            } elseif ($wishes->sortBy('priority')->pluck('priority')->values()->all() !== range(1, $wishes->count())
                || $wishes->pluck('admission_program_id')->unique()->count() !== $wishes->count()) {
                $errors['wishes'] = __('Danh sách nguyện vọng không nhất quán. Hãy tải lại và sắp xếp nguyện vọng.');
            }
            foreach ($wishes as $wish) {
                $program = $programs->get($wish->getAttribute('admission_program_id'));
                if ($program === null || CandidateWishes::unavailableReason($program, $round) !== null) {
                    $errors['wishes'] = __('Một hoặc nhiều nguyện vọng không khả dụng hoặc thuộc đợt khác. Hãy kiểm tra trước khi nộp.');
                }
                if ($wish->getAttribute('candidate_major_offering_id') !== null
                    && (! CandidateMajorOfferings::available($offerings->get($wish->getAttribute('candidate_major_offering_id')), $program, $round)
                        || $majorCounts->get((int) $program?->getAttribute('major_id')) !== 1)) {
                    $errors['wishes'] = __('Một hoặc nhiều ngành đã thay đổi hoặc bị trùng. Hãy kiểm tra danh sách nguyện vọng trước khi nộp.');
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            self::requireOpenRound($round);
            $application->fill(['status' => ApplicationStatus::Submitted, 'submitted_at' => now(config('app.timezone'))]);
            if (! $application->save()) {
                throw ValidationException::withMessages(['submission' => __('Không thể nộp hồ sơ xét tuyển.')]);
            }
            $profile->user()->firstOrFail()->notify(new ApplicationSubmitted($application->getKey()));
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
        $testNow = CarbonImmutable::getTestNow();
        $timezone = $testNow instanceof CarbonInterface ? $testNow->timezone : config('app.timezone');
        $now = CarbonImmutable::now($timezone);
        $start = CarbonImmutable::parse($round->getRawOriginal('start_date'), $timezone);
        $end = CarbonImmutable::parse($round->getRawOriginal('end_date'), $timezone);

        return $round->getAttribute('status') === AdmissionRoundStatus::Open
            && $end->greaterThan($start)
            && $now->betweenIncluded($start, $end);
    }

    public static function requireOpenRound(AdmissionRound $round): void
    {
        if (! self::roundIsOpen($round)) {
            throw ValidationException::withMessages(['round' => __('Đợt tuyển sinh đã ngoài thời gian nhận hồ sơ. Hồ sơ và nguyện vọng chỉ có thể xem.')]);
        }
    }

    public static function profileIsComplete(CandidateProfile $profile): bool
    {
        return in_array($profile->getAttribute('profile_status'), [ProfileStatus::Complete, ProfileStatus::NeedsRevision, ProfileStatus::Verified], true)
            && collect(Profile::COMPLETION)->every(fn (string $field): bool => filled($profile->getAttribute($field)));
    }

    /** @return array<string, string> */
    public static function submissionErrors(CandidateProfile $profile, Application $application, AdmissionRound $round): array
    {
        $errors = [];
        if (! self::profileIsComplete($profile)) {
            $errors['profile'] = __('Hãy hoàn thiện và lưu các thông tin hồ sơ cá nhân bắt buộc trước khi nộp. Không cần chờ nhân viên xác minh.');
        }
        if (! self::roundIsOpen($round)) {
            $errors['round'] = __('Đợt tuyển sinh đã ngoài thời gian nhận hồ sơ. Hồ sơ và nguyện vọng chỉ có thể xem.');
        }
        if (Gate::denies('submit', $application)) {
            $errors['submission'] = __('Trạng thái hiện tại của hồ sơ không cho phép chỉnh sửa.');
        }

        return $errors;
    }
}
