<?php

use App\Actions\StaffApplicationReview;
use App\Enums\ApplicationStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\ApplicationDetails;
use App\Livewire\Candidate\Profile;
use App\Models\ActivityLog;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\CandidateScore;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/ReviewFixtures.php';

test('reviewers start review with exact server metadata and preserve prior reason', function (UserRole $role) {
    $this->freezeTime();
    $application = reviewApplication(ApplicationStatus::Submitted);
    $previous = User::factory()->create();
    $application->update(['reviewed_by' => $previous->id, 'reviewed_at' => now()->subDay(), 'revision_reason' => 'Earlier reason']);
    $actor = User::factory()->create(['role' => $role]);
    $this->actingAs($actor);
    $original = $application->fresh()->only(['application_code', 'candidate_profile_id', 'admission_round_id', 'submitted_at']);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', 'start')->call('perform')->assertHasNoErrors();
    expect($application->fresh()->status)->toBe(ApplicationStatus::UnderReview);
    expect($application->fresh()->reviewed_by)->toBe($actor->id);
    expect($application->fresh()->reviewed_at)->toBeNull();
    expect($application->fresh()->revision_reason)->toBe('Earlier reason');
    expect($application->fresh()->only(array_keys($original)))->toEqual($original);
    expect(ActivityLog::query()->sole()->action)->toBe('application.review_started');
})->with([UserRole::Staff, UserRole::Admin]);

test('staff actions reject every incorrect starting application state', function (ApplicationStatus $status, string $operation) {
    $application = reviewApplication($status);
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $review = app(StaffApplicationReview::class);
    $token = reviewToken($application);
    expect(fn () => match ($operation) {
        'start' => $review->start($application->id, $token),
        'revision' => $review->requestRevision($application->id, $token, ['revision_reason' => 'Please revise']),
        'verify' => $review->verify($application->id, $token),
    })->toThrow(ValidationException::class);
    expect($application->fresh()->status)->toBe($status);
    $this->assertDatabaseCount('activity_logs', 0);
})->with(collect(ApplicationStatus::cases())->flatMap(fn ($status) => collect(['start', 'revision', 'verify'])
    ->filter(fn ($operation) => $status !== ($operation === 'start' ? ApplicationStatus::Submitted : ApplicationStatus::UnderReview))
    ->map(fn ($operation) => [$status, $operation])->values()->all())->all());

test('general revision requests trim reasons and set latest reviewer metadata', function () {
    $this->freezeTime();
    $application = reviewApplication();
    $actor = User::factory()->create(['role' => UserRole::Staff]);
    $this->actingAs($actor);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->call('confirm', 'revision')->set('form.revision_reason', '  Explain your qualification  ')->call('perform')->assertHasNoErrors();
    expect($application->fresh()->status)->toBe(ApplicationStatus::NeedsRevision);
    expect($application->fresh()->revision_reason)->toBe('Explain your qualification');
    expect($application->fresh()->reviewed_by)->toBe($actor->id);
    expect($application->fresh()->reviewed_at->format('Y-m-d H:i:s'))->toBe(now()->format('Y-m-d H:i:s'));
    $this->assertDatabaseCount('candidate_documents', 0);
    $this->assertDatabaseCount('candidate_scores', 0);
});

test('revision reasons reject blank invalid and oversized values', function (mixed $reason) {
    $application = reviewApplication();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->call('confirm', 'revision')->set('form.revision_reason', $reason)->call('perform')->assertHasErrors('form.revision_reason');
    expect($application->fresh()->status)->toBe(ApplicationStatus::UnderReview);
    $this->assertDatabaseCount('activity_logs', 0);
})->with([[''], ['   '], [null], [[]], [str_repeat('x', 5001)]]);

test('the reason boundary is accepted', function () {
    $application = reviewApplication();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    app(StaffApplicationReview::class)->requestRevision($application->id, reviewToken($application), ['revision_reason' => str_repeat('é', 5000)]);
    expect(mb_strlen($application->fresh()->revision_reason))->toBe(5000);
});

test('application verification permits zero files and scores and preserves later admission fields', function () {
    $this->freezeTime();
    $application = reviewApplication();
    $application->update(['revision_reason' => 'Resolved']);
    $wish = $application->wishes()->sole();
    $program = $wish->admissionProgram;
    $wishBefore = $wish->getAttributes();
    $programBefore = $program->getAttributes();
    $actor = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($actor);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', 'verify')->call('perform')->assertHasNoErrors();
    expect($application->fresh()->status)->toBe(ApplicationStatus::Verified);
    expect($application->fresh()->reviewed_by)->toBe($actor->id);
    expect($application->fresh()->reviewed_at->format('Y-m-d H:i:s'))->toBe(now()->format('Y-m-d H:i:s'));
    expect($application->fresh()->revision_reason)->toBeNull();
    expect($wish->fresh()->getAttributes())->toBe($wishBefore);
    expect($program->fresh()->getAttributes())->toBe($programBefore);
    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('candidate_documents', 0);
    $this->assertDatabaseCount('candidate_scores', 0);
});

