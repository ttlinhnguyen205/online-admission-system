<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Models\ActivityLog;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\Major;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdmissionReviewSnapshot
{
    public static function reviewer(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $user = User::query()->find($actor->id);
        abort_unless($user instanceof User && $user->isActive() && $user->canReviewAdmissions() && $user->hasVerifiedEmail(), 403);
        Auth::setUser($user);
        Gate::authorize('viewAny', Application::class);

        return $user;
    }

    /** Locking loads must run inside the caller's transaction. */
    public function load(int $id, bool $lock = false): Application
    {
        $actor = self::reviewer();
        $hint = Application::query()->findOrFail($id);
        Gate::authorize('view', $hint);
        $profileHint = $hint->candidateProfile()->first();
        $users = collect();
        if ($lock) {
            $ids = array_filter([$actor->id, $profileHint?->getAttribute('user_id')]);
            $users = User::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actor->id);
            abort_unless($actor instanceof User && $actor->isActive() && $actor->canReviewAdmissions() && $actor->hasVerifiedEmail(), 403);
            Auth::setUser($actor);
        }
        $profile = CandidateProfile::query()->whereKey($hint->getAttribute('candidate_profile_id'))->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $application = Application::query()->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        Gate::authorize('view', $application);
        if ($application->getAttribute('candidate_profile_id') !== $hint->getAttribute('candidate_profile_id')
            || $profile?->getAttribute('user_id') !== $profileHint?->getAttribute('user_id')) {
            self::stale();
        }
        $application->setRelation('candidateProfile', $profile);
        if ($profile !== null) {
            $profile->setRelation('user', $lock ? $users->get($profile->getAttribute('user_id')) : $profile->user()->first());
        }
        $documents = $application->documents()->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $scores = $profile?->scores()->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $profile?->setRelation('scores', $scores);
        $wishes = $application->wishes()->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $programs = AdmissionProgram::query()->whereKey($wishes->pluck('admission_program_id'))->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $round = AdmissionRound::query()->whereKey($application->getAttribute('admission_round_id'))->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $majors = Major::query()->whereKey($programs->pluck('major_id'))->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $methods = AdmissionMethod::query()->whereKey($programs->pluck('admission_method_id'))->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $results = AdmissionResult::query()->whereIn('admission_wish_id', $wishes->modelKeys())->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('admission_wish_id');
        foreach ($programs as $program) {
            $program->setRelation('major', $majors->get($program->getAttribute('major_id')));
            $program->setRelation('admissionMethod', $methods->get($program->getAttribute('admission_method_id')));
        }
        foreach ($wishes as $wish) {
            $wish->setRelation('admissionProgram', $programs->get($wish->getAttribute('admission_program_id')));
            $wish->setRelation('result', $results->get($wish->getKey()));
        }
        $application->setRelation('documents', $documents);
        $application->setRelation('wishes', $wishes);
        $application->setRelation('admissionRound', $round);
        $application->setRelation('reviewer', $application->reviewer()->first());
        $application->setRelation('latestReviewActivity', ActivityLog::query()
            ->where('subject_type', $application->getMorphClass())->where('subject_id', $id)
            ->whereIn('action', ['application.review_started', 'application.revision_requested', 'application.verified'])
            ->orderByDesc('id')->when($lock, fn ($q) => $q->lockForUpdate())->first());

        return $application;
    }

    /** A fixed-size keyed digest; private record payloads never enter public component state. */
    public function fingerprint(Application $application): string
    {
        $profile = $application->candidateProfile;
        $data = [
            'application' => $this->fields($application, ['id', 'candidate_profile_id', 'admission_round_id', 'application_code', 'status', 'submitted_at', 'reviewed_by', 'reviewed_at', 'revision_reason']),
            'cycle' => $application->getRelation('latestReviewActivity')?->getKey(),
            'profile' => $this->fields($profile, ['id', 'user_id', 'candidate_code', 'date_of_birth', 'gender', 'citizen_id', 'phone', 'address', 'province_code', 'high_school_code', 'high_school_name', 'graduation_year', 'priority_area', 'priority_object', 'photo_path', 'profile_status']),
            'user' => $this->fields($profile?->user, ['id', 'name', 'email']),
            'round' => $this->fields($application->admissionRound, ['id']),
            'documents' => $application->documents->map(fn ($d) => $this->fields($d, ['id', 'application_id', 'document_type', 'original_name', 'file_path', 'mime_type', 'file_size', 'status', 'verified_by', 'verified_at', 'rejection_reason']))->all(),
            'scores' => $profile?->scores->map(fn ($s) => $this->fields($s, ['id', 'candidate_profile_id', 'score_type', 'subject_code', 'subject_name', 'score', 'exam_year', 'evidence_path', 'verified', 'verified_by']))->all(),
            'wishes' => $application->wishes->map(fn ($w) => [
                $this->fields($w, ['id', 'application_id', 'admission_program_id', 'priority']),
                $this->fields($w->admissionProgram, ['id', 'admission_round_id', 'major_id', 'admission_method_id']),
                $w->admissionProgram?->major?->getKey(), $w->admissionProgram?->admissionMethod?->getKey(), $w->result?->getKey(),
            ])->all(),
        ];

        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param list<string> $fields
     * @return array<string, mixed>|null
     */
    private function fields(?Model $model, array $fields): ?array
    {
        return $model === null ? null : array_intersect_key($model->getAttributes(), array_flip($fields));
    }

    public static function stale(): never
    {
        throw ValidationException::withMessages(['review' => __('The review data changed. Reload the application and review the current information before trying again.')]);
    }

    public function fileAvailable(?string $path, bool $photo = false, bool $scoreEvidence = false): bool
    {
        $prefix = $scoreEvidence ? 'candidate-scores/' : ($photo ? 'candidate-photos/' : 'candidate-documents/');
        if (! CandidateFiles::safePath($path) || ! str_starts_with((string) $path, $prefix)) {
            return false;
        }
        try {
            $disk = Storage::disk(CandidateFiles::DISK);
            if (! $disk->exists($path)) {
                return false;
            }
            if (($photo || $scoreEvidence) && ! in_array($disk->mimeType($path), ['image/png', 'image/jpeg'], true)) {
                return false;
            }
            $stream = $disk->readStream($path);
            if (! is_resource($stream)) {
                return false;
            }
            fclose($stream);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, string> */
    public function checklist(Application $application): array
    {
        $errors = [];
        if ($application->getAttribute('status') !== ApplicationStatus::UnderReview) {
            $errors['lifecycle'] = __('Application verification requires an application under review.');
        }
        if ($application->getAttribute('submitted_at') === null) {
            $errors['submission'] = __('A recorded submission is required.');
        }
        $profile = $application->candidateProfile;
        if ($profile === null || $profile->user === null || $application->admissionRound === null) {
            $errors['relationships'] = __('The application has missing profile, user or round relationships.');
        }
        if ($profile === null || ! CandidateApplications::profileIsComplete($profile)) {
            $errors['profile'] = __('The saved profile must be complete with every required field populated.');
        }
        if (! $this->fileAvailable($profile?->getAttribute('photo_path'), true)) {
            $errors['photo'] = __('The required private profile photo is unavailable.');
        }
        $wishes = $application->wishes;
        if ($wishes->isEmpty()) {
            $errors['wishes'] = __('At least one wish is required.');
        } elseif ($wishes->sortBy('priority')->pluck('priority')->values()->all() !== range(1, $wishes->count())
            || $wishes->pluck('admission_program_id')->unique()->count() !== $wishes->count()) {
            $errors['wishes'] = __('Wish priorities must be contiguous and programs must not repeat.');
        }
        foreach ($wishes as $wish) {
            $program = $wish->admissionProgram;
            if ($program === null || $program->major === null || $program->admissionMethod === null
                || $program->getAttribute('admission_round_id') !== $application->getAttribute('admission_round_id')) {
                $errors['wishes'] = __('Every wish must reference an existing program, major and method in this application round.');
            }
            if ($wish->result !== null) {
                $errors['results'] = __('Admission results already exist. Review mutations are blocked for this historical application.');
            }
        }
        foreach ($application->documents as $document) {
            if ($document->getAttribute('status') !== DocumentStatus::Verified) {
                $errors['documents'] = __('Every existing document must be verified.');
            }
            if (! $this->fileAvailable($document->getAttribute('file_path'))) {
                $errors['files'] = __('One or more private document files are unavailable.');
            }
        }
        if ($profile?->scores->contains(fn ($score): bool => ! $score->getAttribute('verified'))) {
            $errors['scores'] = __('Every existing profile score must be verified.');
        }

        return $errors;
    }
}
