<?php

namespace App\Livewire\Admin;

use App\Actions\AdmissionEngineSnapshot;
use App\Actions\PublishAdmissionResults;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Admission results')]
class Results extends Component
{
    use WithPagination;

    public string $roundSelection = '';

    public function boot(): void
    {
        AdmissionEngineSnapshot::actor();
        Gate::authorize('publishResults', AdmissionRound::class);
    }

    public function updatedRoundSelection(): void
    {
        $this->resetPage();
        $this->resetValidation();
    }

    public function publish(PublishAdmissionResults $publisher): void
    {
        $this->boot();
        $this->validate(['roundSelection' => ['required', 'integer', 'min:1']]);
        $publisher->publish((int) $this->roundSelection);
        Flux::toast(variant: 'success', text: __('Results published. Repeated publication leaves the original publication unchanged.'));
    }

    public function render(): View
    {
        $this->boot();
        $query = AdmissionResult::query()->whereHas('admissionWish.application', fn ($q) => $q->where('admission_round_id', (int) $this->roundSelection));
        $counts = [
            'Total results' => (clone $query)->count(),
            'Admitted' => (clone $query)->where('decision', 'admitted')->count(),
            'Not admitted' => (clone $query)->where('decision', 'not_admitted')->count(),
            'Published' => (clone $query)->whereNotNull('published_at')->count(),
            'Unpublished' => (clone $query)->whereNull('published_at')->count(),
            'Confirmed admitted' => (clone $query)->where('decision', 'admitted')->whereNotNull('confirmed_at')->count(),
        ];

        return view('livewire.admin.results', [
            'rounds' => AdmissionRound::query()->orderByDesc('year')->orderBy('id')->get(),
            'selectedRound' => AdmissionRound::query()->find((int) $this->roundSelection),
            'counts' => $counts,
            'results' => $query->with(['admissionWish.application:id,application_code', 'admissionWish.admissionProgram.major', 'admissionWish.admissionProgram.admissionMethod'])
                ->orderBy('id')->paginate(25),
        ]);
    }
}
