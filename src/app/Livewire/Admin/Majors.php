<?php

namespace App\Livewire\Admin;

use App\Models\Major;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;

#[Title('Majors')]
class Majors extends ConfigurationPage
{
    protected function modelClass(): string
    {
        return Major::class;
    }

    protected function viewName(): string
    {
        return 'livewire.admin.majors';
    }

    protected function defaults(): array
    {
        return ['code' => '', 'name' => '', 'description' => null, 'default_tuition_fee' => null, 'is_active' => true];
    }

    protected function formRules(?Model $record): array
    {
        return [
            ...$this->namedRules($record),
            'form.description' => ['nullable', 'string', 'max:10000'],
            'form.default_tuition_fee' => $this->feeRules(),
            'form.is_active' => ['required', 'boolean'],
        ];
    }

    /** @return Builder<Major> */
    protected function recordsQuery(): Builder
    {
        $this->validateOnly('statusFilter', ['statusFilter' => ['nullable', Rule::in(['active', 'inactive'])]]);

        return Major::query()->where(function (Builder $query): void {
            $query->whereLike('code', '%'.$this->search.'%')->orWhereLike('name', '%'.$this->search.'%');
        })->when($this->statusFilter !== '', fn (Builder $query) => $query->where('is_active', $this->statusFilter === 'active'))
            ->withCount('programs')->orderBy('name')->orderBy('id');
    }
}
