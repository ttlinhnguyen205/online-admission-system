<?php

namespace App\Livewire\Candidate;

use App\Actions\CandidateFiles;
use App\Enums\DocumentStatus;
use App\Models\Application;
use App\Models\CandidateDocument;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

#[Title('Tài liệu hồ sơ')]
class Documents extends CandidatePage
{
    use WithFileUploads, WithPagination;

    #[Locked]
    public ?int $applicationId = null;

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public ?int $deleteId = null;

    /** @var array<string, mixed> */
    public array $form = ['document_type' => ''];

    public mixed $file = null;

    public bool $showEditor = false;

    public bool $showDeletion = false;

    public function mount(?int $application = null): void
    {
        if ($application !== null) {
            $this->applicationId = $this->profile()->applications()->findOrFail($application)->getKey();
            Gate::authorize('view', $this->application());
        }
    }

    protected function application(): Application
    {
        abort_if($this->applicationId === null, 404);
        $application = $this->profile()->applications()->findOrFail($this->applicationId);
        Gate::authorize('view', $application);

        return $application;
    }

    public function create(): void
    {
        Gate::authorize('create', [CandidateDocument::class, $this->application()]);
        $this->resetValidation();
        $this->recordId = null;
        $this->file = null;
        $this->form = ['document_type' => ''];
        $this->showEditor = true;
    }

    public function edit(int $id): void
    {
        $document = $this->application()->documents()->findOrFail($id);
        Gate::authorize('update', $document);
        $this->resetValidation();
        $this->recordId = $document->getKey();
        $this->form = $document->only('document_type');
        $this->file = null;
        $this->showEditor = true;
    }

    public function save(CandidateFiles $files): void
    {
        $profile = $this->profile();
        abort_if($this->applicationId === null, 404);
        $newPath = null;
        $oldPath = null;
        try {
            $profile->getConnection()->transaction(function () use ($profile, $files, &$newPath, &$oldPath): void {
                $application = $profile->applications()->lockForUpdate()->findOrFail($this->applicationId);
                $document = $this->recordId === null ? null : $application->documents()->lockForUpdate()->findOrFail($this->recordId);
                Gate::authorize($document === null ? 'create' : 'update', $document ?? [CandidateDocument::class, $application]);
                $this->form = $this->normalize($this->form);
                if (is_string($this->form['document_type'] ?? null)) {
                    $this->form['document_type'] = Str::lower($this->form['document_type']);
                }
                $validated = $this->validate([
                    'form' => ['required', 'array:document_type'],
                    'form.document_type' => ['required', 'string', 'max:50'],
                    'file' => CandidateFiles::documentRules($document === null),
                ]);
                $document ??= $application->documents()->make();
                $document->setAttribute('document_type', $validated['form']['document_type']);
                if ($this->file instanceof UploadedFile) {
                    $oldPath = $document->getAttribute('file_path');
                    $newPath = $files->store($this->file, 'candidate-documents/'.$application->getKey(), 'file');
                    $document->fill([
                        'file_path' => $newPath,
                        'original_name' => CandidateFiles::displayName($this->file->getClientOriginalName()),
                        'mime_type' => $files->mime($this->file, 'file'),
                        'file_size' => $this->file->getSize(),
                    ]);
                }
                if (! $document->exists || $document->isDirty(['document_type', 'file_path'])) {
                    $document->fill(['status' => DocumentStatus::Pending, 'verified_by' => null, 'verified_at' => null, 'rejection_reason' => null]);
                }
                if (! $document->save()) {
                    throw ValidationException::withMessages(['form' => __('Không thể lưu tài liệu.')]);
                }
            });
        } catch (Throwable $exception) {
            $files->remove($newPath);
            throw $exception;
        }
        $this->file = null;
        $this->showEditor = false;
        $this->resetPage();
        if (! $files->remove($oldPath)) {
            $this->addError('cleanup', __('Đã lưu nhưng không thể xóa tệp cũ. Vui lòng liên hệ bộ phận hỗ trợ.'));
        }
        Flux::toast(variant: 'success', text: __('Đã lưu tài liệu.'));
    }

    public function confirmDeletion(int $id): void
    {
        $document = $this->application()->documents()->findOrFail($id);
        Gate::authorize('delete', $document);
        $this->resetValidation();
        $this->deleteId = $document->getKey();
        $this->showDeletion = true;
    }

    public function delete(CandidateFiles $files): void
    {
        $profile = $this->profile();
        abort_if($this->applicationId === null || $this->deleteId === null, 404);
        $path = $profile->getConnection()->transaction(function () use ($profile): string {
            $application = $profile->applications()->lockForUpdate()->findOrFail($this->applicationId);
            $document = $application->documents()->lockForUpdate()->findOrFail($this->deleteId);
            Gate::authorize('delete', $document);
            $path = (string) $document->getAttribute('file_path');
            if (! $document->delete()) {
                throw ValidationException::withMessages(['deletion' => __('Không thể xóa tài liệu.')]);
            }

            return $path;
        });
        $this->showDeletion = false;
        $this->deleteId = null;
        $this->resetPage();
        if (! $files->remove($path)) {
            $this->addError('cleanup', __('Đã xóa bản ghi nhưng không thể dọn tệp. Vui lòng liên hệ bộ phận hỗ trợ.'));

            return;
        }
        Flux::toast(variant: 'success', text: __('Đã xóa tài liệu.'));
    }

    public function render(): View
    {
        $profile = $this->candidate()->candidateProfile()->first();
        $applications = $profile?->applications()->with('admissionRound')->orderByDesc('id')->get() ?? collect();
        $application = $this->applicationId === null ? null : $this->application();
        $records = $application?->documents()->orderByDesc('id')->paginate(15);

        return view('livewire.candidate.documents', compact('profile', 'applications', 'application', 'records'));
    }
}
