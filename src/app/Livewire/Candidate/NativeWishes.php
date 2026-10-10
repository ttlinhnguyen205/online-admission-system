<?php

namespace App\Livewire\Candidate;

use App\Actions\CandidateApplications;
use App\Actions\NativeWishRegistration;
use App\Models\AdmissionProgram;
use App\Models\Application;
use App\Models\CandidateMajorOffering;
use App\Models\NativeAdmissionWish;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class NativeWishes extends CandidatePage
{
    #[Locked]
    public int $applicationId;

    #[Locked]
    public string $catalogFingerprint = '';

    /** @var list<int> */
    #[Locked]
    public array $expectedOrder = [];

    public string $offeringId = '';

    public function mount(int $applicationId): void
    {
        $this->applicationId = $applicationId;
        $this->reloadWishes();
    }

    private function application(): Application
    {
        $application = $this->profile()->applications()->findOrFail($this->applicationId);
        Gate::authorize('view', $application);
        abort_unless($application->registration_mode === 'native', 404);

        return $application;
    }

    public function reloadWishes(): void
    {
        $this->catalogFingerprint = NativeWishRegistration::catalogFingerprint($this->application()->admissionRound);
        $this->expectedOrder = array_values($this->application()->nativeWishes()->orderBy('priority')->get()
            ->map(fn (NativeAdmissionWish $wish): int => $wish->id)->all());
    }

    public function addWish(NativeWishRegistration $registration): void
    {
        $this->validate(['offeringId' => ['required', 'integer', 'min:1']], ['offeringId.required' => 'Vui lòng chọn ngành.']);
        $registration->add($this->applicationId, (int) $this->offeringId);
        $this->offeringId = '';
        $this->reloadWishes();
    }

    public function deleteWish(int $id, NativeWishRegistration $registration): void
    {
        $registration->delete($this->applicationId, $id, $this->expectedOrder);
        $this->reloadWishes();
    }

    public function moveWish(int $id, string $direction, NativeWishRegistration $registration): void
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['wishes' => 'Hướng di chuyển không hợp lệ.']);
        }
        $order = $this->expectedOrder;
        $index = array_search($id, $order, true);
        abort_if($index === false, 404);
        $target = $index + ($direction === 'up' ? -1 : 1);
        if (array_key_exists($target, $order)) {
            [$order[$index], $order[$target]] = [$order[$target], $order[$index]];
        }
        $registration->reorder($this->applicationId, $order, $this->expectedOrder);
        $this->reloadWishes();
    }

    public function submit(CandidateApplications $applications): void
    {
        $applications->submit($this->applicationId, $this->catalogFingerprint);
        $this->reloadWishes();
    }

    public function render(): View
    {
        $application = $this->application();
        $round = $application->admissionRound;
        $wishes = $application->nativeWishes()->with('offering.major')->orderBy('priority')->get();
        $programs = AdmissionProgram::query()->where('admission_round_id', $application->admission_round_id)
            ->where('status', 'active')->whereHas('admissionMethod', fn ($query) => $query->where('is_active', true))
            ->with('admissionMethod')->get()->groupBy('major_id');
        $offerings = CandidateMajorOffering::query()->where('admission_round_id', $application->admission_round_id)
            ->where('is_selectable', true)->whereHas('major', fn ($query) => $query->where('is_active', true))
            ->whereNotIn('id', $wishes->pluck('candidate_major_offering_id'))->with('major')->orderBy('major_id')->get()
            ->filter(fn (CandidateMajorOffering $offering): bool => $programs->has($offering->major_id));
        $editable = NativeWishRegistration::openForRound($round) && Gate::allows('update', $application);
        $snapshots = $application->submissionSnapshots()->with('entries.bindings')->orderByDesc('submission_version')->get();
        $checklist = CandidateApplications::submissionErrors($application->candidateProfile, $application, $round);

        return view('livewire.candidate.native-wishes', compact('application', 'round', 'wishes', 'programs', 'offerings', 'editable', 'snapshots', 'checklist'));
    }
}
