<?php

use App\Actions\CandidateFiles;
use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Livewire\Candidate\Documents;
use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function phaseFourPdf(string $name = 'transcript.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF");
}

test('documents without an application show an empty state and create no records', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('candidate.documents.index'))->assertOk()->assertSee('No admission application yet');
    $this->assertDatabaseCount('applications', 0);
    $this->assertDatabaseCount('candidate_documents', 0);
    $this->assertDatabaseCount('candidate_profiles', 0);
});

test('owned editable applications support document upload replace and delete', function (ApplicationStatus $status) {
    Storage::fake(CandidateFiles::DISK);
    $application = Application::factory()->create(['status' => $status]);
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(Documents::class, ['application' => $application->id])->call('create')
        ->set('form.document_type', ' Transcript ')->set('file', phaseFourPdf())->call('save')->assertHasNoErrors();
    $document = $application->documents()->sole();
    expect($document->document_type)->toBe('transcript');
    expect($document->status)->toBe(DocumentStatus::Pending);
    expect($document->mime_type)->toBe('application/pdf');
    expect($document->file_size)->toBeGreaterThan(0);
    expect($document->file_path)->toStartWith('candidate-documents/'.$application->id.'/')->not->toContain('transcript.pdf');
    Storage::disk(CandidateFiles::DISK)->assertExists($document->file_path);
    $oldPath = $document->file_path;
    $document->update(['status' => DocumentStatus::Rejected, 'verified_by' => User::factory()->create()->id, 'verified_at' => now(), 'rejection_reason' => 'Blurry scan']);
    $page->call('edit', $document->id)->set('file', phaseFourPdf('clear.pdf'))->call('save')->assertHasNoErrors();
    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Pending);
    expect($document->verified_by)->toBeNull();
    expect($document->verified_at)->toBeNull();
    expect($document->rejection_reason)->toBeNull();
    Storage::disk(CandidateFiles::DISK)->assertMissing($oldPath);
    Storage::disk(CandidateFiles::DISK)->assertExists($document->file_path);
    $page->call('confirmDeletion', $document->id)->call('delete')->assertHasNoErrors();
    $this->assertModelMissing($document);
    Storage::disk(CandidateFiles::DISK)->assertMissing($document->file_path);
})->with([ApplicationStatus::Draft, ApplicationStatus::NeedsRevision]);

test('noneditable applications refuse every document mutation but retain readable documents', function (ApplicationStatus $status, string $action) {
    $application = Application::factory()->create(['status' => $status]);
    $document = CandidateDocument::factory()->for($application)->create();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(Documents::class, ['application' => $application->id])->assertSee('Read-only: application is not editable');
    $page->call($action, ...($action === 'create' ? [] : [$document->id]))->assertForbidden();
    $this->assertModelExists($document);
})->with([ApplicationStatus::Submitted, ApplicationStatus::UnderReview, ApplicationStatus::Verified, ApplicationStatus::Processing, ApplicationStatus::Completed])->with(['create', 'edit', 'confirmDeletion']);

test('document save and delete recheck application lifecycle after a form opened', function (string $action) {
    Storage::fake(CandidateFiles::DISK);
    $document = CandidateDocument::factory()->create();
    Storage::disk(CandidateFiles::DISK)->put($document->file_path, 'original');
    $this->actingAs($document->application->candidateProfile->user);
    $page = Livewire::test(Documents::class, ['application' => $document->application_id])
        ->call($action === 'save' ? 'edit' : 'confirmDeletion', $document->id);
    if ($action === 'save') {
        $page->set('file', phaseFourPdf());
    }
    $document->application->update(['status' => ApplicationStatus::Submitted]);
    $page->call($action)->assertForbidden();
    expect($document->fresh()->file_path)->toBe($document->file_path);
    expect(Storage::disk(CandidateFiles::DISK)->allFiles())->toBe([$document->file_path]);
})->with(['save', 'delete']);

test('verified document replacement is allowed by editable parent and resets review while unchanged saves preserve it', function () {
    Storage::fake(CandidateFiles::DISK);
    $document = CandidateDocument::factory()->create(['status' => DocumentStatus::Verified]);
    $this->actingAs($document->application->candidateProfile->user);
    $page = Livewire::test(Documents::class, ['application' => $document->application_id])->call('edit', $document->id)->call('save')->assertHasNoErrors();
    expect($document->fresh()->status)->toBe(DocumentStatus::Verified);
    $page->call('edit', $document->id)->set('file', phaseFourPdf())->call('save')->assertHasNoErrors();
    expect($document->fresh()->status)->toBe(DocumentStatus::Pending);
});

