<?php

namespace App\Livewire\Candidate;

use App\Actions\CandidateApplications;
use App\Actions\CandidateWishes;
use App\Models\AdmissionProgram;
use App\Models\Application;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

#[Title('Application details')]
class ApplicationDetails extends CandidatePage
{
    #[Locked]
    public int $applicationId;

    /** @var list<int> */
    #[Locked]
    public array $expectedOrder = [];

    #[Locked]
    public ?int $deleteId = null;

    /** @var array<string, mixed> */
    public array $form = ['admission_program_id' => ''];

    public bool $showDeletion = false;

    public bool $showSubmission = false;

    public function mount(int $application): void
    {
        $this->applicationId = $this->profile()->applications()->findOrFail($application)->getKey();
        $this->reloadWishes();
    }

    protected function application(): Application
    {
        $application = $this->profile()->applications()->findOrFail($this->applicationId);
        Gate::authorize('view', $application);

        return $application;
    }

    public function reloadWishes(): void
    {
        $this->expectedOrder = CandidateWishes::orderedIds($this->application()->wishes()->get());
        $this->resetValidation();
        $this->deleteId = null;
        $this->showDeletion = false;
        $this->showSubmission = false;
    }

    public function addWish(CandidateWishes $wishes): void
    {
        $this->candidate();
        $wishes->add($this->applicationId, $this->form);
        $this->form = ['admission_program_id' => ''];
        $this->reloadWishes();
        Flux::toast(variant: 'success', text: __('Wish added.'));
    }

    public function confirmDeletion(int $id): void
    {
        $application = $this->application();
        $wish = $application->wishes()->findOrFail($id);
        Gate::authorize('delete', $wish);
        CandidateApplications::requireOpenRound($application->admissionRound()->firstOrFail());
        $this->resetValidation();
        $this->deleteId = $wish->getKey();
        $this->showDeletion = true;
    }

    public function deleteWish(CandidateWishes $wishes): void
    {
        $this->candidate();
        abort_if($this->deleteId === null, 404);
        $wishes->delete($this->applicationId, $this->deleteId, $this->expectedOrder);
        $this->reloadWishes();
        Flux::toast(variant: 'success', text: __('Wish removed and priorities updated.'));
    }

    /** @param array<mixed> $order */
    public function reorderWishes(array $order, CandidateWishes $wishes): void
    {
        $this->candidate();
        $wishes->reorder($this->applicationId, $order, $this->expectedOrder);
        $this->reloadWishes();
        Flux::toast(variant: 'success', text: __('Wish priorities saved.'));
    }

    public function moveWish(int $id, string $direction, CandidateWishes $wishes): void
    {
        $application = $this->application();
        $wish = $application->wishes()->findOrFail($id);
        Gate::authorize('update', $wish);
        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['order' => __('Choose move up or move down.')]);
        }
        $order = $this->expectedOrder;
        $position = array_search($id, $order, true);
        if ($position === false) {
            throw ValidationException::withMessages(['order' => __('Reload the wishes before changing their order.')]);
        }
        $target = $position + ($direction === 'up' ? -1 : 1);
        if (array_key_exists($target, $order)) {
            [$order[$position], $order[$target]] = [$order[$target], $order[$position]];
        }
        $this->reorderWishes($order, $wishes);
    }

    public function confirmSubmission(): void
    {
        $application = $this->application();
        Gate::authorize('submit', $application);
        CandidateApplications::requireOpenRound($application->admissionRound()->firstOrFail());
        $this->resetValidation();
        $this->showSubmission = true;
    }

    public function submit(CandidateApplications $applications): void
    {
        $this->candidate();
        $this->validate(['form' => ['array:admission_program_id']]);
        $applications->submit($this->applicationId);
        $this->reloadWishes();
        Flux::toast(variant: 'success', text: __('Application submitted.'));
    }

    public function render(): View
    {
        $profile = $this->profile();
        $application = $this->application();
        Gate::authorize('browseForApplication', [AdmissionProgram::class, $application]);
        $round = $application->admissionRound()->firstOrFail();
        $wishes = $application->wishes()->with(['admissionProgram.major', 'admissionProgram.admissionMethod', 'admissionProgram.admissionRound'])->withExists('result')->orderBy('priority')->get();
        $programs = $round->programs()->with(['major', 'admissionMethod'])->orderBy('id')->get();
        $reasons = $programs->mapWithKeys(fn (AdmissionProgram $program): array => [$program->getKey() => CandidateWishes::unavailableReason($program, $round)]);
        $selected = $wishes->pluck('admission_program_id')->all();
        $editable = Gate::allows('update', $application) && CandidateApplications::roundIsOpen($round);
        $checklist = CandidateApplications::submissionErrors($profile, $application, $round);
        if ($wishes->isEmpty()) {
            $checklist['wishes'] = __('Add at least one admission wish before submitting.');
        } elseif ($wishes->pluck('priority')->all() !== range(1, $wishes->count()) || $wishes->pluck('admission_program_id')->unique()->count() !== $wishes->count()) {
            $checklist['wishes'] = __('Reload and reorder your wishes to restore contiguous priorities.');
        }
        foreach ($wishes as $wish) {
            if (CandidateWishes::unavailableReason($wish->admissionProgram, $round) !== null) {
                $checklist['programs'] = __('One or more wishes are unavailable. Review the wish list.');
            }
        }

        return view('livewire.candidate.application-details', compact('application', 'round', 'wishes', 'programs', 'reasons', 'selected', 'editable', 'checklist'));
    }
}
