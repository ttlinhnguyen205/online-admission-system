<?php

use App\Actions\StaffApplicationReview;
use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\ApplicationDetails;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/ReviewFixtures.php';

test('pending document verification writes server metadata and leaves file content untouched', function () {
    $this->freezeTime();
    $application = reviewApplication();
    $document = CandidateDocument::factory()->for($application)->create(['rejection_reason' => 'Obsolete']);
    Storage::disk('candidate-private')->put($document->file_path, '%PDF-1.4 private');
    $actor = User::factory()->create(['role' => UserRole::Staff]);
    $this->actingAs($actor);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', 'verifyDocument', $document->id)->call('perform')->assertHasNoErrors();
    expect($document->fresh()->status)->toBe(DocumentStatus::Verified);
    expect($document->fresh()->verified_by)->toBe($actor->id);
    expect($document->fresh()->verified_at->format('Y-m-d H:i:s'))->toBe(now()->format('Y-m-d H:i:s'));
    expect($document->fresh()->rejection_reason)->toBeNull();
    expect(Storage::disk('candidate-private')->get($document->file_path))->toBe('%PDF-1.4 private');
    expect(ActivityLog::query()->sole()->action)->toBe('document.verified');
    expect($application->fresh()->reviewed_by)->toBeNull();
    $this->get(route('admission.documents.download', $document->id))->assertDownload()->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('pending rejection permits missing files and clears successful verification metadata', function () {
    $application = reviewApplication();
    $document = CandidateDocument::factory()->for($application)->create(['verified_by' => User::factory()->create()->id, 'verified_at' => now()]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->call('confirm', 'rejectDocument', $document->id)->set('form.rejection_reason', '  Replace unreadable file  ')->call('perform')->assertHasNoErrors();
    expect($document->fresh()->status)->toBe(DocumentStatus::Rejected);
    expect($document->fresh()->rejection_reason)->toBe('Replace unreadable file');
    expect($document->fresh()->verified_by)->toBeNull();
    expect($document->fresh()->verified_at)->toBeNull();
    expect(ActivityLog::query()->sole()->action)->toBe('document.rejected');
});

test('document rejection validates reason and forbidden metadata', function (string $field, mixed $value, string $error) {
    $application = reviewApplication();
    $document = CandidateDocument::factory()->for($application)->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', 'rejectDocument', $document->id)
        ->set('form.rejection_reason', 'Replace')->set('form.'.$field, $value)->call('perform')->assertHasErrors($error);
    expect($document->fresh()->status)->toBe(DocumentStatus::Pending);
    $this->assertDatabaseCount('activity_logs', 0);
})->with([
    ['rejection_reason', '', 'form.rejection_reason'], ['rejection_reason', '   ', 'form.rejection_reason'],
    ['rejection_reason', str_repeat('x', 5001), 'form.rejection_reason'], ['rejection_reason', [], 'form.rejection_reason'],
    ['status', 'verified', 'form'], ['verified_by', 123, 'form'], ['verified_at', '2026-01-01', 'form'],
    ['application_id', 123, 'form'], ['file_path', 'secret', 'form'], ['unknown', 123, 'form'],
]);

test('documents cannot be reviewed again or outside under review', function (DocumentStatus $status, string $operation) {
    $application = reviewApplication();
    $document = CandidateDocument::factory()->for($application)->create(['status' => $status]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $review = app(StaffApplicationReview::class);
    $token = reviewToken($application);
    expect(fn () => $operation === 'verify'
        ? $review->verifyDocument($application->id, $document->id, $token)
        : $review->rejectDocument($application->id, $document->id, $token, ['rejection_reason' => 'Replace']))->toThrow(ValidationException::class);
    expect($document->fresh()->status)->toBe($status);
    $this->assertDatabaseCount('activity_logs', 0);
})->with([DocumentStatus::Verified, DocumentStatus::Rejected])->with(['verify', 'reject']);

test('every nonreview application state rejects document decisions', function (ApplicationStatus $status) {
    $application = reviewApplication($status);
    $document = CandidateDocument::factory()->for($application)->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    expect(fn () => app(StaffApplicationReview::class)->rejectDocument($application->id, $document->id, reviewToken($application), ['rejection_reason' => 'Replace']))->toThrow(ValidationException::class);
    expect($document->fresh()->status)->toBe(DocumentStatus::Pending);
})->with(array_filter(ApplicationStatus::cases(), fn ($s) => $s !== ApplicationStatus::UnderReview));

test('missing and unsafe private document files cannot be verified', function (string $path) {
    $application = reviewApplication();
    $document = CandidateDocument::factory()->for($application)->create(['file_path' => $path]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', 'verifyDocument', $document->id)->call('perform')->assertHasErrors('review');
    expect($document->fresh()->status)->toBe(DocumentStatus::Pending);
    $this->assertDatabaseCount('activity_logs', 0);
})->with(['candidate-documents/missing.pdf', 'candidate-documents/../secret.pdf', 'candidate-photos/not-a-document.png']);

test('foreign documents cannot be selected under another application even with the same candidate', function (bool $sameProfile) {
    $application = reviewApplication();
    $other = Application::factory()->create($sameProfile ? ['candidate_profile_id' => $application->candidate_profile_id] : []);
    $document = CandidateDocument::factory()->for($other)->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', 'rejectDocument', $document->id)->assertNotFound();
    $this->assertDatabaseCount('activity_logs', 0);
})->with([true, false]);

test('changed file type or status invalidates a document confirmation', function (string $field, mixed $value) {
    $application = reviewApplication();
    $document = CandidateDocument::factory()->for($application)->create();
    Storage::disk('candidate-private')->put($document->file_path, '%PDF-1.4');
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', 'verifyDocument', $document->id);
    $document->update([$field => $value]);
    $page->call('perform')->assertHasErrors('review');
    expect($document->fresh()->verified_by)->toBeNull();
    $this->assertDatabaseCount('activity_logs', 0);
})->with([['file_path', 'candidate-documents/replaced.pdf'], ['document_type', 'certificate'], ['status', 'rejected']]);

test('candidate cannot invoke document review action directly', function () {
    $application = reviewApplication();
    $document = CandidateDocument::factory()->for($application)->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $token = reviewToken($application);
    $this->actingAs($application->candidateProfile->user);
    expect(fn () => app(StaffApplicationReview::class)->rejectDocument($application->id, $document->id, $token, ['rejection_reason' => 'Injected']))->toThrow(HttpException::class);
    $this->assertDatabaseCount('activity_logs', 0);
});