test('changing document type invalidates its old review', function () {
    $document = CandidateDocument::factory()->create(['status' => DocumentStatus::Verified, 'verified_at' => now()]);
    $this->actingAs($document->application->candidateProfile->user);
    Livewire::test(Documents::class, ['application' => $document->application_id])->call('edit', $document->id)
        ->set('form.document_type', 'Certificate')->call('save')->assertHasNoErrors();
    expect($document->fresh()->status)->toBe(DocumentStatus::Pending);
    expect($document->fresh()->verified_at)->toBeNull();
});

test('document forms reject privileged and invalid fields', function (string $field, mixed $value, string $error) {
    $document = CandidateDocument::factory()->create();
    $this->actingAs($document->application->candidateProfile->user);
    Livewire::test(Documents::class, ['application' => $document->application_id])->call('edit', $document->id)
        ->set('form.'.$field, $value)->call('save')->assertHasErrors($error);
    expect($document->fresh()->status)->toBe(DocumentStatus::Pending);
    expect($document->fresh()->file_path)->toBe($document->file_path);
})->with([
    ['document_type', '', 'form.document_type'], ['document_type', str_repeat('x', 51), 'form.document_type'],
    ['application_id', 2, 'form'], ['candidate_profile_id', 2, 'form'], ['file_path', '../secret', 'form'], ['original_name', 'evil', 'form'],
    ['mime_type', 'text/html', 'form'], ['file_size', 0, 'form'], ['status', 'verified', 'form'], ['verified_by', 1, 'form'],
    ['verified_at', '2026-01-01', 'form'], ['rejection_reason', 'x', 'form'], ['unexpected', true, 'form'],
]);

test('invalid document bytes extensions and size leave permanent storage empty', function (string $kind) {
    Storage::fake(CandidateFiles::DISK);
    config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:12288']]);
    $application = Application::factory()->create();
    $this->actingAs($application->candidateProfile->user);
    $file = match ($kind) {
        'html' => UploadedFile::fake()->createWithContent('fake.pdf', '<html><script>alert(1)</script></html>'),
        'extension' => phaseFourPdf('script.php'),
        'mismatch' => phaseFourPdf('photo.jpg'),
        'size' => phaseFourPdf()->size(10241),
    };
    Livewire::test(Documents::class, ['application' => $application->id])->call('create')
        ->set('form.document_type', 'transcript')->set('file', $file)->call('save')->assertHasErrors('file');
    $this->assertDatabaseCount('candidate_documents', 0);
    expect(Storage::disk(CandidateFiles::DISK)->allFiles())->toBe([]);
})->with(['html', 'extension', 'mismatch', 'size']);

test('document uploads require a file and do not overwrite matching types', function () {
    Storage::fake(CandidateFiles::DISK);
    $application = Application::factory()->create();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(Documents::class, ['application' => $application->id])->call('create')->set('form.document_type', 'transcript')->call('save')->assertHasErrors('file');
    CandidateDocument::factory()->for($application)->create();
    $page->set('file', phaseFourPdf())->call('save')->assertHasNoErrors();
    expect($application->documents()->count())->toBe(2);
});

test('failed document persistence rolls back new files and retains the original', function () {
    Storage::fake(CandidateFiles::DISK);
    $document = CandidateDocument::factory()->create();
    Storage::disk(CandidateFiles::DISK)->put($document->file_path, 'original');
    $this->actingAs($document->application->candidateProfile->user);
    Event::listen('eloquent.saving: '.CandidateDocument::class, fn () => false);
    try {
        Livewire::test(Documents::class, ['application' => $document->application_id])->call('edit', $document->id)
            ->set('file', phaseFourPdf())->call('save')->assertHasErrors('form');
        expect($document->fresh()->file_path)->toBe($document->file_path);
        expect(Storage::disk(CandidateFiles::DISK)->allFiles())->toBe([$document->file_path]);
    } finally {
        Event::forget('eloquent.saving: '.CandidateDocument::class);
    }
});

test('cancelled document deletion preserves the record and file', function () {
    Storage::fake(CandidateFiles::DISK);
    $document = CandidateDocument::factory()->create();
    Storage::disk(CandidateFiles::DISK)->put($document->file_path, 'original');
    $this->actingAs($document->application->candidateProfile->user);
    Event::listen('eloquent.deleting: '.CandidateDocument::class, fn () => false);
    try {
        Livewire::test(Documents::class, ['application' => $document->application_id])->call('confirmDeletion', $document->id)->call('delete')->assertHasErrors('deletion');
        $this->assertModelExists($document);
        Storage::disk(CandidateFiles::DISK)->assertExists($document->file_path);
    } finally {
        Event::forget('eloquent.deleting: '.CandidateDocument::class);
    }
});

