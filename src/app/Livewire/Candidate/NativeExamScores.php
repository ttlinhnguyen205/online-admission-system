<?php

namespace App\Livewire\Candidate;

use App\Actions\CandidateApplications;
use App\Actions\CandidateFiles;
use App\Models\CandidateExamResult;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Throwable;

class NativeExamScores extends CandidatePage
{
    use WithFileUploads;

    #[Locked]
    public ?int $recordId = null;

    public string $year = '';

    /** @var array<string, mixed> */
    public array $scores = [];

    public mixed $evidence = null;

    public function toggleSubject(string $code): void
    {
        abort_unless(array_key_exists($code, config('admission_data.subjects')), 422);
        if (array_key_exists($code, $this->scores)) {
            unset($this->scores[$code]);
        } else {
            $this->scores[$code] = '';
        }
        $this->resetValidation(['scores', 'scores.'.$code]);
    }

    public function edit(int $id): void
    {
        $record = $this->profile()->examResults()->where('exam_type', 'thpt')->with('subjectScores')->findOrFail($id);
        Gate::authorize('update', $record);
        $this->resetEditor();
        $this->recordId = $record->id;
        $this->year = (string) $record->exam_year;
        $this->scores = $record->subjectScores->pluck('score', 'subject_code')->all();
    }

    public function resetEditor(): void
    {
        $this->reset('recordId', 'year', 'scores', 'evidence');
        $this->resetValidation();
    }

    public function save(CandidateFiles $files): void
    {
        $this->candidate();
        $newPath = $oldPath = null;
        try {
            $this->profile()->getConnection()->transaction(function () use ($files, &$newPath, &$oldPath): void {
                $profile = CandidateApplications::lockProfile();
                $record = $this->recordId === null ? null : $profile->examResults()->where('exam_type', 'thpt')->lockForUpdate()->findOrFail($this->recordId);
                Gate::authorize($record === null ? 'create' : 'update', $record === null ? [CandidateExamResult::class, $profile] : $record);
                $subjects = config('admission_data.subjects');
                $this->validate([
                    'year' => ['required', 'integer', 'between:2000,2100'],
                    'scores' => ['required', 'array:'.implode(',', array_keys($subjects)), 'min:1'],
                    'scores.*' => ['required', 'numeric', 'between:0,10', 'decimal:0,3'],
                    'evidence' => CandidateFiles::scoreEvidenceRules($record === null || $record->evidence_path === null),
                ], [
                    'scores.required' => 'Vui lòng chọn ít nhất một môn đã thi và nhập điểm.',
                    'scores.*.required' => 'Vui lòng nhập điểm cho môn đã chọn hoặc bỏ chọn môn không thi.',
                ], [
                    'year' => 'năm thi',
                    'scores' => 'điểm các môn đã thi',
                    ...collect($subjects)->mapWithKeys(fn (string $label, string $code): array => ['scores.'.$code => 'điểm môn '.$label])->all(),
                ]);
                $duplicates = $profile->examResults()->where('exam_type', 'thpt')->where('exam_year', (int) $this->year);
                if ($record !== null) {
                    $duplicates->where('id', '<>', $record->id);
                }
                if ($duplicates->exists()) {
                    throw ValidationException::withMessages(['year' => 'Bạn đã khai báo điểm thi THPT năm này. Vui lòng chỉnh sửa bản ghi hiện có.']);
                }
                $record ??= $profile->examResults()->make(['exam_type' => 'thpt']);
                if ($this->evidence instanceof UploadedFile) {
                    $oldPath = $record->evidence_path;
                    $newPath = $files->store($this->evidence, 'candidate-exam-results/'.$profile->id, 'evidence');
                    $record->evidence_path = $newPath;
                }
                $record->fill(['exam_year' => (int) $this->year, 'status' => 'pending', 'verified_by' => null, 'verified_at' => null, 'rejection_reason' => null]);
                if (! $record->save()) {
                    throw ValidationException::withMessages(['evidence' => 'Không thể lưu điểm và minh chứng. Vui lòng thử lại.']);
                }
                $record->subjectScores()->delete();
                foreach ($this->scores as $code => $value) {
                    $score = $record->subjectScores()->make(['subject_code' => $code, 'subject_name' => $subjects[$code], 'score' => $value]);
                    if (! $score->save()) {
                        throw ValidationException::withMessages(['scores' => 'Không thể lưu điểm. Vui lòng thử lại.']);
                    }
                }
            });
        } catch (Throwable $exception) {
            $files->remove($newPath);
            throw $exception;
        }
        $this->resetEditor();
        if (! $files->remove($oldPath)) {
            $this->addError('evidence', 'Điểm đã được lưu nhưng chưa thể xóa minh chứng cũ. Vui lòng liên hệ bộ phận tuyển sinh.');
        }
        Flux::toast(variant: 'success', text: 'Đã lưu điểm và minh chứng THPT. Hãy gửi hồ sơ xét duyệt trong mục Đăng ký nguyện vọng để cán bộ tiếp nhận.');
    }

    public function render(): View
    {
        return view('livewire.candidate.native-exam-scores', [
            'records' => $this->profile()->examResults()->where('exam_type', 'thpt')->with('subjectScores')->orderByDesc('exam_year')->orderByDesc('id')->get(),
            'subjects' => config('admission_data.subjects'),
        ]);
    }
}
