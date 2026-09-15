<?php

namespace App\Livewire\Admin;

use App\Actions\AdmissionReviewSnapshot;
use App\Enums\ApplicationStatus;
use App\Models\AdmissionRound;
use App\Models\Application;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

#[Title('Application review')]
class Applications extends ReviewPage
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $roundFilter = '';

    #[Url]
    public string $statusFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoundFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'roundFilter', 'statusFilter');
        $this->resetPage();
    }

    public function render(): View
    {
        AdmissionReviewSnapshot::reviewer();
        Gate::authorize('viewAny', AdmissionRound::class);
        $statuses = array_values(array_filter(ApplicationStatus::cases(), fn ($status) => $status !== ApplicationStatus::Draft));
        $rounds = AdmissionRound::query()->orderByDesc('year')->orderBy('id')->get();
        try {
            $this->validate([
                'search' => ['string', 'max:100'],
                'roundFilter' => ['nullable', 'integer', 'min:1', Rule::exists(AdmissionRound::class, 'id')],
                'statusFilter' => ['nullable', Rule::in(['all', ...array_map(fn ($s) => $s->value, $statuses)])],
            ]);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $records = Application::query()->whereKey([])->paginate(15);

            return view('livewire.admin.applications', compact('records', 'rounds', 'statuses'));
        }
        $search = trim($this->search);
        $records = Application::query()->with(['candidateProfile.user', 'admissionRound', 'reviewer'])
            ->where('status', '!=', ApplicationStatus::Draft)
            ->when($this->statusFilter === '', fn ($q) => $q->whereIn('status', [ApplicationStatus::Submitted, ApplicationStatus::UnderReview]))
            ->when($this->statusFilter !== '' && $this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->roundFilter !== '', fn ($q) => $q->where('admission_round_id', $this->roundFilter))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search): void {
                $q->whereLike('application_code', '%'.$search.'%')
                    ->orWhereHas('candidateProfile', fn ($p) => $p->whereLike('candidate_code', '%'.$search.'%'))
                    ->orWhereHas('candidateProfile.user', fn ($u) => $u->where(function ($u) use ($search): void {
                        $u->whereLike('name', '%'.$search.'%')->orWhereLike('email', '%'.$search.'%');
                    }));
            }))
            ->orderBy('submitted_at')->orderBy('id')->paginate(15);

        return view('livewire.admin.applications', compact('records', 'rounds', 'statuses'));
    }
}
