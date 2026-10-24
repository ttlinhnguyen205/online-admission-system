<?php

namespace App\Livewire\Admin;

use App\Models\NewsArticle;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Quản lý tin tức')]
class NewsArticles extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public bool $showEditor = false;

    #[Locked]
    public ?int $recordId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function boot(): void
    {
        Auth::user()?->refresh();
        Gate::authorize('viewAny', NewsArticle::class);
    }

    public function create(): void
    {
        Gate::authorize('create', NewsArticle::class);
        $this->resetValidation();
        $this->recordId = null;
        $this->form = ['title' => '', 'category' => 'school', 'summary' => '', 'content' => '', 'status' => 'draft'];
        $this->showEditor = true;
    }

    public function edit(int $id): void
    {
        $article = NewsArticle::query()->findOrFail($id);
        Gate::authorize('update', $article);
        $this->resetValidation();
        $this->recordId = $article->id;
        $this->form = $article->only(['title', 'category', 'summary', 'content', 'status']);
        $this->showEditor = true;
    }

    public function save(): void
    {
        $article = $this->recordId === null ? new NewsArticle : NewsArticle::query()->findOrFail($this->recordId);
        Gate::authorize($article->exists ? 'update' : 'create', $article->exists ? $article : NewsArticle::class);
        foreach (['title', 'summary', 'content'] as $field) {
            if (is_string($this->form[$field] ?? null)) {
                $this->form[$field] = trim($this->form[$field]);
            }
        }
        $validated = $this->validate([
            'form' => ['required', 'array:title,category,summary,content,status'],
            'form.title' => ['required', 'string', 'max:255'],
            'form.category' => ['required', Rule::in(array_keys(NewsArticle::CATEGORIES))],
            'form.summary' => ['nullable', 'string', 'max:1000'],
            'form.content' => ['required', 'string', 'max:100000'],
            'form.status' => ['required', Rule::in(array_keys(NewsArticle::STATUSES))],
        ], [], [
            'form.title' => 'tiêu đề', 'form.category' => 'nhóm tin', 'form.summary' => 'tóm tắt',
            'form.content' => 'nội dung', 'form.status' => 'trạng thái',
        ])['form'];
        $article->fill($validated);
        if (! $article->exists) {
            $article->created_by = Auth::id();
        }
        if ($article->status === 'published' && $article->published_at === null) {
            $article->published_at = now();
        }
        $article->save();
        $this->showEditor = false;
        $this->resetPage();
        Flux::toast(variant: 'success', text: 'Đã lưu bài viết.');
    }

    public function publish(int $id): void
    {
        $article = NewsArticle::query()->findOrFail($id);
        Gate::authorize('update', $article);
        $article->update(['status' => 'published', 'published_at' => $article->published_at ?? now()]);
        Flux::toast(variant: 'success', text: 'Đã xuất bản bài viết.');
    }

    public function hide(int $id): void
    {
        $article = NewsArticle::query()->findOrFail($id);
        Gate::authorize('update', $article);
        $article->update(['status' => 'hidden']);
        Flux::toast(variant: 'success', text: 'Đã ẩn bài viết.');
    }

    public function delete(int $id): void
    {
        $article = NewsArticle::query()->findOrFail($id);
        Gate::authorize('delete', $article);
        $article->delete();
        $this->resetPage();
        Flux::toast(variant: 'success', text: 'Đã xóa bài viết.');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        try {
            $this->validateOnly('search', ['search' => ['string', 'max:100']]);
            $this->validateOnly('statusFilter', ['statusFilter' => ['nullable', Rule::in(array_keys(NewsArticle::STATUSES))]]);
            $records = NewsArticle::query()->whereLike('title', '%'.$this->search.'%')
                ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
                ->latest('id')->paginate(15);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $records = new LengthAwarePaginator([], 0, 15);
        }

        return view('livewire.admin.news-articles', ['records' => $records]);
    }
}
