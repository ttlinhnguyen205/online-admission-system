<?php

namespace App\Livewire\Candidate;

use App\Actions\CandidateApplications;
use App\Actions\CandidateFiles;
use App\Models\CandidateExamResult;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;
use Throwable;

class NativeExamScores extends CandidatePage
{
    use WithFileUploads;

    public string $year = '';

    /** @var array<string, mixed> */
    public array $scores = ['MATH' => '', 'PHYSICS' => '', 'CHEMISTRY' => ''];

    public mixed $evidence = null;

    public function save(CandidateFiles $files): void
    {
        $this->candidate();
        $this->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'scores' => ['required', 'array:MATH,PHYSICS,CHEMISTRY'],
            'scores.MATH' => ['required', 'numeric', 'between:0,10', 'decimal:0,3'],
            'scores.PHYSICS' => ['required', 'numeric', 'between:0,10', 'decimal:0,3'],
            'scores.CHEMISTRY' => ['required', 'numeric', 'between:0,10', 'decimal:0,3'],
            'evidence' => CandidateFiles::scoreEvidenceRules(true)]);
        $path = null;
        try {
            $this->profile()->getConnection()->transaction(function () use ($files, &$path): void {
                $profile = CandidateApplications::lockProfile();
                Gate::authorize('create', [CandidateExamResult::class, $profile]);
                if ($profile->examResults()->where('exam_type', 'thpt')->where('exam_year', (int) $this->year)->exists()) {
                    throw ValidationException::withMessages(['year' => 'Đã có nguồn THPT năm này; không tạo nguồn trùng.']);
                }
                $path = $files->store($this->evidence, 'candidate-exam-results/'.$profile->id, 'evidence');
                $record = $profile->examResults()->create(['exam_type' => 'thpt', 'exam_year' => (int) $this->year,
                    'evidence_path' => $path, 'status' => 'pending']);
                foreach ($this->scores as $code => $value) {
                    $record->subjectScores()->create(['subject_code' => $code, 'subject_name' => config('admission_data.subjects.'.$code), 'score' => $value]);
                }
            });
        } catch (Throwable $exception) {
            $files->remove($path);
            throw $exception;
        }
        $this->reset('year', 'scores', 'evidence');
        Flux::toast(variant: 'success', text: 'Đã lưu nguồn THPT, chờ Admin/Staff xác minh.');
    }

    public function render(): View
    {
        return view('livewire.candidate.native-exam-scores', ['records' => $this->profile()->examResults()->where('exam_type', 'thpt')->with('subjectScores')->orderByDesc('id')->get()]);
    }
}