test('application verification rechecks every required profile field even when profile is verified', function (string $field) {
    $application = reviewApplication();
    $application->candidateProfile->update(['profile_status' => ProfileStatus::Verified, $field => null]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    expect(fn () => app(StaffApplicationReview::class)->verify($application->id, reviewToken($application)))->toThrow(ValidationException::class);
    expect($application->fresh()->status)->toBe(ApplicationStatus::UnderReview);
    $this->assertDatabaseCount('activity_logs', 0);
})->with(Profile::COMPLETION);

test('application verification rejects incomplete or inconsistent persisted review data', function (string $condition) {
    $application = reviewApplication();
    match ($condition) {
        'submission' => $application->update(['submitted_at' => null]),
        'profile' => $application->candidateProfile->update(['profile_status' => ProfileStatus::Incomplete]),
        'photo' => Storage::disk('candidate-private')->delete($application->candidateProfile->photo_path),
        'unsafe photo' => $application->candidateProfile->update(['photo_path' => 'candidate-photos/../private.txt']),
        'no wishes' => $application->wishes()->delete(),
        'priority' => $application->wishes()->update(['priority' => 2]),
        'cross round' => $application->wishes()->sole()->admissionProgram->update(['admission_round_id' => AdmissionRound::factory()->create()->id]),
        'pending document' => CandidateDocument::factory()->for($application)->create(),
        'rejected document' => CandidateDocument::factory()->for($application)->create(['status' => 'rejected']),
        'missing document' => CandidateDocument::factory()->for($application)->create(['status' => 'verified']),
        'score' => CandidateScore::factory()->for($application->candidateProfile)->create(),
    };
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    expect(fn () => app(StaffApplicationReview::class)->verify($application->id, reviewToken($application)))->toThrow(ValidationException::class);
    expect($application->fresh()->status)->toBe(ApplicationStatus::UnderReview);
    $this->assertDatabaseCount('activity_logs', 0);
})->with(['submission', 'profile', 'photo', 'unsafe photo', 'no wishes', 'priority', 'cross round', 'pending document', 'rejected document', 'missing document', 'score']);

test('verified documents and scores permit application verification after deadline and catalog deactivation', function () {
    $application = reviewApplication();
    $application->admissionRound->update(['status' => 'closed', 'end_date' => now()->subDay()]);
    $program = $application->wishes()->sole()->admissionProgram;
    $program->update(['status' => 'inactive', 'quota' => 0]);
    $program->major->update(['is_active' => false]);
    $program->admissionMethod->update(['is_active' => false]);
    $document = CandidateDocument::factory()->for($application)->create(['status' => 'verified']);
    Storage::disk('candidate-private')->put($document->file_path, '%PDF-1.4 reviewed');
    CandidateScore::factory()->for($application->candidateProfile)->create(['verified' => true]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->assertSee('Advisory: catalog inactive')->call('confirm', 'verify')->call('perform')->assertHasNoErrors();
    expect($application->fresh()->status)->toBe(ApplicationStatus::Verified);
});

test('existing results block every staff review mutation without historical changes', function (string $operation) {
    $application = reviewApplication($operation === 'start' ? ApplicationStatus::Submitted : ApplicationStatus::UnderReview);
    AdmissionResult::factory()->for($application->wishes()->sole())->create();
    $document = CandidateDocument::factory()->for($application)->create();
    $score = CandidateScore::factory()->for($application->candidateProfile)->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $review = app(StaffApplicationReview::class);
    $token = reviewToken($application);
    expect(fn () => match ($operation) {
        'start' => $review->start($application->id, $token),
        'revision' => $review->requestRevision($application->id, $token, ['revision_reason' => 'Revision']),
        'verify' => $review->verify($application->id, $token),
        'document' => $review->verifyDocument($application->id, $document->id, $token),
        'reject' => $review->rejectDocument($application->id, $document->id, $token, ['rejection_reason' => 'Replace']),
        'score' => $review->verifyScore($application->id, $score->id, $token),
    })->toThrow(ValidationException::class);
    $this->assertDatabaseCount('activity_logs', 0);
    $this->assertDatabaseCount('admission_results', 1);
})->with(['start', 'revision', 'verify', 'document', 'reject', 'score']);

test('cancelled application saves roll back review metadata and audit', function (string $operation) {
    $application = reviewApplication($operation === 'start' ? ApplicationStatus::Submitted : ApplicationStatus::UnderReview);
    $before = $application->getAttributes();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', $operation);
    if ($operation === 'revision') {
        $page->set('form.revision_reason', 'Revise');
    }
    Event::listen('eloquent.saving: '.Application::class, fn () => false);
    try {
        $page->call('perform')->assertHasErrors('review');
        expect($application->fresh()->getAttributes())->toBe($before);
        $this->assertDatabaseCount('activity_logs', 0);
    } finally {
        Event::forget('eloquent.saving: '.Application::class);
    }
})->with(['start', 'revision', 'verify']);
