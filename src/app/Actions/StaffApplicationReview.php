<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StaffApplicationReview
{
    public function __construct(private AdmissionReviewSnapshot $snapshots, private RecordAdmissionReviewActivity $audit) {}

    public function start(int $id, string $expected): void
    {
        $this->mutate($id, $expected, ApplicationStatus::Submitted, function (Application $application): void {
            if ($application->getAttribute('submitted_at') === null) {
                throw ValidationException::withMessages(['review' => __('A recorded submission is required to start review.')]);
            }
            $this->save($application, ['status' => ApplicationStatus::UnderReview, 'reviewed_by' => Auth::id(), 'reviewed_at' => null], 'application.review_started');
        });
    }

    /** @param array<string, mixed> $form */
    public function requestRevision(int $id, string $expected, array $form): void
    {
        $this->mutate($id, $expected, ApplicationStatus::UnderReview, function (Application $application) use ($form): void {
            $reason = $this->reason($form, 'revision_reason');
            $this->save($application, ['status' => ApplicationStatus::NeedsRevision, 'reviewed_by' => Auth::id(),
                'reviewed_at' => now(config('app.timezone')), 'revision_reason' => $reason], 'application.revision_requested');
        });
    }

    public function verify(int $id, string $expected): void
    {
        $this->mutate($id, $expected, ApplicationStatus::UnderReview, function (Application $application): void {
            $errors = $this->snapshots->checklist($application);
            if ($errors !== []) {
                throw ValidationException::withMessages(['review' => implode(' ', $errors)]);
            }
            $this->save($application, ['status' => ApplicationStatus::Verified, 'reviewed_by' => Auth::id(),
                'reviewed_at' => now(config('app.timezone')), 'revision_reason' => null], 'application.verified');
        });
    }

    public function verifyDocument(int $id, int $documentId, string $expected): void
    {
        $this->mutate($id, $expected, ApplicationStatus::UnderReview, function (Application $application) use ($documentId): void {
            $document = $application->documents->firstWhere('id', $documentId);
            abort_if($document === null, 404);
            Gate::authorize('verify', $document);
            if ($document->getAttribute('status') !== DocumentStatus::Pending) {
                throw ValidationException::withMessages(['review' => __('Only pending documents can be reviewed. Reload the application.')]);
            }
            if (! $this->snapshots->fileAvailable($document->getAttribute('file_path'))) {
                throw ValidationException::withMessages(['review' => __('The private document file is unavailable. Verification was not saved.')]);
            }
            $this->save($document, ['status' => DocumentStatus::Verified, 'verified_by' => Auth::id(),
                'verified_at' => now(config('app.timezone')), 'rejection_reason' => null], 'document.verified');
        });
    }

    /** @param array<string, mixed> $form */
    public function rejectDocument(int $id, int $documentId, string $expected, array $form): void
    {
        $this->mutate($id, $expected, ApplicationStatus::UnderReview, function (Application $application) use ($documentId, $form): void {
            $document = $application->documents->firstWhere('id', $documentId);
            abort_if($document === null, 404);
            Gate::authorize('reject', $document);
            if ($document->getAttribute('status') !== DocumentStatus::Pending) {
                throw ValidationException::withMessages(['review' => __('Only pending documents can be reviewed. Reload the application.')]);
            }
            $reason = $this->reason($form, 'rejection_reason');
            $this->save($document, ['status' => DocumentStatus::Rejected, 'verified_by' => null,
                'verified_at' => null, 'rejection_reason' => $reason], 'document.rejected');
        });
    }

    public function verifyScore(int $id, int $scoreId, string $expected): void
    {
        $this->mutate($id, $expected, ApplicationStatus::UnderReview, function (Application $application) use ($scoreId): void {
            $score = $application->candidateProfile?->scores->firstWhere('id', $scoreId);
            abort_if($score === null, 404);
            Gate::authorize('verify', $score);
            if ($score->getAttribute('verified')) {
                throw ValidationException::withMessages(['review' => __('This score is already verified. Its reviewer has not been changed.')]);
            }
            $this->save($score, ['verified' => true, 'verified_by' => Auth::id()], 'score.verified');
        });
    }

    /** @param \Closure(Application): void $operation */
    private function mutate(int $id, string $expected, ApplicationStatus $state, \Closure $operation): void
    {
        DB::transaction(function () use ($id, $expected, $state, $operation): void {
            $application = $this->snapshots->load($id, true);
            Gate::authorize('review', $application);
            if ($application->getAttribute('status') !== $state) {
                throw ValidationException::withMessages(['review' => __('The application is no longer in the required review state. Reload before continuing.')]);
            }
            if ($application->wishes->contains(fn ($wish): bool => $wish->result !== null)) {
                throw ValidationException::withMessages(['review' => __('Admission results already exist. Review mutations are blocked for this historical application.')]);
            }
            if (! hash_equals($this->snapshots->fingerprint($application), $expected)) {
                AdmissionReviewSnapshot::stale();
            }
            $operation($application);
        }, 3);
    }

    /** @param array<string, mixed> $form */
    private function reason(array $form, string $field): string
    {
        if (is_string($form[$field] ?? null)) {
            $form[$field] = trim($form[$field]);
        }
        $validated = Validator::make(['form' => $form], [
            'form' => ['required', 'array:'.$field],
            'form.'.$field => ['required', 'string', 'max:5000'],
        ])->validate();

        return $validated['form'][$field];
    }

    /** @param array<string, mixed> $attributes */
    private function save(Model $subject, array $attributes, string $action): void
    {
        $old = $subject->getAttributes();
        $subject->fill($attributes);
        if (! $subject->save()) {
            throw ValidationException::withMessages(['review' => __('The review could not be saved. No changes were made.')]);
        }
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $this->audit->record($actor, $subject, $action, $old);
    }
}
