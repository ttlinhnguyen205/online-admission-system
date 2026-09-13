<?php

namespace App\Livewire\Admin;

use App\Models\AdmissionMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

#[Title('Admission Methods')]
class AdmissionMethods extends ConfigurationPage
{
    #[Locked]
    public bool $unsupportedConfiguration = false;

    #[Locked]
    public string $configurationPreview = '';

    protected function modelClass(): string
    {
        return AdmissionMethod::class;
    }

    protected function viewName(): string
    {
        return 'livewire.admin.admission-methods';
    }

    protected function defaults(): array
    {
        return ['code' => '', 'name' => '', 'description' => null, 'weights' => [], 'is_active' => true];
    }

    public function create(): void
    {
        parent::create();
        $this->unsupportedConfiguration = false;
        $this->configurationPreview = '';
    }

    /**
     * Phase 3 V1 stores only {"weights":{"MATH":2}} or null.
     * Team Member 3's engine must explicitly support a configuration contract;
     * it must never infer calculation semantics from arbitrary stored JSON.
     *
     * @return array<string, mixed>
     */
    private function weightRules(): array
    {
        return [
            'form.weights' => ['present', 'array', 'list', 'max:30'],
            'form.weights.*' => ['required', 'array:subject,weight'],
            'form.weights.*.subject' => ['required', 'string', 'max:30', 'regex:/\\A[A-Z0-9_-]+\\z/D', 'distinct:strict'],
            'form.weights.*.weight' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:100'],
        ];
    }

    protected function formRules(?Model $record): array
    {
        return [
            ...$this->namedRules($record),
            'form.description' => ['nullable', 'string', 'max:10000'],
            'form.is_active' => ['required', 'boolean'],
            ...$this->weightRules(),
        ];
    }

    /** @return list<array{subject: string, weight: mixed}>|null */
    private function supportedRows(mixed $configuration): ?array
    {
        if ($configuration === null) {
            return [];
        }
        if (! is_array($configuration) || array_keys($configuration) !== ['weights']
            || ! is_array($configuration['weights']) || $configuration['weights'] === []) {
            return null;
        }
        $rows = [];
        foreach ($configuration['weights'] as $subject => $weight) {
            $rows[] = ['subject' => (string) $subject, 'weight' => $weight];
        }

        return Validator::make(['form' => ['weights' => $rows]], $this->weightRules())->passes() ? $rows : null;
    }

    protected function recordForm(Model $record): array
    {
        $rows = $this->supportedRows($record->getAttribute('score_config'));
        $this->unsupportedConfiguration = $rows === null;
        $this->configurationPreview = $rows === null
            ? (string) json_encode($record->getAttribute('score_config'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '';

        return [
            ...$record->only(['code', 'name', 'description', 'is_active']),
            'weights' => $rows ?? [],
        ];
    }

    protected function normalizeForm(): void
    {
        parent::normalizeForm();
        if (is_array($this->form['weights'] ?? null)) {
            foreach ($this->form['weights'] as $index => $row) {
                if (is_array($row) && is_string($row['subject'] ?? null)) {
                    $this->form['weights'][$index]['subject'] = strtoupper(trim($row['subject']));
                }
            }
        }
    }

    protected function attributesForSave(array $validated, ?Model $record): array
    {
        $attributes = array_intersect_key($validated, array_flip(['code', 'name', 'description', 'is_active']));
        if ($record !== null && $this->supportedRows($record->getAttribute('score_config')) === null) {
            if ($validated['weights'] !== []) {
                throw ValidationException::withMessages(['form.weights' => 'This existing configuration is unsupported and must be preserved.']);
            }

            return $attributes;
        }
        $weights = [];
        foreach ($validated['weights'] as $row) {
            $weights[$row['subject']] = (float) $row['weight'];
        }
        $attributes['score_config'] = $weights === [] ? null : ['weights' => (object) $weights];

        return $attributes;
    }

    public function addWeight(): void
    {
        $record = $this->recordId === null ? null : AdmissionMethod::query()->findOrFail($this->recordId);
        Gate::authorize($record === null ? 'create' : 'update', $record ?? AdmissionMethod::class);
        if ($record !== null && $this->supportedRows($record->score_config) === null) {
            throw ValidationException::withMessages(['form.weights' => 'This existing configuration is unsupported and must be preserved.']);
        }
        $this->validateOnly('form.weights', ['form.weights' => ['present', 'array', 'list', 'max:29']]);
        $this->form['weights'][] = ['subject' => '', 'weight' => '1'];
    }

    public function removeWeight(int $index): void
    {
        $record = $this->recordId === null ? null : AdmissionMethod::query()->findOrFail($this->recordId);
        Gate::authorize($record === null ? 'create' : 'update', $record ?? AdmissionMethod::class);
        if (is_array($this->form['weights'] ?? null)) {
            unset($this->form['weights'][$index]);
            $this->form['weights'] = array_values($this->form['weights']);
        }
    }

    /** @return Builder<AdmissionMethod> */
    protected function recordsQuery(): Builder
    {
        $this->validateOnly('statusFilter', ['statusFilter' => ['nullable', Rule::in(['active', 'inactive'])]]);

        return AdmissionMethod::query()->where(function (Builder $query): void {
            $query->whereLike('code', '%'.$this->search.'%')->orWhereLike('name', '%'.$this->search.'%');
        })->when($this->statusFilter !== '', fn (Builder $query) => $query->where('is_active', $this->statusFilter === 'active'))
            ->withCount('programs')->orderBy('name')->orderBy('id');
    }
}