test('document list scopes applications and escapes rejected reasons', function () {
    $document = CandidateDocument::factory()->create(['status' => DocumentStatus::Rejected, 'rejection_reason' => '<script>alert(1)</script>']);
    $foreign = CandidateDocument::factory()->create(['original_name' => 'FOREIGN-SECRET.pdf']);
    $this->actingAs($document->application->candidateProfile->user);
    Livewire::test(Documents::class, ['application' => $document->application_id])->assertDontSee('FOREIGN-SECRET.pdf')
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    $this->get(route('candidate.applications.documents.index', $foreign->application_id))->assertNotFound();
});

test('a database transaction failure after document saving restores metadata and removes the new file', function () {
    Storage::fake(CandidateFiles::DISK);
    $document = CandidateDocument::factory()->create(['status' => DocumentStatus::Verified]);
    Storage::disk(CandidateFiles::DISK)->put($document->file_path, 'original');
    $this->actingAs($document->application->candidateProfile->user);
    Event::listen('eloquent.saved: '.CandidateDocument::class, function (): void {
        throw ValidationException::withMessages(['form' => 'Simulated persistence failure.']);
    });
    try {
        Livewire::test(Documents::class, ['application' => $document->application_id])->call('edit', $document->id)
            ->set('file', phaseFourPdf())->call('save')->assertHasErrors('form');
        expect($document->fresh()->file_path)->toBe($document->file_path);
        expect($document->fresh()->status)->toBe(DocumentStatus::Verified);
        expect(Storage::disk(CandidateFiles::DISK)->allFiles())->toBe([$document->file_path]);
    } finally {
        Event::forget('eloquent.saved: '.CandidateDocument::class);
    }
});

test('storage write failure leaves the existing document untouched and gives actionable feedback', function () {
    $disk = Storage::fake(CandidateFiles::DISK);
    $document = CandidateDocument::factory()->create();
    $disk->put($document->file_path, 'original');
    $this->actingAs($document->application->candidateProfile->user);
    $page = Livewire::test(Documents::class, ['application' => $document->application_id])->call('edit', $document->id)->set('file', phaseFourPdf());
    $failedDisk = Mockery::mock(FilesystemAdapter::class);
    $failedDisk->shouldReceive('putFileAs')->once()->andThrow(new RuntimeException('Disk unavailable'));
    $failedDisk->shouldReceive('delete')->once()->andReturn(true);
    Storage::set(CandidateFiles::DISK, $failedDisk);
    $page->call('save')->assertHasErrors('file')->assertSee('The file could not be stored. Please try again.');
    expect($document->fresh()->file_path)->toBe($document->file_path);
    $disk->assertExists($document->file_path);
});

test('failed physical deletion reports cleanup failure without claiming complete deletion', function () {
    $disk = Storage::fake(CandidateFiles::DISK);
    $document = CandidateDocument::factory()->create();
    $disk->put($document->file_path, 'original');
    $this->actingAs($document->application->candidateProfile->user);
    $page = Livewire::test(Documents::class, ['application' => $document->application_id])->call('confirmDeletion', $document->id);
    $failedDisk = Mockery::mock(FilesystemAdapter::class);
    $failedDisk->shouldReceive('delete')->once()->with($document->file_path)->andReturn(false);
    Storage::set(CandidateFiles::DISK, $failedDisk);
    $page->call('delete')->assertHasErrors('cleanup')->assertSee('file cleanup failed');
    $this->assertModelMissing($document);
    $disk->assertExists($document->file_path);
});

test('PNG and JPEG documents use detected content types', function (string $fixture, string $mime) {
    Storage::fake(CandidateFiles::DISK);
    $application = Application::factory()->create();
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(Documents::class, ['application' => $application->id])->call('create')->set('form.document_type', 'image')
        ->set('file', UploadedFile::fake()->createWithContent($fixture, file_get_contents(base_path('tests/Fixtures/'.$fixture))))
        ->call('save')->assertHasNoErrors();
    $document = $application->documents()->sole();
    expect($document->mime_type)->toBe($mime);
    Storage::disk(CandidateFiles::DISK)->assertExists($document->file_path);
})->with([['portrait.png', 'image/png'], ['replacement.jpg', 'image/jpeg']]);
