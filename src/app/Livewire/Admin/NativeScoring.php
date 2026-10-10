<?php

namespace App\Livewire\Admin;

use App\Actions\AdmissionReviewSnapshot;
use App\Actions\NativeAdmissionScoring;
use App\Actions\NativeSourceVerification;
use App\Actions\NativeWishRegistration;
use App\Models\Application;
use App\Models\NativeMethodEvaluation;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NativeScoring extends Component
{
    #[Locked]
    public int $applicationId;

    public bool $confirmed = false;

    public bool $showSourceConfirmation = false;

    public bool $sourceConfirmed = false;

    #[Locked]
    public string $sourceType = '';

    #[Locked]
    public ?int $sourceId = null;

    #[Locked]
    public string $sourceExpected = '';

    public function confirmSource(string $type, int $id, NativeSourceVerification $verification): void
    {
        $application = $this->application();
        Gate::authorize('scoreNative', $application);
        abort_unless(in_array($type, ['thpt', 'transcript'], true), 404);
        $source = $type === 'thpt' ? $application->candidateProfile->examResults()->where('exam_type', 'thpt')->findOrFail($id)
            : $application->candidateProfile->transcripts()->findOrFail($id);
        $this->sourceType = $type;
        $this->sourceId = $id;
        $this->sourceExpected = NativeWishRegistration::hash($verification->inspect($source));
        $this->sourceConfirmed = false;
        $this->showSourceConfirmation = true;
    }

    public function verifySource(NativeSourceVerification $verification): void
    {
        abort_unless($this->showSourceConfirmation && $this->sourceId !== null, 403);
        $this->validate(['sourceConfirmed' => ['accepted']]);
        $verification->verify($this->applicationId, $this->sourceType, $this->sourceId, $this->sourceExpected);
        $this->showSourceConfirmation = false;
        $this->sourceConfirmed = false;
        Flux::toast(variant: 'success', text: 'Đã xác minh nguồn điểm; chưa chạy tính điểm.');
    }

    public function boot(): void
    {
        AdmissionReviewSnapshot::reviewer();
    }

    public function mount(int $applicationId): void
    {
        $this->applicationId = $applicationId;
        $this->application();
    }

    private function application(): Application
    {
        $application = Application::query()->findOrFail($this->applicationId);
        Gate::authorize('view', $application);
        abort_unless($application->registration_mode === 'native', 404);

        return $application;
    }

    public function run(NativeAdmissionScoring $scoring): void
    {
        $this->application();
        $this->validate(['confirmed' => ['accepted']]);
        $scoring->score($this->applicationId);
        $this->confirmed = false;
        Flux::toast(variant: 'success', text: 'Đã đánh giá từng phương thức native.');
    }

    public function render(): View
    {
        $application = $this->application();
        $available = Schema::hasTable('native_method_evaluations');
        $snapshot = $application->submissionSnapshots()->orderByDesc('submission_version')->with('entries.bindings')->first();
        $ids = $snapshot?->entries->flatMap(fn ($entry) => $entry->bindings->pluck('id')) ?? collect();
        $evaluations = $available ? NativeMethodEvaluation::query()->whereIn('wish_method_binding_id', $ids)
            ->where('algorithm_version', NativeAdmissionScoring::ALGORITHM)->get()->keyBy('wish_method_binding_id') : collect();

        return view('livewire.admin.native-scoring', ['available' => $available, 'snapshot' => $snapshot, 'evaluations' => $evaluations,
            'examSources' => $application->candidateProfile->examResults()->where('exam_type', 'thpt')->with('subjectScores')->orderBy('id')->get(),
            'transcriptSources' => $application->candidateProfile->transcripts()->with(['scores', 'evidenceImages'])->orderBy('id')->get(),
            'canScore' => $available && Gate::allows('scoreNative', $application)]);
    }
}
