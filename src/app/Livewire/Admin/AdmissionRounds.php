<?php

namespace App\Livewire\Admin;

use App\Enums\AdmissionRoundStatus;
use App\Models\AdmissionRound;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

#[Title('Admission Rounds')]
class AdmissionRounds extends ConfigurationPage
{
    #[Url]
    public string $yearFilter = '';

    protected function modelClass(): string
    {
        return AdmissionRound::class;
    }

    protected function viewName(): string
    {
        return 'livewire.admin.admission-rounds';
    }

    protected function defaults(): array
    {
        return ['code' => '', 'name' => '', 'year' => now()->year, 'start_date' => '', 'end_date' => '', 'result_date' => null, 'status' => 'draft'];
    }

    protected function formRules(?Model $record): array
    {
        return [
            ...$this->namedRules($record),
            'form.year' => ['required', 'integer', 'between:2000,2100'],
            'form.start_date' => ['required', 'date_format:Y-m-d\\TH:i:s,Y-m-d\\TH:i'],
            'form.end_date' => ['required', 'date_format:Y-m-d\\TH:i:s,Y-m-d\\TH:i', 'after:form.start_date'],
            'form.result_date' => ['nullable', 'date_format:Y-m-d\\TH:i:s,Y-m-d\\TH:i', 'after_or_equal:form.end_date'],
            'form.status' => ['required', Rule::enum(AdmissionRoundStatus::class)],
        ];
    }

    protected function recordForm(Model $record): array
    {
        $form = parent::recordForm($record);
        foreach (['start_date', 'end_date', 'result_date'] as $field) {
            $value = $record->getRawOriginal($field);
            $form[$field] = $value === null ? null : CarbonImmutable::parse($value, config('app.timezone'))->format('Y-m-d\\TH:i:s');
        }
        $form['status'] = $record->getAttribute('status')->value;

        return $form;
    }

    protected function attributesForSave(array $validated, ?Model $record): array
    {
        foreach (['start_date', 'end_date', 'result_date'] as $field) {
            $validated[$field] = ($validated[$field] ?? null) === null ? null
                : CarbonImmutable::parse($validated[$field], config('app.timezone'))->format('Y-m-d H:i:s');
        }

        return parent::attributesForSave($validated, $record);
    }

    /** @return Builder<AdmissionRound> */
    protected function recordsQuery(): Builder
    {
        $this->validateOnly('yearFilter', ['yearFilter' => ['nullable', 'integer', 'between:2000,2100']]);
        $this->validateOnly('statusFilter', ['statusFilter' => ['nullable', Rule::enum(AdmissionRoundStatus::class)]]);

        return AdmissionRound::query()
            ->where(function (Builder $query): void {
                $query->whereLike('code', '%'.$this->search.'%')->orWhereLike('name', '%'.$this->search.'%');
            })
            ->when($this->yearFilter !== '', fn (Builder $query) => $query->where('year', $this->yearFilter))
            ->when($this->statusFilter !== '', fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->withCount(['programs', 'applications'])->orderByDesc('year')->orderByDesc('id');
    }

    public function updatedYearFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        parent::clearFilters();
        $this->reset('yearFilter');
    }
}
