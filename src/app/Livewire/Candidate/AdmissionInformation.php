<?php

namespace App\Livewire\Candidate;

use App\Actions\CandidateApplications;
use App\Actions\CandidateFiles;
use App\Enums\VerificationStatus;
use App\Models\CandidateAdmissionClaim;
use App\Models\CandidateCertificate;
use App\Models\CandidateExamResult;
use App\Models\CandidateTranscript;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

#[Title('Thông tin tuyển sinh')]
class AdmissionInformation extends CandidatePage
{
    use WithFileUploads;

    /** @var array<string, mixed> */
    public array $certificateForm = [];

    /** @var array<string, mixed> */
    public array $claimForm = [];

    /** @var array<string, mixed> */
    public array $thptForm = [];

    /** @var array<string, mixed> */
    public array $competencyForm = [];

    /** @var array<string, mixed> */
    public array $transcriptForm = [];

    public mixed $certificateEvidence = null;

    public mixed $claimEvidence = null;

    public mixed $thptEvidence = null;

    public mixed $competencyEvidence = null;

    /** @var list<TemporaryUploadedFile> */
    public array $transcriptEvidence = [];

    /** @var list<int> */
    public array $removedTranscriptEvidence = [];

    public bool $removeLegacyTranscriptEvidence = false;

    /** @var array<string, bool> */
    public array $claimSelections = ['direct_admission' => false, 'priority_admission' => false];

    /** @var array<string, array<string, mixed>> */
    public array $declarations = [];

    /** @var array<string, mixed> */
    public array $declarationEvidence = [];

    #[Locked]
    public ?int $certificateId = null;

    #[Locked]
    public ?int $claimId = null;

    #[Locked]
    public ?int $thptId = null;

    #[Locked]
    public ?int $competencyId = null;

    #[Locked]
    public ?int $transcriptId = null;

    public bool $showCertificateEditor = false;

    public bool $showClaimEditor = false;

    public bool $showThptEditor = false;

    public bool $showCompetencyEditor = false;

    public bool $showTranscriptEditor = false;

    public function mount(): void
    {
        $profile = $this->candidate()->candidateProfile()->first();
        if ($profile === null) {
            return;
        }
        $certificate = $profile->certificates()->first();
        if ($certificate === null) {
            $this->createCertificate();
        } elseif ($certificate->status !== VerificationStatus::Verified) {
            $this->editCertificate($certificate->id);
        }
        $exam = $profile->examResults()->whereIn('exam_type', $this->competencyTypes())->first();
        if ($exam === null) {
            $this->createCompetency();
        } elseif ($exam->status !== VerificationStatus::Verified) {
            $this->editCompetency($exam->id);
        }
        foreach (array_keys($this->claimSelections) as $type) {
            $claim = $profile->admissionClaims()->where('claim_type', $type)->first();
            $this->claimSelections[$type] = $claim !== null;
            $this->declarations[$type] = $claim?->only(['claim_code', 'description'])
                ?? ['claim_code' => null, 'description' => null];
        }
    }

