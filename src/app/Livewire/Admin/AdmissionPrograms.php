<?php

namespace App\Livewire\Admin;

use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Major;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

#[Title('Admission Programs')]
class AdmissionPrograms extends ConfigurationPage
{
    #[Url]
    public string $roundFilter = '';

    #[Url]
    public string $majorFilter = '';

    #[Url]
    public string $methodFilter = '';

    #[Locked]
    public bool $relationshipsLocked = false;

    protected function modelClass(): string
    {
        return AdmissionProgram::class;
    }

    protected function viewName(): string
    {
        return 'livewire.admin.admission-programs';
    }

    protected function defaults(): array
    {
        return ['admission_round_id' => '', 'major_id' => '', 'admission_method_id' => '', 'quota' => 0,
            'minimum_score' => null, 'previous_cutoff_score' => null, 'tuition_fee' => null, 'status' => 'active'];
    }

    public function create(): void
    {
        parent::create();
        $this->relationshipsLocked = false;
    }

    protected function recordForm(Model $record): array
    {
        $this->relationshipsLocked = $record instanceof AdmissionProgram && ($record->wishes()->exists() || $record->candidateMajorOfferings()->exists());

        return parent::recordForm($record);
    }

    protected function formRules(?Model $record): array
    {
        return [
            'form' => ['required', 'array:'.implode(',', array_keys($this->defaults()))],
            'form.admission_round_id' => ['required', 'integer', 'min:1', Rule::exists(AdmissionRound::class, 'id')],
            'form.major_id' => ['required', 'integer', 'min:1', Rule::exists(Major::class, 'id')],
            'form.admission_method_id' => ['required', 'integer', 'min:1', Rule::exists(AdmissionMethod::class, 'id')],
            'form.quota' => ['required', 'integer', 'between:0,4294967295'],
            'form.minimum_score' => ['nullable', 'numeric', 'decimal:0,3', 'between:0,99999.999'],
            'form.previous_cutoff_score' => ['nullable', 'numeric', 'decimal:0,3', 'between:0,99999.999'],
            'form.tuition_fee' => $this->feeRules(),
            'form.status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    protected function attributesForSave(array $validated, ?Model $record): array
    {
        $parents = ['admission_round_id' => AdmissionRound::class, 'major_id' => Major::class, 'admission_method_id' => AdmissionMethod::class];
        foreach ($parents as $field => $model) {
            $parent = $model::query()->lockForUpdate()->find($validated[$field]);
            if ($parent === null) {
                throw ValidationException::withMessages(['form.'.$field => 'The selected record no longer exists.']);
            }
            Gate::authorize('view', $parent);
        }

        if ($record instanceof AdmissionProgram && ($record->wishes()->exists() || $record->candidateMajorOfferings()->exists())) {
            foreach (array_keys($parents) as $field) {
                if ((int) $validated[$field] !== (int) $record->getAttribute($field)) {
                    throw ValidationException::withMessages(['form.'.$field => 'The round, major and method cannot change once this program has wishes or candidate offerings.']);
                }
            }
        }

        $duplicate = AdmissionProgram::query()->where('admission_round_id', $validated['admission_round_id'])
            ->where('major_id', $validated['major_id'])->where('admission_method_id', $validated['admission_method_id'])
            ->when($record !== null, fn (Builder $query) => $query->whereKeyNot($record->getKey()))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([$this->uniqueErrorField() => $this->uniqueErrorMessage()]);
        }

        return parent::attributesForSave($validated, $record);
    }

    protected function uniqueErrorField(): string
    {
        return 'form.admission_method_id';
    }

    protected function uniqueErrorMessage(): string
    {
        return 'A program already exists for this round, major and admission method.';
    }

    /** @return Builder<AdmissionProgram> */
    protected function recordsQuery(): Builder
    {
        foreach (['roundFilter' => AdmissionRound::class, 'majorFilter' => Major::class, 'methodFilter' => AdmissionMethod::class] as $field => $model) {
            $this->validateOnly($field, [$field => ['nullable', 'integer', 'min:1', Rule::exists($model, 'id')]]);
        }
        $this->validateOnly('statusFilter', ['statusFilter' => ['nullable', Rule::in(['active', 'inactive'])]]);

        return AdmissionProgram::query()->with(['admissionRound', 'major', 'admissionMethod'])->withCount('wishes')
            ->where(function (Builder $query): void {
                foreach (['admissionRound', 'major', 'admissionMethod'] as $relation) {
                    $query->orWhereHas($relation, function (Builder $related): void {
                        $related->whereLike('code', '%'.$this->search.'%')->orWhereLike('name', '%'.$this->search.'%');
                    });
                }
            })
            ->when($this->roundFilter !== '', fn (Builder $query) => $query->where('admission_round_id', $this->roundFilter))
            ->when($this->majorFilter !== '', fn (Builder $query) => $query->where('major_id', $this->majorFilter))
            ->when($this->methodFilter !== '', fn (Builder $query) => $query->where('admission_method_id', $this->methodFilter))
            ->when($this->statusFilter !== '', fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->orderByDesc('id');
    }

    protected function viewData(): array
    {
        foreach ([AdmissionRound::class, Major::class, AdmissionMethod::class] as $model) {
            Gate::authorize('viewAny', $model);
        }

        return [
            'rounds' => AdmissionRound::query()->orderByDesc('year')->orderBy('name')->get(),
            'majors' => Major::query()->orderBy('name')->get(),
            'methods' => AdmissionMethod::query()->orderBy('name')->get(),
        ];
    }

    public function updatedRoundFilter(): void
    {
        $this->resetPage();
    }

    public function updatedMajorFilter(): void
    {
        $this->resetPage();
    }

    public function updatedMethodFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        parent::clearFilters();
        $this->reset('roundFilter', 'majorFilter', 'methodFilter');
    }
}
