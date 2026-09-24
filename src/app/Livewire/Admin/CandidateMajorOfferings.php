<?php

namespace App\Livewire\Admin;

use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\CandidateMajorOffering;
use App\Models\Major;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

#[Title('Candidate Major Offerings')]
class CandidateMajorOfferings extends ConfigurationPage
{
    #[Locked]
    public bool $relationshipsLocked = false;

    protected function modelClass(): string
    {
        return CandidateMajorOffering::class;
    }

    protected function viewName(): string
    {
        return 'livewire.admin.candidate-major-offerings';
    }

    protected function defaults(): array
    {
        return ['admission_round_id' => '', 'major_id' => '', 'admission_program_id' => null, 'is_selectable' => false];
    }

    public function create(): void
    {
        parent::create();
        $this->relationshipsLocked = false;
    }

    protected function recordForm(Model $record): array
    {
        $this->relationshipsLocked = $record instanceof CandidateMajorOffering && $record->wishes()->exists();

        return parent::recordForm($record);
    }

    protected function formRules(?Model $record): array
    {
        return [
            'form' => ['required', 'array:'.implode(',', array_keys($this->defaults()))],
            'form.admission_round_id' => ['required', 'integer', 'min:1', Rule::exists(AdmissionRound::class, 'id')],
            'form.major_id' => ['required', 'integer', 'min:1', Rule::exists(Major::class, 'id')],
            'form.admission_program_id' => ['nullable', 'required_if:form.is_selectable,1', 'integer', 'min:1', Rule::exists(AdmissionProgram::class, 'id')],
            'form.is_selectable' => ['required', 'boolean'],
        ];
    }

    protected function attributesForSave(array $validated, ?Model $record): array
    {
        if ($record instanceof CandidateMajorOffering && $record->wishes()->exists()) {
            foreach (['admission_round_id', 'major_id', 'admission_program_id'] as $field) {
                if ((int) ($validated[$field] ?? 0) !== (int) $record->getAttribute($field)) {
                    throw ValidationException::withMessages(['form.'.$field => 'This offering has wishes. Its round, major and compatibility program cannot change. Existing wishes retain their pinned program.']);
                }
            }
        }

        $program = isset($validated['admission_program_id'])
            ? AdmissionProgram::query()->whereKey($validated['admission_program_id'])->lockForUpdate()->first() : null;
        if (($validated['is_selectable'] && $program === null)
            || (isset($validated['admission_program_id']) && ($program === null
                || $program->admission_round_id !== (int) $validated['admission_round_id']
                || $program->major_id !== (int) $validated['major_id']))) {
            throw ValidationException::withMessages(['form.admission_program_id' => 'Choose an explicit compatibility program belonging to both this round and this major.']);
        }
        AdmissionRound::query()->lockForUpdate()->findOrFail($validated['admission_round_id']);
        Major::query()->lockForUpdate()->findOrFail($validated['major_id']);
        $duplicate = CandidateMajorOffering::query()
            ->where('admission_round_id', $validated['admission_round_id'])->where('major_id', $validated['major_id'])
            ->when($record !== null, fn (Builder $query) => $query->whereKeyNot($record->getKey()))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([$this->uniqueErrorField() => $this->uniqueErrorMessage()]);
        }

        return parent::attributesForSave($validated, $record);
    }

    protected function uniqueErrorField(): string
    {
        return 'form.major_id';
    }

    protected function uniqueErrorMessage(): string
    {
        return 'This major already has a candidate offering in this round.';
    }

    public function updatedFormAdmissionRoundId(): void
    {
        $this->form['admission_program_id'] = null;
    }

    public function updatedFormMajorId(): void
    {
        $this->form['admission_program_id'] = null;
    }

    /** @return Builder<CandidateMajorOffering> */
    protected function recordsQuery(): Builder
    {
        $this->validateOnly('statusFilter', ['statusFilter' => ['nullable', Rule::in(['enabled', 'disabled'])]]);

        return CandidateMajorOffering::query()->with(['admissionRound', 'major', 'admissionProgram.admissionMethod'])
            ->where(fn (Builder $query) => $query
                ->whereHas('major', fn (Builder $major) => $major->whereLike('name', '%'.$this->search.'%')->orWhereLike('code', '%'.$this->search.'%'))
                ->orWhereHas('admissionRound', fn (Builder $round) => $round->whereLike('name', '%'.$this->search.'%')->orWhereLike('code', '%'.$this->search.'%')))
            ->when($this->statusFilter !== '', fn (Builder $query) => $query->where('is_selectable', $this->statusFilter === 'enabled'))
            ->orderByDesc('id');
    }

    protected function viewData(): array
    {
        $round = $this->form['admission_round_id'] ?? null;
        $major = $this->form['major_id'] ?? null;

        return [
            'rounds' => AdmissionRound::query()->orderByDesc('year')->orderBy('id')->get(),
            'majors' => Major::query()->orderBy('name')->orderBy('id')->get(),
            'programs' => is_scalar($round) && is_scalar($major)
                ? AdmissionProgram::query()->where('admission_round_id', (int) $round)->where('major_id', (int) $major)
                    ->with('admissionMethod')->orderBy('id')->get()
                : collect(),
        ];
    }
}
