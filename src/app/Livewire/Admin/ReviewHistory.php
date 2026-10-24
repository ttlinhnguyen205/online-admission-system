<?php

namespace App\Livewire\Admin;

use App\Actions\AdmissionReviewSnapshot;
use App\Models\ActivityLog;
use App\Models\Application;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
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

    #[Url]
    public string $statusFilter = '';

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

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
        $this->reset('search', 'actionFilter', 'statusFilter', 'dateFrom', 'dateTo');
        $this->resetValidation();
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
        $validator = Validator::make(
            ['dateFrom' => $this->dateFrom, 'dateTo' => $this->dateTo],
            ['dateFrom' => ['nullable', 'date_format:Y-m-d'], 'dateTo' => ['nullable', 'date_format:Y-m-d', ...($this->dateFrom !== '' ? ['after_or_equal:dateFrom'] : [])]],
            ['date_format' => 'Ngày phải có định dạng năm-tháng-ngày.', 'after_or_equal' => 'Ngày kết thúc phải từ ngày bắt đầu trở đi.']
        );
        $validDates = $validator->passes();
        $this->setErrorBag($validator->errors());

        $records = ActivityLog::query()
            ->with(['user', 'subject' => fn ($relation) => $relation->morphWith([Application::class => ['candidateProfile.user']])])
            ->where('subject_type', (new Application)->getMorphClass())
            ->whereIn('action', $actions)
            ->when($this->statusFilter !== '', fn ($query) => $query->where('new_values->status', $this->statusFilter))
            ->when(! $validDates, fn ($query) => $query->whereRaw('1 = 0'))
            ->when($validDates && $this->dateFrom !== '', fn ($query) => $query->where('created_at', '>=', Carbon::parse($this->dateFrom, 'Asia/Ho_Chi_Minh')->startOfDay()->setTimezone(config('app.timezone'))))
            ->when($validDates && $this->dateTo !== '', fn ($query) => $query->where('created_at', '<', Carbon::parse($this->dateTo, 'Asia/Ho_Chi_Minh')->addDay()->startOfDay()->setTimezone(config('app.timezone'))))
            ->when(
                $this->actionFilter !== '' && in_array($this->actionFilter, $actions, true),
                fn ($query) => $query->where('action', $this->actionFilter)
            )
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->whereHasMorph(
                        'subject',
                        [Application::class],
                        fn ($application) => $application
                            ->whereLike('application_code', '%'.$search.'%')
                            ->orWhereHas('candidateProfile.user', function ($user) use ($search): void {
                                $user->whereLike('name', '%'.$search.'%')
                                    ->orWhereLike('email', '%'.$search.'%');
                            })
                    )->orWhereHas('user', function ($user) use ($search): void {
                        $user->whereLike('name', '%'.$search.'%')
                            ->orWhereLike('email', '%'.$search.'%');
                    });
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);

        return view('livewire.admin.review-history', compact('records', 'actions'));
    }
}
