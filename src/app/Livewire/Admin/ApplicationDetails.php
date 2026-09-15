<?php

namespace App\Livewire\Admin;

use App\Actions\AdmissionReviewSnapshot;
use App\Actions\CandidateApplications;
use App\Actions\StaffApplicationReview;
use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Models\ActivityLog;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

#[Title('Application review details')]
class ApplicationDetails extends ReviewPage
{
    #[Locked]
    public int $applicationId;

    #[Locked]
    public string $expected = '';

    #[Locked]
    public string $operation = '';

    #[Locked]
    public ?int $childId = null;

    public bool $showConfirmation = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(int $application, AdmissionReviewSnapshot $snapshots): void
    {
        $this->applicationId = $application;
        $this->reloadReview($snapshots);
    }

    public function reloadReview(AdmissionReviewSnapshot $snapshots): void
    {
        $application = $snapshots->load($this->applicationId);
        $this->expected = $snapshots->fingerprint($application);
        $this->resetValidation();
        $this->operation = '';
        $this->childId = null;
        $this->form = [];
        $this->showConfirmation = false;
    }

    public function confirm(string $operation, ?int $childId = null): void
    {
        abort_unless(in_array($operation, ['start', 'revision', 'verify', 'verifyDocument', 'rejectDocument', 'verifyScore'], true), 404);
        $snapshots = new AdmissionReviewSnapshot;
        $application = $snapshots->load($this->applicationId);
        Gate::authorize('review', $application);
        $required = $operation === 'start' ? ApplicationStatus::Submitted : ApplicationStatus::UnderReview;
        if ($application->getAttribute('status') !== $required || $application->wishes->contains(fn ($wish) => $wish->result !== null)) {
            throw ValidationException::withMessages(['review' => __('This application is read-only for that review action.')]);
        }
        if (! hash_equals($snapshots->fingerprint($application), $this->expected)) {
            AdmissionReviewSnapshot::stale();
        }
        if (in_array($operation, ['verifyDocument', 'rejectDocument'], true)) {
            $document = $application->documents->firstWhere('id', $childId);
            abort_if($document === null, 404);
            Gate::authorize($operation === 'verifyDocument' ? 'verify' : 'reject', $document);
            if ($document->getAttribute('status') !== DocumentStatus::Pending) {
                throw ValidationException::withMessages(['review' => __('Only pending documents can be reviewed.')]);
            }
        } elseif ($operation === 'verifyScore') {
            $score = $application->candidateProfile?->scores->firstWhere('id', $childId);
            abort_if($score === null, 404);
            Gate::authorize('verify', $score);
            if ($score->getAttribute('verified')) {
                throw ValidationException::withMessages(['review' => __('This score is already verified.')]);
            }
        } else {
            abort_unless($childId === null, 404);
        }
        $this->resetValidation();
        $this->operation = $operation;
        $this->childId = $childId;
        $this->form = match ($operation) {
            'revision' => ['revision_reason' => ''],
            'rejectDocument' => ['rejection_reason' => ''],
            default => [],
        };
        $this->showConfirmation = true;
    }

    public function perform(StaffApplicationReview $review, AdmissionReviewSnapshot $snapshots): void
    {
        AdmissionReviewSnapshot::reviewer();
        try {
            if (! in_array($this->operation, ['revision', 'rejectDocument'], true) && $this->form !== []) {
                throw ValidationException::withMessages(['form' => __('This action does not accept form fields.')]);
            }
            match ($this->operation) {
                'start' => $review->start($this->applicationId, $this->expected),
                'revision' => $review->requestRevision($this->applicationId, $this->expected, $this->form),
                'verify' => $review->verify($this->applicationId, $this->expected),
                'verifyDocument' => $review->verifyDocument($this->applicationId, $this->childId ?? 0, $this->expected),
                'rejectDocument' => $review->rejectDocument($this->applicationId, $this->childId ?? 0, $this->expected, $this->form),
                'verifyScore' => $review->verifyScore($this->applicationId, $this->childId ?? 0, $this->expected),
                default => abort(403),
            };
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: __('Review not saved. Check the errors and reload if the data changed.'));
            throw $exception;
        }
        $this->reloadReview($snapshots);
        Flux::toast(variant: 'success', text: __('Review decision saved.'));
    }

    public function render(): View
    {
        $snapshots = new AdmissionReviewSnapshot;
        $application = $snapshots->load($this->applicationId);
        $profile = $application->candidateProfile;
        $checklist = $snapshots->checklist($application);
        $stale = ! hash_equals($snapshots->fingerprint($application), $this->expected);
        $hasResults = $application->wishes->contains(fn ($wish) => $wish->result !== null);
        $reviewable = $application->getAttribute('status') === ApplicationStatus::UnderReview && ! $hasResults;
        $canStart = $application->getAttribute('status') === ApplicationStatus::Submitted && ! $hasResults;
        $windowOpen = $application->admissionRound !== null && CandidateApplications::roundIsOpen($application->admissionRound);
        $photoAvailable = $snapshots->fileAvailable($profile?->getAttribute('photo_path'), true);
        $documentAvailability = $application->documents->mapWithKeys(fn ($document) => [$document->getKey() => $snapshots->fileAvailable($document->getAttribute('file_path'))]);
        $history = ActivityLog::query()->with('user')->where('subject_type', $application->getMorphClass())
            ->where('subject_id', $application->getKey())
            ->whereIn('action', ['application.review_started', 'application.revision_requested', 'application.verified'])
            ->orderByDesc('id')->limit(20)->get();

        return view('livewire.admin.application-details', compact('application', 'profile', 'checklist', 'stale', 'reviewable', 'canStart', 'hasResults', 'windowOpen', 'photoAvailable', 'documentAvailability', 'history'));
    }
}
