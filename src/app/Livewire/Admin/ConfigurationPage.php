<?php

namespace App\Livewire\Admin;

use App\Actions\DeleteAdmissionConfiguration;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

abstract class ConfigurationPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    /** @var array<string, mixed> */
    public array $form = [];

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public ?int $deleteId = null;

    #[Locked]
    public bool $readOnly = false;

    public bool $showEditor = false;

    public bool $showDeletion = false;

    #[Locked]
    public string $deleteLabel = '';

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return array<string, mixed> */
    abstract protected function defaults(): array;

    /** @return array<string, mixed> */
    abstract protected function formRules(?Model $record): array;

    /** @return Builder<covariant Model> */
    abstract protected function recordsQuery(): Builder;

    /** @return view-string */
    abstract protected function viewName(): string;

    public function boot(): void
    {
        Auth::user()?->refresh();
        Gate::authorize('viewAny', $this->modelClass());
    }

    public function create(): void
    {
        Gate::authorize('create', $this->modelClass());
        $this->resetValidation();
        $this->recordId = null;
        $this->readOnly = false;
        $this->form = $this->defaults();
        $this->showEditor = true;
    }

    public function edit(int $id): void
    {
        $record = $this->modelClass()::query()->findOrFail($id);
        Gate::authorize('update', $record);
        $this->openRecord($record, false);
    }

    public function details(int $id): void
    {
        $record = $this->modelClass()::query()->findOrFail($id);
        Gate::authorize('view', $record);
        $this->openRecord($record, true);
    }

    protected function openRecord(Model $record, bool $readOnly): void
    {
        $this->resetValidation();
        $this->recordId = (int) $record->getKey();
        $this->readOnly = $readOnly;
        $this->form = $this->recordForm($record);
        $this->showEditor = true;
    }

    /** @return array<string, mixed> */
    protected function recordForm(Model $record): array
    {
        return $record->only(array_keys($this->defaults()));
    }

    public function save(): void
    {
        $model = $this->modelClass();
        try {
            (new $model)->getConnection()->transaction(function () use ($model): void {
                $record = $this->recordId === null ? null : $model::query()->lockForUpdate()->findOrFail($this->recordId);
                Gate::authorize($record === null ? 'create' : 'update', $record ?? $model);
                $this->normalizeForm();
                $validated = $this->validate($this->formRules($record));
                $attributes = $this->attributesForSave($validated['form'], $record);
                if ($record === null) {
                    $model::query()->create($attributes);
                } else {
                    $record->update($attributes);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$this->uniqueErrorField() => $this->uniqueErrorMessage()]);
        }
        $this->showEditor = false;
        $this->resetPage();
        Flux::toast(variant: 'success', text: __('Configuration saved.'));
    }

    protected function uniqueErrorField(): string
    {
        return 'form.code';
    }

    protected function uniqueErrorMessage(): string
    {
        return 'This code is already in use.';
    }

    protected function normalizeForm(): void
    {
        foreach ($this->form as $key => $value) {
            if (is_string($value)) {
                $this->form[$key] = trim($value) === '' ? null : trim($value);
            }
        }
    }

    /** @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected function attributesForSave(array $validated, ?Model $record): array
    {
        return array_intersect_key($validated, $this->defaults());
    }

    /** @return array<string, mixed> */
    protected function namedRules(?Model $record): array
    {
        return [
            'form' => ['required', 'array:'.implode(',', array_keys($this->defaults()))],
            'form.code' => ['required', 'string', 'max:30', Rule::unique($this->modelClass(), 'code')->ignore($record)],
            'form.name' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return list<string> */
    protected function feeRules(): array
    {
        return ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'];
    }

    public function confirmDeletion(int $id): void
    {
        $record = $this->modelClass()::query()->findOrFail($id);
        Gate::authorize('update', $record);
        $this->resetValidation();
        $this->deleteId = (int) $record->getKey();
        $this->deleteLabel = (string) ($record->getAttribute('name') ?? 'Program #'.$record->getKey());
        $this->showDeletion = true;
        if (Gate::denies('delete', $record)) {
            $this->addError('deletion', 'This record is in use by admission records and cannot be deleted.');
        }
    }

    public function delete(DeleteAdmissionConfiguration $delete): void
    {
        abort_if($this->deleteId === null, 404);
        $delete($this->modelClass()::query()->findOrFail($this->deleteId));
        $this->showDeletion = false;
        $this->deleteId = null;
        $this->resetPage();
        Flux::toast(variant: 'success', text: __('Configuration deleted.'));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'statusFilter');
        $this->resetPage();
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        return [];
    }

    public function render(): View
    {
        Gate::authorize('viewAny', $this->modelClass());
        $this->validateOnly('search', ['search' => ['string', 'max:100']]);

        return view($this->viewName(), [
            'records' => $this->recordsQuery()->paginate(15),
            'resourceModel' => $this->modelClass(),
            ...$this->viewData(),
        ]);
    }
}