    public function saveDeclaration(string $type, CandidateFiles $files): void
    {
        abort_unless(in_array($type, ['direct_admission', 'priority_admission'], true), 404);
        abort_unless($this->claimSelections[$type] ?? false, 403);
        $this->validate([
            'declarations.'.$type => ['required', 'array:claim_code,description'],
            'declarations.'.$type.'.claim_code' => ['nullable', 'string', 'max:100'],
            'declarations.'.$type.'.description' => ['nullable', 'string', 'max:5000'],
        ]);
        $record = $this->profile()->admissionClaims()->where('claim_type', $type)->first();
        $this->claimId = $record?->id;
        $this->claimForm = ['claim_type' => $type, ...$this->declarations[$type]];
        $this->claimEvidence = $this->declarationEvidence[$type] ?? null;
        try {
            $this->saveClaim($files);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
                fn (array $messages, string $key): array => [
                    str_replace(['claimEvidence', 'claimForm.'], ['declarationEvidence.'.$type, 'declarations.'.$type.'.'], $key) => $messages,
                ]
            )->all());
        }
        unset($this->declarationEvidence[$type]);
    }

    public function createCertificate(): void
    {
        $existing = $this->profile()->certificates()->first();
        if ($existing !== null) {
            $this->editCertificate($existing->id);

            return;
        }
        Gate::authorize('create', [CandidateCertificate::class, $this->profile()]);
        $this->resetValidation();
        $this->certificateId = null;
        $this->certificateEvidence = null;
        $this->certificateForm = [
            'certificate_type' => '', 'score' => '', 'certificate_number' => null,
            'issued_at' => null, 'expires_at' => null,
        ];
        $this->showCertificateEditor = true;
    }

    public function editCertificate(int $id): void
    {
        $record = $this->profile()->certificates()->findOrFail($id);
        Gate::authorize('update', $record);
        $this->certificateId = $record->getKey();
        $this->certificateEvidence = null;
        $this->certificateForm = $record->only(['certificate_type', 'score', 'certificate_number', 'issued_at', 'expires_at']);
        $this->certificateForm['certificate_type'] = $record->getRawOriginal('certificate_type');
        $this->certificateForm['issued_at'] = $record->getRawOriginal('issued_at');
        $this->certificateForm['expires_at'] = $record->getRawOriginal('expires_at');
        $this->showCertificateEditor = true;
    }

    public function saveCertificate(CandidateFiles $files): void
    {
        $this->certificateForm = $this->normalize($this->certificateForm);
        $type = $this->certificateForm['certificate_type'] ?? null;
        $validated = $this->validate([
            'certificateForm' => ['required', 'array:certificate_type,score,certificate_number,issued_at,expires_at'],
            'certificateForm.certificate_type' => ['required', Rule::in(array_keys(config('admission_data.certificate_types')))],
            'certificateForm.score' => $this->scoreRules('certificate_types', is_string($type) ? $type : ''),
            'certificateForm.certificate_number' => ['nullable', 'string', 'max:100'],
            'certificateForm.issued_at' => ['nullable', 'date'],
            'certificateForm.expires_at' => ['nullable', 'date'],
            'certificateEvidence' => CandidateFiles::scoreEvidenceRules($this->certificateId === null),
        ], $this->evidenceMessages('certificateEvidence'));

        $this->saveParent(
            CandidateCertificate::class,
            'certificates',
            $this->certificateId,
            $validated['certificateForm'],
            $this->certificateEvidence,
            'candidate-certificates',
            $files,
        );
        $this->certificateEvidence = null;
        $this->showCertificateEditor = false;
        Flux::toast(variant: 'success', text: __('Đã lưu chứng chỉ.'));
    }

    public function deleteCertificate(int $id, CandidateFiles $files): void
    {
        $this->deleteParent('certificates', $id, $files);
        Flux::toast(variant: 'success', text: __('Đã xóa chứng chỉ.'));
    }

    public function createClaim(): void
    {
        Gate::authorize('create', [CandidateAdmissionClaim::class, $this->profile()]);
        $this->resetValidation();
        $this->claimId = null;
        $this->claimEvidence = null;
        $this->claimForm = ['claim_type' => '', 'claim_code' => null, 'description' => null];
        $this->showClaimEditor = true;
    }

    public function editClaim(int $id): void
    {
        $record = $this->profile()->admissionClaims()->findOrFail($id);
        Gate::authorize('update', $record);
        $this->claimId = $record->getKey();
        $this->claimEvidence = null;
        $this->claimForm = $record->only(['claim_type', 'claim_code', 'description']);
        $this->showClaimEditor = true;
    }

    public function saveClaim(CandidateFiles $files): void
    {
        $this->claimForm = $this->normalize($this->claimForm);
        $validated = $this->validate([
            'claimForm' => ['required', 'array:claim_type,claim_code,description'],
            'claimForm.claim_type' => ['required', 'string', 'max:100'],
            'claimForm.claim_code' => ['nullable', 'string', 'max:100'],
            'claimForm.description' => ['nullable', 'string', 'max:5000'],
            'claimEvidence' => CandidateFiles::scoreEvidenceRules($this->claimId === null),
        ], $this->evidenceMessages('claimEvidence'));

        $this->saveParent(
            CandidateAdmissionClaim::class,
            'admissionClaims',
            $this->claimId,
            $validated['claimForm'],
            $this->claimEvidence,
            'candidate-admission-claims',
            $files,
        );
        $this->claimEvidence = null;
        $this->showClaimEditor = false;
        Flux::toast(variant: 'success', text: __('Đã lưu thông tin xét tuyển thẳng hoặc ưu tiên xét tuyển.'));
    }

    public function deleteClaim(int $id, CandidateFiles $files): void
    {
        $this->deleteParent('admissionClaims', $id, $files);
        Flux::toast(variant: 'success', text: __('Đã xóa thông tin xét tuyển thẳng hoặc ưu tiên xét tuyển.'));
    }

    public function createThpt(): void
    {
        Gate::authorize('create', [CandidateExamResult::class, $this->profile()]);
        $this->resetValidation();
        $this->thptId = null;
        $this->thptEvidence = null;
        $this->thptForm = [
            'exam_year' => now()->year, 'exam_date' => null, 'registration_number' => null,
            'subjects' => [['subject_code' => '', 'score' => '']],
        ];
        $this->showThptEditor = true;
    }

    public function editThpt(int $id): void
    {
        $record = $this->profile()->examResults()->where('exam_type', 'thpt')->with('subjectScores')->findOrFail($id);
        Gate::authorize('update', $record);
        $this->thptId = $record->getKey();
        $this->thptEvidence = null;
        $this->thptForm = [
            'exam_year' => $record->exam_year,
            'exam_date' => $record->getRawOriginal('exam_date'),
            'registration_number' => $record->registration_number,
            'subjects' => $record->subjectScores->map(fn ($score): array => [
                'subject_code' => $score->subject_code, 'score' => $score->score,
            ])->all(),
        ];
        $this->showThptEditor = true;
    }

    public function addThptSubject(): void
    {
        $this->thptForm['subjects'][] = ['subject_code' => '', 'score' => ''];
    }

    public function removeThptSubject(int $index): void
    {
        unset($this->thptForm['subjects'][$index]);
        $this->thptForm['subjects'] = array_values($this->thptForm['subjects']);
    }

    public function saveThpt(CandidateFiles $files): void
    {
        $validated = $this->validate([
            'thptForm' => ['required', 'array:exam_year,exam_date,registration_number,subjects'],
            'thptForm.exam_year' => ['required', 'integer', 'between:1900,'.now()->year],
            'thptForm.exam_date' => ['nullable', 'date'],
            'thptForm.registration_number' => ['nullable', 'string', 'max:100'],
            'thptForm.subjects' => ['required', 'array', 'min:1'],
            'thptForm.subjects.*' => ['required', 'array:subject_code,score'],
            'thptForm.subjects.*.subject_code' => ['required', Rule::in(array_keys(config('admission_data.subjects'))), 'distinct'],
            'thptForm.subjects.*.score' => $this->scoreRules(),
            'thptEvidence' => CandidateFiles::scoreEvidenceRules($this->thptId === null),
        ], $this->evidenceMessages('thptEvidence'));

        $subjects = $validated['thptForm']['subjects'];
        unset($validated['thptForm']['subjects']);
        $validated['thptForm']['exam_type'] = 'thpt';
        $this->saveExam($this->thptId, $validated['thptForm'], $subjects, $this->thptEvidence, $files);
        $this->thptEvidence = null;
        $this->showThptEditor = false;
        Flux::toast(variant: 'success', text: __('Đã lưu điểm thi tốt nghiệp THPT.'));
    }

    public function deleteThpt(int $id, CandidateFiles $files): void
    {
        $this->deleteParent('examResults', $id, $files, 'thpt');
        Flux::toast(variant: 'success', text: __('Đã xóa kết quả thi THPT.'));
    }

    public function createCompetency(): void
    {
        $existing = $this->profile()->examResults()->whereIn('exam_type', $this->competencyTypes())->first();
        if ($existing !== null) {
            $this->editCompetency($existing->id);

            return;
        }
        Gate::authorize('create', [CandidateExamResult::class, $this->profile()]);
        $this->resetValidation();
        $this->competencyId = null;
        $this->competencyEvidence = null;
        $this->competencyForm = [
            'exam_type' => '', 'overall_score' => '', 'exam_year' => now()->year, 'exam_date' => null,
            'exam_session' => null, 'registration_number' => null,
        ];
        $this->showCompetencyEditor = true;
    }

    public function editCompetency(int $id): void
    {
        $record = $this->profile()->examResults()->whereIn('exam_type', $this->competencyTypes())->findOrFail($id);
        Gate::authorize('update', $record);
        $this->competencyId = $record->getKey();
        $this->competencyEvidence = null;
        $this->competencyForm = $record->only([
            'exam_type', 'overall_score', 'exam_year', 'exam_date', 'exam_session', 'registration_number',
        ]);
        $this->competencyForm['exam_type'] = $record->getRawOriginal('exam_type');
        $this->competencyForm['exam_date'] = $record->getRawOriginal('exam_date');
        $this->showCompetencyEditor = true;
    }

    public function saveCompetency(CandidateFiles $files): void
    {
        $this->competencyForm = $this->normalize($this->competencyForm);
        $type = $this->competencyForm['exam_type'] ?? null;
        $validated = $this->validate([
            'competencyForm' => ['required', 'array:exam_type,overall_score,exam_year,exam_date,exam_session,registration_number'],
            'competencyForm.exam_type' => ['required', Rule::in($this->competencyTypes())],
            'competencyForm.overall_score' => $this->scoreRules('exam_types', is_string($type) ? $type : ''),
            'competencyForm.exam_year' => ['required', 'integer', 'between:1900,'.now()->year],
            'competencyForm.exam_date' => ['nullable', 'date'],
            'competencyForm.exam_session' => ['nullable', 'string', 'max:100'],
            'competencyForm.registration_number' => ['nullable', 'string', 'max:100'],
            'competencyEvidence' => CandidateFiles::scoreEvidenceRules($this->competencyId === null),
        ], $this->evidenceMessages('competencyEvidence'));

        $this->saveExam($this->competencyId, $validated['competencyForm'], [], $this->competencyEvidence, $files);
        $this->competencyEvidence = null;
        $this->showCompetencyEditor = false;
        Flux::toast(variant: 'success', text: __('Đã lưu kết quả kỳ thi.'));
    }

    public function deleteCompetency(int $id, CandidateFiles $files): void
    {
        $this->deleteParent('examResults', $id, $files, $this->competencyTypes());
        Flux::toast(variant: 'success', text: __('Đã xóa kết quả kỳ thi.'));
    }

    public function createTranscript(): void
    {
        Gate::authorize('create', [CandidateTranscript::class, $this->profile()]);
        $this->resetValidation();
        $this->transcriptId = null;
        $this->transcriptEvidence = [];
        $this->removedTranscriptEvidence = [];
        $this->removeLegacyTranscriptEvidence = false;
        $this->transcriptForm = [
            'school_name' => $this->profile()->high_school_name,
            'graduation_year' => $this->profile()->graduation_year ?? now()->year,
            'subjects' => $this->blankTranscriptSubjects(),
        ];
        $this->showTranscriptEditor = true;
    }

    public function editTranscript(int $id): void
    {
        $record = $this->profile()->transcripts()->with('scores')->findOrFail($id);
        Gate::authorize('update', $record);
        $this->transcriptId = $record->getKey();
        $this->transcriptEvidence = [];
        $this->removedTranscriptEvidence = [];
        $this->removeLegacyTranscriptEvidence = false;
        $rows = $this->blankTranscriptSubjects();
        foreach ($rows as &$row) {
            foreach ($record->scores->where('subject_code', $row['subject_code']) as $score) {
                $row['grade_'.$score->grade_level] = $score->score;
            }
        }
        unset($row);
        $this->transcriptForm = [
            'school_name' => $record->school_name,
            'graduation_year' => $record->graduation_year,
            'subjects' => $rows,
        ];
        $this->showTranscriptEditor = true;
    }

    /** @return list<array{subject_code: string, grade_10: null, grade_11: null, grade_12: null}> */
    private function blankTranscriptSubjects(): array
    {
        $subjects = [];
        foreach (config('admission_data.subjects') as $code => $label) {
            if (! is_string($code)) {
                throw new \UnexpectedValueException('Transcript subject codes must be canonical strings.');
            }
            $subjects[] = ['subject_code' => $code, 'grade_10' => null, 'grade_11' => null, 'grade_12' => null];
        }

        return $subjects;
    }

    public function updatedTranscriptEvidence(): void
    {
        $this->validate(['transcriptEvidence.*' => CandidateFiles::scoreEvidenceRules(true)], $this->evidenceMessages('transcriptEvidence.*'));
    }

    public function saveTranscript(CandidateFiles $files): void
    {
        $validated = $this->validate([
            'transcriptForm' => ['required', 'array:school_name,graduation_year,subjects'],
            'transcriptForm.school_name' => ['nullable', 'string', 'max:255'],
            'transcriptForm.graduation_year' => ['required', 'integer', 'between:1900,'.(now()->year + 1)],
            'transcriptForm.subjects' => ['required', 'array', 'min:1'],
            'transcriptForm.subjects.*' => ['required', 'array:subject_code,grade_10,grade_11,grade_12'],
            'transcriptForm.subjects.*.subject_code' => ['required', Rule::in(array_keys(config('admission_data.subjects'))), 'distinct'],
            'transcriptForm.subjects.*.grade_10' => $this->optionalScoreRules(),
            'transcriptForm.subjects.*.grade_11' => $this->optionalScoreRules(),
            'transcriptForm.subjects.*.grade_12' => $this->optionalScoreRules(),
            'transcriptEvidence' => ['array'],
            'transcriptEvidence.*' => CandidateFiles::scoreEvidenceRules(true),
            'removedTranscriptEvidence' => ['array'],
            'removedTranscriptEvidence.*' => ['integer', 'distinct'],
            'removeLegacyTranscriptEvidence' => ['boolean'],
        ], $this->evidenceMessages('transcriptEvidence.*'));
        $rows = $this->transcriptRows($validated['transcriptForm']['subjects']);
        if ($rows === []) {
            throw ValidationException::withMessages([
                'transcriptForm.subjects' => __('Vui lòng nhập ít nhất một điểm học bạ.'),
            ]);
        }
        unset($validated['transcriptForm']['subjects']);
        $this->saveTranscriptRecord($this->transcriptId, $validated['transcriptForm'], $rows, $this->transcriptEvidence, $files);
        $this->transcriptEvidence = [];
        $this->removedTranscriptEvidence = [];
        $this->removeLegacyTranscriptEvidence = false;
        $this->showTranscriptEditor = false;
        Flux::toast(variant: 'success', text: __('Đã lưu điểm học bạ.'));
    }

    public function deleteTranscript(int $id, CandidateFiles $files): void
    {
        $this->deleteParent('transcripts', $id, $files);
        Flux::toast(variant: 'success', text: __('Đã xóa thông tin học bạ.'));
    }

    public function render(): View
    {
        $profile = $this->candidate()->candidateProfile()->first();
        if ($profile !== null) {
            Gate::authorize('view', $profile);
        }

        return view('livewire.candidate.admission-information', [
            'profile' => $profile,
            'certificates' => $profile?->certificates()->latest('id')->get() ?? collect(),
            'claims' => $profile?->admissionClaims()->latest('id')->get() ?? collect(),
            'competencyResults' => $profile?->examResults()->whereIn('exam_type', $this->competencyTypes())->latest('id')->get() ?? collect(),
            'transcripts' => $profile?->transcripts()->with(['scores', 'evidenceImages'])->latest('id')->get() ?? collect(),
            'certificateTypes' => config('admission_data.certificate_types'),
            'examTypes' => config('admission_data.exam_types'),
            'subjects' => config('admission_data.subjects'),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function saveParent(
        string $modelClass,
        string $relationship,
        ?int $id,
        array $attributes,
        mixed $evidence,
        string $directory,
        CandidateFiles $files,
    ): void {
        $newPath = $oldPath = null;
        try {
            $this->profile()->getConnection()->transaction(function () use (
                $modelClass, $relationship, $id, $attributes, $evidence, $directory, $files, &$newPath, &$oldPath
            ): void {
                $profile = CandidateApplications::lockProfile();
                if ($relationship === 'certificates' && $id === null && $profile->certificates()->exists()) {
                    throw ValidationException::withMessages(['certificateForm' => __('Bạn đã có chứng chỉ. Vui lòng chỉnh sửa thông tin hiện có.')]);
                }
                if ($relationship === 'admissionClaims' && $id === null
                    && in_array($attributes['claim_type'], ['direct_admission', 'priority_admission'], true)
                    && $profile->admissionClaims()->where('claim_type', $attributes['claim_type'])->exists()) {
                    throw ValidationException::withMessages(['claimForm' => __('Thông tin này đã được lưu. Vui lòng tải lại trang để chỉnh sửa.')]);
                }
                $record = $id === null ? null : $profile->{$relationship}()->lockForUpdate()->findOrFail($id);
                Gate::authorize($record === null ? 'create' : 'update', $record === null ? [$modelClass, $profile] : $record);
                $record ??= $profile->{$relationship}()->make();
                $record->fill([...$attributes, ...$this->resubmissionAttributes($record)]);
                if ($evidence instanceof UploadedFile) {
                    $oldPath = $record->getAttribute('evidence_path');
                    $newPath = $files->store($evidence, $directory.'/'.$profile->getKey(), 'evidence');
                    $record->setAttribute('evidence_path', $newPath);
                }
                if (! $record->save()) {
                    throw ValidationException::withMessages(['form' => __('Không thể lưu thông tin tuyển sinh.')]);
                }
            });
        } catch (Throwable $exception) {
            $files->remove($newPath);
            throw $exception;
        }
        $this->removeReplacedEvidence($files, $oldPath);
    }

    /** @param array<string, mixed> $attributes
     * @param  list<array<string, mixed>>  $subjects
     */
    private function saveExam(?int $id, array $attributes, array $subjects, mixed $evidence, CandidateFiles $files): void
    {
        $newPath = $oldPath = null;
        try {
            $this->profile()->getConnection()->transaction(function () use (
                $id, $attributes, $subjects, $evidence, $files, &$newPath, &$oldPath
            ): void {
                $profile = CandidateApplications::lockProfile();
                if ($attributes['exam_type'] !== 'thpt' && $id === null
                    && $profile->examResults()->whereIn('exam_type', $this->competencyTypes())->exists()) {
                    throw ValidationException::withMessages(['competencyForm' => __('Bạn đã có kết quả kỳ thi. Vui lòng chỉnh sửa thông tin hiện có.')]);
                }
                $query = $profile->examResults()->lockForUpdate();
                $attributes['exam_type'] === 'thpt'
                    ? $query->where('exam_type', 'thpt')
                    : $query->whereIn('exam_type', $this->competencyTypes());
                $record = $id === null ? null : $query->findOrFail($id);
                Gate::authorize($record === null ? 'create' : 'update', $record === null
                    ? [CandidateExamResult::class, $profile] : $record);
                $record ??= $profile->examResults()->make();
                $record->fill([...$attributes, ...$this->resubmissionAttributes($record)]);
                if ($evidence instanceof UploadedFile) {
                    $oldPath = $record->getAttribute('evidence_path');
                    $newPath = $files->store($evidence, 'candidate-exam-results/'.$profile->getKey(), 'evidence');
                    $record->setAttribute('evidence_path', $newPath);
                }
                $record->saveOrFail();
                $record->subjectScores()->delete();
                foreach ($subjects as $subject) {
                    $record->subjectScores()->create([
                        'subject_code' => $subject['subject_code'],
                        'subject_name' => config('admission_data.subjects.'.$subject['subject_code']),
                        'score' => $subject['score'],
                    ]);
                }
            });
        } catch (Throwable $exception) {
            $files->remove($newPath);
            throw $exception;
        }
        $this->removeReplacedEvidence($files, $oldPath);
    }

    /** @param array<string, mixed> $attributes
     * @param  list<array{subject_code: string, subject_name: string, grade_level: int, score: mixed}>  $rows
     */
    private function saveTranscriptRecord(?int $id, array $attributes, array $rows, mixed $evidence, CandidateFiles $files): void
    {
        $newPaths = $oldPaths = [];
        try {
            $this->profile()->getConnection()->transaction(function () use (
                $id, $attributes, $rows, $evidence, $files, &$newPaths, &$oldPaths
            ): void {
                $profile = CandidateApplications::lockProfile();
                $record = $id === null ? null : $profile->transcripts()->lockForUpdate()->findOrFail($id);
                Gate::authorize($record === null ? 'create' : 'update', $record === null
                    ? [CandidateTranscript::class, $profile] : $record);
                $record ??= $profile->transcripts()->make();
                $record->fill([...$attributes, ...$this->resubmissionAttributes($record)]);
                if ($this->removeLegacyTranscriptEvidence && $record->evidence_path !== null) {
                    $oldPaths[] = $record->evidence_path;
                    $record->evidence_path = null;
                }
                $record->saveOrFail();
                foreach ($this->removedTranscriptEvidence as $imageId) {
                    $image = $record->evidenceImages()->findOrFail($imageId);
                    $oldPaths[] = $image->path;
                    $image->deleteOrFail();
                }
                $sortOrder = (int) $record->evidenceImages()->max('sort_order');
                foreach ($evidence as $upload) {
                    $path = $files->store($upload, 'candidate-transcripts/'.$profile->id, 'evidence');
                    $newPaths[] = $path;
                    $image = $record->evidenceImages()->make([
                        'path' => $path,
                        'original_name' => CandidateFiles::displayName($upload->getClientOriginalName()),
                        'mime_type' => $upload->getMimeType(),
                        'size' => $upload->getSize(),
                        'sort_order' => ++$sortOrder,
                    ]);
                    $image->saveOrFail();
                }
                if (($id === null || $this->removedTranscriptEvidence !== [] || $this->removeLegacyTranscriptEvidence)
                    && $record->evidence_path === null && ! $record->evidenceImages()->exists()) {
                    throw ValidationException::withMessages(['transcriptEvidence' => __('Vui lòng giữ hoặc tải lên ít nhất một ảnh học bạ.')]);
                }
                $record->scores()->delete();
                $record->scores()->createMany($rows);
                $this->transcriptId = $record->id;
            });
        } catch (Throwable $exception) {
            foreach ($newPaths as $path) {
                $files->remove($path);
            }
            throw $exception;
        }
        foreach ($oldPaths as $path) {
            $this->removeReplacedEvidence($files, $path);
        }
    }

    /** @param string|list<string>|null $expectedType */
    private function deleteParent(string $relationship, int $id, CandidateFiles $files, string|array|null $expectedType = null): void
    {
        $paths = $this->profile()->getConnection()->transaction(function () use ($relationship, $id, $expectedType): array {
            $profile = CandidateApplications::lockProfile();
            $query = $profile->{$relationship}()->lockForUpdate();
            if (is_string($expectedType)) {
                $query->where('exam_type', $expectedType);
            } elseif (is_array($expectedType)) {
                $query->whereIn('exam_type', $expectedType);
            }
            $record = $query->findOrFail($id);
            Gate::authorize('delete', $record);
            $paths = [$record->getAttribute('evidence_path')];
            if ($record instanceof CandidateTranscript) {
                $paths = [...$paths, ...$record->evidenceImages()->pluck('path')->all()];
            }
            $record->deleteOrFail();

            return $paths;
        });
        foreach ($paths as $path) {
            $this->removeReplacedEvidence($files, $path);
        }
    }

    /** @return array<string, mixed> */
    private function resubmissionAttributes(Model $record): array
    {
        return $record->exists && $record->getAttribute('status') === VerificationStatus::Rejected
            ? ['status' => VerificationStatus::Pending, 'verified_by' => null, 'verified_at' => null, 'rejection_reason' => null]
            : [];
    }

    private function removeReplacedEvidence(CandidateFiles $files, ?string $path): void
    {
        if (! $files->remove($path)) {
            $this->addError('cleanup', __('Đã lưu dữ liệu nhưng không thể xóa minh chứng cũ. Vui lòng liên hệ bộ phận hỗ trợ.'));
        }
    }

    /** @return list<string> */
    private function competencyTypes(): array
    {
        return ['dgnl', 'dgtd', 'vsat', 'spt'];
    }

    /** @return list<mixed> */
    private function scoreRules(?string $group = null, ?string $type = null): array
    {
        $rules = ['required', 'numeric', 'decimal:0,3', 'between:0,99999.999'];
        if ($group !== null && $type !== null) {
            $minimum = config("admission_data.{$group}.{$type}.score.min");
            $maximum = config("admission_data.{$group}.{$type}.score.max");
            if (is_numeric($minimum)) {
                $rules[] = 'min:'.$minimum;
            }
            if (is_numeric($maximum)) {
                $rules[] = 'max:'.$maximum;
            }
        }

        return $rules;
    }

    /** @return list<mixed> */
    private function optionalScoreRules(): array
    {
        return ['nullable', 'numeric', 'decimal:0,3', 'between:0,99999.999'];
    }

    /** @param list<array<string, mixed>> $subjects
     * @return list<array{subject_code: string, subject_name: string, grade_level: int, score: mixed}>
     */
    private function transcriptRows(array $subjects): array
    {
        $rows = [];
        foreach ($subjects as $subject) {
            foreach ([10, 11, 12] as $grade) {
                $score = $subject['grade_'.$grade] ?? null;
                if ($score === null || $score === '') {
                    continue;
                }
                $rows[] = [
                    'subject_code' => $subject['subject_code'],
                    'subject_name' => config('admission_data.subjects.'.$subject['subject_code']),
                    'grade_level' => $grade,
                    'score' => $score,
                ];
            }
        }

        return $rows;
    }

    /** @return array<string, string> */
    private function evidenceMessages(string $field): array
    {
        return [
            $field.'.required' => __('Vui lòng tải ảnh minh chứng.'),
            $field.'.image' => __('Minh chứng phải là ảnh JPG, JPEG hoặc PNG.'),
            $field.'.mimes' => __('Minh chứng phải là ảnh JPG, JPEG hoặc PNG.'),
            $field.'.extensions' => __('Minh chứng phải có đuôi JPG, JPEG hoặc PNG.'),
            $field.'.max' => __('Minh chứng không được vượt quá 2 MiB.'),
        ];
    }
}
