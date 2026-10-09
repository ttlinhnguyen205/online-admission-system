<?php

namespace App\Livewire\Admin;

use App\Actions\AdmissionReviewSnapshot;
use App\Actions\AdmissionStatistics;
use App\Enums\ApplicationStatus;
use App\Models\AdmissionRound;
use App\Support\AdmissionReportFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class Dashboard extends ReviewPage
{
    use WithPagination;

    #[Url]
    public string $roundFilter = '';

    #[Url]
    public string $yearFilter = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $search = '';

    public function updated(string $property): void
    {
        if ($property === 'yearFilter' && $this->roundFilter !== '' && ! AdmissionRound::query()
            ->whereKey($this->roundFilter)
            ->when($this->yearFilter !== '', fn ($query) => $query->where('year', $this->yearFilter))
            ->exists()) {
            $this->reset('roundFilter');
        }
        if (in_array($property, ['roundFilter', 'statusFilter', 'search', 'yearFilter'], true)) {
            $this->resetPage();
            $this->resetValidation();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('roundFilter', 'statusFilter', 'search', 'yearFilter');
        $this->resetPage();
        $this->resetValidation();
    }

    public function render(AdmissionStatistics $statistics): View
    {
        $actor = AdmissionReviewSnapshot::reviewer();
        abort_unless($this->yearFilter === '' || $actor->isAdmin(), 403);
        $filters = null;
        $summary = null;
        $records = null;
        $pending = collect();
        try {
            $filters = AdmissionReportFilters::from(['roundFilter' => $this->roundFilter, 'statusFilter' => $this->statusFilter, 'search' => $this->search, 'yearFilter' => $this->yearFilter]);
            $summary = $statistics->build($actor, $filters);
            $records = $statistics->applications($actor, $filters)->with(['candidateProfile.user', 'admissionRound'])
                ->orderBy('submitted_at')->orderBy('id')->paginate(15);
            $pending = $statistics->applications($actor, $filters)->whereIn('status', [ApplicationStatus::Submitted, ApplicationStatus::UnderReview])
                ->with('admissionRound')->orderBy('submitted_at')->orderBy('id')->limit(5)->get();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
        }

        return view('livewire.admin.dashboard', [
            'actor' => $actor, 'summary' => $summary, 'records' => $records, 'pending' => $pending,
            'filters' => $filters, 'rounds' => AdmissionRound::query()->when($actor->isAdmin() && $this->yearFilter !== '', fn ($query) => $query->where('year', $this->yearFilter))
                ->orderByDesc('year')->orderBy('id')->get(),
            'years' => $actor->isAdmin() ? AdmissionRound::query()->distinct()->orderByDesc('year')->pluck('year') : collect(),
            'statuses' => AdmissionReportFilters::statuses(),
        ]);
    }
}
