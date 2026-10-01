<?php

namespace App\Livewire\Admin;

use App\Actions\AdmissionReviewSnapshot;
use App\Models\ActivityLog;
use App\Models\Application;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

#[Title('Lịch sử xử lý')]
class ReviewHistory extends ReviewPage
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $actionFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedActionFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'actionFilter');
        $this->resetPage();
    }

    public function render(): View
    {
        AdmissionReviewSnapshot::reviewer();
        Gate::authorize('viewAny', Application::class);

        $actions = [
            'application.review_started',
            'application.revision_requested',
            'application.rejected',
            'application.verified',
        ];

        $search = trim($this->search);

        $records = ActivityLog::query()
            ->with(['user', 'subject'])
            ->where('subject_type', (new Application)->getMorphClass())
            ->whereIn('action', $actions)
            ->when(
                $this->actionFilter !== '' && in_array($this->actionFilter, $actions, true),
                fn($query) => $query->where('action', $this->actionFilter)
            )
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->whereHasMorph(
                        'subject',
                        [Application::class],
                        fn($application) => $application
                            ->whereLike('application_code', '%' . $search . '%')
                            ->orWhereHas('candidateProfile.user', function ($user) use ($search): void {
                                $user->whereLike('name', '%' . $search . '%')
                                    ->orWhereLike('email', '%' . $search . '%');
                            })
                    )->orWhereHas('user', function ($user) use ($search): void {
                        $user->whereLike('name', '%' . $search . '%')
                            ->orWhereLike('email', '%' . $search . '%');
                    });
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);

        return view('livewire.admin.review-history', compact('records', 'actions'));
    }
}
