<?php

namespace App\Livewire\Candidate;

use App\Actions\AdmissionReviewSnapshot;
use App\Actions\CandidateApplications;
use App\Actions\CandidateFiles;
use App\Models\CandidateScore;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

#[Title('Điểm & minh chứng')]
class Scores extends CandidatePage
{
    use WithFileUploads, WithPagination;

    public const SCORE_TYPES = [
        'thpt' => 'THPT',
        'hoc_ba' => 'Học bạ',
        'dgnl' => 'ĐGNL',
        'ielts' => 'IELTS',
        'sat' => 'SAT',
    ];

    private const FIELDS = ['score_type', 'subject_code', 'subject_name', 'score', 'exam_year'];

    /** @var array<string, mixed> */
    public array $form = [];

    public mixed $evidence = null;

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
        $this->evidence = null;
        $this->showEditor = true;
    }

    public function edit(int $id): void
    {
        $score = $this->profile()->scores()->findOrFail($id);
        Gate::authorize('update', $score);
        $this->resetValidation();
        $this->recordId = $score->getKey();
        $this->form = $score->only(self::FIELDS);
        $this->evidence = null;
        $this->showEditor = true;
    }

    public function save(CandidateFiles $files): void
    {
        $profile = $this->profile();
        $newPath = null;
        $oldPath = null;
        try {
            $profile->getConnection()->transaction(function () use ($files, &$newPath, &$oldPath): void {
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
                    'form.score_type' => ['required', 'string', Rule::in(array_keys(self::SCORE_TYPES))],
                    'form.subject_code' => [Rule::requiredIf(in_array($this->form['score_type'] ?? null, ['thpt', 'hoc_ba'], true)), 'nullable', 'string', 'max:30'],
                    'form.subject_name' => ['nullable', 'string', 'max:100'],
                    'form.score' => ['required', 'numeric', 'regex:/\A[0-9]+(?:\.[0-9]{1,3})?\z/D', 'decimal:0,3', 'between:0,99999.999'],
                    'form.exam_year' => ['required', 'integer', 'between:1900,'.now()->year],
                    'evidence' => CandidateFiles::scoreEvidenceRules($score === null),
                ], [
                    'form.score_type.required' => __('Vui lòng chọn loại điểm.'),
                    'form.score_type.in' => __('Loại điểm không được hỗ trợ.'),
                    'form.subject_code.required' => __('Vui lòng nhập mã môn cho điểm THPT hoặc Học bạ.'),
                    'evidence.required' => __('Vui lòng tải ảnh minh chứng khi thêm điểm.'),
                    'evidence.file' => __('Ảnh minh chứng phải là tệp hợp lệ.'),
                    'evidence.image' => __('Ảnh minh chứng phải là ảnh JPG, JPEG hoặc PNG.'),
                    'evidence.mimes' => __('Ảnh minh chứng phải là ảnh JPG, JPEG hoặc PNG.'),
                    'evidence.extensions' => __('Ảnh minh chứng phải có đuôi JPG, JPEG hoặc PNG.'),
                    'evidence.max' => __('Ảnh minh chứng không được vượt quá 2 MiB.'),
                ]);
                $attributes = array_intersect_key($validated['form'], array_flip(self::FIELDS));
                Gate::authorize($score === null ? 'create' : 'update', $score === null
                    ? [CandidateScore::class, $profile, $attributes] : [$score, $attributes]);
                $score ??= $profile->scores()->make();
                $score->fill($attributes);
                if ($this->evidence instanceof UploadedFile) {
                    $oldPath = $score->getAttribute('evidence_path');
                    $newPath = $files->store($this->evidence, 'candidate-scores/'.$profile->getKey(), 'evidence');
                    $score->setAttribute('evidence_path', $newPath);
                }
                if (! $score->save()) {
                    throw ValidationException::withMessages(['form' => __('Không thể lưu điểm.')]);
                }
            });
        } catch (Throwable $exception) {
            $files->remove($newPath);
            throw $exception;
        }
        $this->evidence = null;
        $this->showEditor = false;
        $this->resetPage();
        if (! $files->remove($oldPath)) {
            $this->addError('cleanup', __('Đã lưu điểm nhưng không thể xóa ảnh minh chứng cũ. Vui lòng liên hệ bộ phận hỗ trợ.'));
        }
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

    public function delete(CandidateFiles $files): void
    {
        $profile = $this->profile();
        abort_if($this->deleteId === null, 404);
        $path = $profile->getConnection()->transaction(function (): ?string {
            $profile = CandidateApplications::lockProfile();
            $score = $profile->scores()->lockForUpdate()->findOrFail($this->deleteId);
            abort_if($score->getAttribute('verified'), 403);
            Gate::authorize('delete', $score);
            $path = $score->getAttribute('evidence_path');
            if (! $score->delete()) {
                throw ValidationException::withMessages(['deletion' => __('Không thể xóa điểm.')]);
            }

            return $path;
        });
        $this->showDeletion = false;
        $this->deleteId = null;
        $this->resetPage();
        if (! $files->remove($path)) {
            $this->addError('cleanup', __('Đã xóa điểm nhưng không thể dọn ảnh minh chứng. Vui lòng liên hệ bộ phận hỗ trợ.'));

            return;
        }
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

    public function resetFilters(): void
    {
        $this->typeFilter = '';
        $this->yearFilter = '';
        $this->resetPage();
    }

    public function render(): View
    {
        $profile = $this->candidate()->candidateProfile()->first();
        if ($profile !== null) {
            Gate::authorize('view', $profile);
        }

        $availableYears = $profile?->scores()->select('exam_year')->distinct()->orderByDesc('exam_year')->pluck('exam_year') ?? collect();
        $validType = $this->typeFilter === '' || array_key_exists($this->typeFilter, self::SCORE_TYPES);
        $validYear = $this->yearFilter === '' || $availableYears->contains(fn ($year) => (string) $year === $this->yearFilter);
        $records = $profile?->scores()
            ->when(! $validType || ! $validYear, fn ($query) => $query->whereKey([]))
            ->when($this->typeFilter !== '' && $validType, fn ($query) => $query->where('score_type', $this->typeFilter))
            ->when($this->yearFilter !== '' && $validYear, fn ($query) => $query->where('exam_year', $this->yearFilter))
            ->orderByDesc('exam_year')->orderByDesc('id')->paginate(15);
        $evidenceAvailability = $records?->getCollection()->mapWithKeys(fn ($score) => [
            $score->getKey() => (new AdmissionReviewSnapshot)->fileAvailable($score->getAttribute('evidence_path'), scoreEvidence: true),
        ]) ?? collect();

        return view('livewire.candidate.scores', compact('profile', 'records', 'evidenceAvailability', 'availableYears'));
    }
}
