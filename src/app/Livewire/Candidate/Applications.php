<?php

namespace App\Livewire\Candidate;

use App\Actions\CandidateApplications;
use App\Enums\AdmissionRoundStatus;
use App\Models\AdmissionRound;
use App\Models\Application;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

#[Title('Admission applications')]
class Applications extends CandidatePage
{
    use WithPagination;

    /** @var array<string, mixed> */
    public array $form = ['admission_round_id' => ''];

    public bool $showEditor = false;

    public function create(): void
    {
        Gate::authorize('create', [Application::class, $this->profile()]);
        $this->resetValidation();
        $this->form = ['admission_round_id' => ''];
        $this->showEditor = true;
    }

    public function save(CandidateApplications $applications): void
    {
        $this->candidate();
        $this->resetValidation();
        $application = $applications->create($this->form);
        Flux::toast(variant: 'success', text: __('Draft application created.'));
        $this->redirectRoute('candidate.applications.show', ['application' => $application->getKey()], navigate: true);
    }

    public function render(): View
    {
        $profile = $this->candidate()->candidateProfile()->first();
        $records = null;
        $rounds = collect();
        if ($profile !== null) {
            Gate::authorize('view', $profile);
            Gate::authorize('browseForCandidate', [AdmissionRound::class, $profile]);
            $records = $profile->applications()->with('admissionRound')->orderByDesc('id')->paginate(15);
            $rounds = AdmissionRound::query()->where('status', AdmissionRoundStatus::Open)
                ->where('start_date', '<=', now(config('app.timezone')))->where('end_date', '>=', now(config('app.timezone')))
                ->whereDoesntHave('applications', fn ($query) => $query->whereBelongsTo($profile))
                ->orderBy('start_date')->orderBy('id')->get()
                ->filter(fn (AdmissionRound $round): bool => CandidateApplications::roundIsOpen($round));
        }

        return view('livewire.candidate.applications', compact('profile', 'records', 'rounds'));
    }
}
