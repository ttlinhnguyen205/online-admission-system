<?php

namespace App\Livewire\Candidate;

use App\Actions\CandidateApplications;
use App\Models\CandidateScore;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

#[Title('Điểm xét tuyển')]
class Scores extends CandidatePage
{
    use WithPagination;

    private const FIELDS = ['score_type', 'subject_code', 'subject_name', 'score', 'exam_year'];

    /** @var array<string, mixed> */
    public array $form = [];

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public ?int $deleteId = null;

    public bool $showEditor = false;

    public bool $showDeletion = false;

    public string $typeFilter = '';

    public string $yearFilter = '';

    public function create(): void
    {
        Gate::authorize('create', [CandidateScore::class, $this->profile()]);
        $this->resetValidation();
        $this->recordId = null;
        $this->form = ['score_type' => '', 'subject_code' => null, 'subject_name' => null, 'score' => '', 'exam_year' => now()->year];
        $this->showEditor = true;
    }

    public function edit(int $id): void
    {
        $score = $this->profile()->scores()->findOrFail($id);
        Gate::authorize('update', $score);
        $this->resetValidation();
        $this->recordId = $score->getKey();
        $this->form = $score->only(self::FIELDS);
        $this->showEditor = true;
    }

    public function save(): void
    {
        $profile = $this->profile();
        $profile->getConnection()->transaction(function (): void {
            $profile = CandidateApplications::lockProfile();
            $score = $this->recordId === null ? null : $profile->scores()->lockForUpdate()->findOrFail($this->recordId);
            abort_if($score?->getAttribute('verified'), 403);
            Gate::authorize($score === null ? 'create' : 'update', $score === null ? [CandidateScore::class, $profile] : $score);
            $this->form = $this->normalize($this->form);
            if (is_string($this->form['score_type'] ?? null)) {
                $this->form['score_type'] = Str::lower($this->form['score_type']);
            }
            $validated = $this->validate([
                'form' => ['required', 'array:'.implode(',', self::FIELDS)],
                'form.score_type' => ['required', 'string', 'max:30'],
                'form.subject_code' => ['nullable', 'string', 'max:30'],
                'form.subject_name' => ['nullable', 'string', 'max:100'],
                'form.score' => ['required', 'numeric', 'regex:/\A[0-9]+(?:\.[0-9]{1,3})?\z/D', 'decimal:0,3', 'between:0,99999.999'],
                'form.exam_year' => ['required', 'integer', 'between:1900,'.now()->year],
            ]);
            $attributes = array_intersect_key($validated['form'], array_flip(self::FIELDS));
            Gate::authorize($score === null ? 'create' : 'update', $score === null
                ? [CandidateScore::class, $profile, $attributes] : [$score, $attributes]);
            $score ??= $profile->scores()->make();
            $score->fill($attributes);
            if (! $score->save()) {
                throw ValidationException::withMessages(['form' => __('Không thể lưu điểm.')]);
            }
        });
        $this->showEditor = false;
        $this->resetPage();
        Flux::toast(variant: 'success', text: __('Đã lưu điểm.'));
    }

    public function confirmDeletion(int $id): void
    {
        $score = $this->profile()->scores()->findOrFail($id);
        Gate::authorize('delete', $score);
        $this->resetValidation();
        $this->deleteId = $score->getKey();
        $this->showDeletion = true;
    }

    public function delete(): void
    {
        $profile = $this->profile();
        abort_if($this->deleteId === null, 404);
        $profile->getConnection()->transaction(function (): void {
            $profile = CandidateApplications::lockProfile();
            $score = $profile->scores()->lockForUpdate()->findOrFail($this->deleteId);
            abort_if($score->getAttribute('verified'), 403);
            Gate::authorize('delete', $score);
            if (! $score->delete()) {
                throw ValidationException::withMessages(['deletion' => __('Không thể xóa điểm.')]);
            }
        });
        $this->showDeletion = false;
        $this->deleteId = null;
        $this->resetPage();
        Flux::toast(variant: 'success', text: __('Đã xóa điểm.'));
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedYearFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $profile = $this->candidate()->candidateProfile()->first();
        $this->validateOnly('typeFilter', ['typeFilter' => ['string', 'max:30']]);
        $this->validateOnly('yearFilter', ['yearFilter' => ['nullable', 'integer', 'between:1900,65535']]);
        if ($profile !== null) {
            Gate::authorize('view', $profile);
        }
        $records = $profile?->scores()
            ->when($this->typeFilter !== '', fn ($query) => $query->where('score_type', Str::lower(trim($this->typeFilter))))
            ->when($this->yearFilter !== '', fn ($query) => $query->where('exam_year', $this->yearFilter))
            ->orderByDesc('exam_year')->orderByDesc('id')->paginate(15);

        return view('livewire.candidate.scores', compact('profile', 'records'));
    }
}
