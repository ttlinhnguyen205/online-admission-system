<?php

use App\Actions\CandidateApplications;
use App\Actions\StaffApplicationReview;
use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\ApplicationDetails;
use App\Livewire\Candidate\Documents;
use App\Livewire\Candidate\Scores;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\CandidateScore;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/ReviewFixtures.php';

test('two reviewers cannot both start the same submission', function () {
    $application = reviewApplication(ApplicationStatus::Submitted);
    $first = User::factory()->create(['role' => UserRole::Staff]);
    $second = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($first);
    $token = reviewToken($application);
    app(StaffApplicationReview::class)->start($application->id, $token);
    $this->actingAs($second);
    expect(fn () => app(StaffApplicationReview::class)->start($application->id, $token))->toThrow(ValidationException::class);
    expect($application->fresh()->reviewed_by)->toBe($first->id);
    $this->assertDatabaseCount('activity_logs', 1);
});

test('competing verify and revision requests have exactly one committed outcome', function (string $firstOperation) {
    $application = reviewApplication();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $review = app(StaffApplicationReview::class);
    $token = reviewToken($application);
    if ($firstOperation === 'verify') {
        $review->verify($application->id, $token);
        expect(fn () => $review->requestRevision($application->id, $token, ['revision_reason' => 'Too late']))->toThrow(ValidationException::class);
    } else {
        $review->requestRevision($application->id, $token, ['revision_reason' => 'Revise']);
        expect(fn () => $review->verify($application->id, $token))->toThrow(ValidationException::class);
    }
    expect($application->fresh()->status)->toBe($firstOperation === 'verify' ? ApplicationStatus::Verified : ApplicationStatus::NeedsRevision);
    $this->assertDatabaseCount('activity_logs', 1);
})->with(['verify', 'revision']);

test('changed review records and child sets invalidate an open final confirmation', function (string $change) {
    $application = reviewApplication();
    $score = CandidateScore::factory()->for($application->candidateProfile)->create(['verified' => true]);
    $document = CandidateDocument::factory()->for($application)->create(['status' => 'verified']);
    Storage::disk('candidate-private')->put($document->file_path, '%PDF-1.4');
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', 'verify');
    match ($change) {
        'profile' => $application->candidateProfile->update(['address' => 'Changed after reading']),
        'identity' => $application->candidateProfile->user->update(['name' => 'Changed name']),
        'score added' => CandidateScore::factory()->for($application->candidateProfile)->create(['verified' => true]),
        'score deleted' => $score->delete(),
        'document added' => CandidateDocument::factory()->for($application)->create(),
        'document deleted' => $document->delete(),
        'wish added' => AdmissionWish::factory()->for($application)->create(['priority' => 2]),
        'wish reordered' => $application->wishes()->update(['priority' => 2]),
    };
    $page->call('perform')->assertHasErrors('review');
    expect($application->fresh()->status)->toBe(ApplicationStatus::UnderReview);
    $this->assertDatabaseCount('activity_logs', 0);
})->with(['profile', 'identity', 'score added', 'score deleted', 'document added', 'document deleted', 'wish added', 'wish reordered']);

test('a fresh authorized colleague can continue a review without reviewer assignment', function () {
    $application = reviewApplication(ApplicationStatus::Submitted);
    $first = User::factory()->create(['role' => UserRole::Staff]);
    $second = User::factory()->create(['role' => UserRole::Staff]);
    $this->actingAs($first);
    app(StaffApplicationReview::class)->start($application->id, reviewToken($application));
    $this->actingAs($second);
    app(StaffApplicationReview::class)->verify($application->id, reviewToken($application));
    expect($application->fresh()->reviewed_by)->toBe($second->id);
    expect($application->fresh()->status)->toBe(ApplicationStatus::Verified);
    $this->assertDatabaseCount('activity_logs', 2);
});

test('audit cycle identity invalidates an old token even when all application workflow values repeat within a second', function () {
    $this->travelTo(now()->setDate(2026, 9, 14)->startOfSecond());
    $application = reviewApplication(ApplicationStatus::Submitted);
    $application->update(['submitted_at' => now(), 'revision_reason' => 'Same reason']);
    $application->admissionRound->update(['status' => 'open']);
    $actor = User::factory()->create(['role' => UserRole::Staff]);
    $this->actingAs($actor);
    $review = app(StaffApplicationReview::class);
    $review->start($application->id, reviewToken($application));
    $old = reviewToken($application);
    $workflow = $application->fresh()->getAttributes();
    $review->requestRevision($application->id, $old, ['revision_reason' => 'Same reason']);
    $this->actingAs($application->candidateProfile->user);
    app(CandidateApplications::class)->submit($application->id);
    $this->actingAs($actor);
    $review->start($application->id, reviewToken($application));
    expect($application->fresh()->getAttributes())->toBe($workflow);
    expect(reviewToken($application))->not->toBe($old);
    expect(fn () => $review->verify($application->id, $old))->toThrow(ValidationException::class);
    expect($application->fresh()->status)->toBe(ApplicationStatus::UnderReview);
});

test('candidate revision replacement and resubmission preserve existing file workflow and reject stale review', function () {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
    $application = reviewApplication();
    $application->admissionRound->update(['status' => 'open']);
    $document = CandidateDocument::factory()->for($application)->create(['status' => 'verified', 'verified_at' => now()]);
    Storage::disk('candidate-private')->put($document->file_path, '%PDF-1.4 old');
    $actor = User::factory()->create(['role' => UserRole::Staff]);
    $this->actingAs($actor);
    $old = reviewToken($application);
    app(StaffApplicationReview::class)->requestRevision($application->id, $old, ['revision_reason' => 'Replace evidence']);
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(Documents::class, ['application' => $application->id])->call('edit', $document->id)
        ->set('file', UploadedFile::fake()->createWithContent('new.pdf', "%PDF-1.4\n%%EOF"))->call('save')->assertHasNoErrors();
    expect($document->fresh()->status->value)->toBe('pending');
    expect($document->fresh()->verified_at)->toBeNull();
    app(CandidateApplications::class)->submit($application->id);
    expect($application->fresh()->status)->toBe(ApplicationStatus::Submitted);
    expect($application->fresh()->revision_reason)->toBe('Replace evidence');
    $this->actingAs($actor);
    expect(fn () => app(StaffApplicationReview::class)->verifyDocument($application->id, $document->id, $old))->toThrow(ValidationException::class);
    app(StaffApplicationReview::class)->start($application->id, reviewToken($application));
    app(StaffApplicationReview::class)->verifyDocument($application->id, $document->id, reviewToken($application));
    expect($document->fresh()->status->value)->toBe('verified');
});

test('actual candidate score edits invalidate staff verification and staff verification blocks an open candidate editor', function () {
    $application = reviewApplication();
    $score = CandidateScore::factory()->for($application->candidateProfile)->create();
    reviewScoreEvidence($score);
    $reviewer = User::factory()->create(['role' => UserRole::Staff]);
    $this->actingAs($reviewer);
    $token = reviewToken($application);
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(Scores::class)->call('edit', $score->id)->set('form.score', '7.250')->call('save')->assertHasNoErrors();
    $this->actingAs($reviewer);
    expect(fn () => app(StaffApplicationReview::class)->verifyScore($application->id, $score->id, $token))->toThrow(ValidationException::class);
    app(StaffApplicationReview::class)->verifyScore($application->id, $score->id, reviewToken($application));
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(Scores::class)->call('edit', $score->id)->assertForbidden();
    expect($score->fresh()->score)->toBe('7.250');
});

test('staff verification blocks candidate score dialogs opened before the decision', function (string $operation) {
    $application = reviewApplication();
    $score = CandidateScore::factory()->for($application->candidateProfile)->create(['score' => '6.500']);
    reviewScoreEvidence($score);
    $reviewer = User::factory()->create(['role' => UserRole::Staff]);
    $candidate = $application->candidateProfile->user;
    $this->actingAs($candidate);
    $page = Livewire::test(Scores::class)->call($operation === 'save' ? 'edit' : 'confirmDeletion', $score->id);
    if ($operation === 'save') {
        $page->set('form.score', '9.000');
    }
    $this->actingAs($reviewer);
    app(StaffApplicationReview::class)->verifyScore($application->id, $score->id, reviewToken($application));
    $this->actingAs($candidate);

    $page->call($operation)->assertForbidden();

    $this->assertDatabaseHas('candidate_scores', ['id' => $score->id, 'score' => '6.500', 'verified' => true, 'verified_by' => $reviewer->id]);
    $this->assertDatabaseCount('activity_logs', 1);
})->with(['save', 'delete']);

test('application token cannot be reused against another application id', function () {
    $application = reviewApplication();
    $other = Application::factory()->create(['status' => 'under_review', 'submitted_at' => now()]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    expect(fn () => app(StaffApplicationReview::class)->requestRevision($other->id, reviewToken($application), ['revision_reason' => 'Wrong context']))->toThrow(ValidationException::class);
    expect($other->fresh()->status)->toBe(ApplicationStatus::UnderReview);
    $this->assertDatabaseCount('activity_logs', 0);
});

test('a stale component requires an explicit reload before a new decision', function () {
    $application = reviewApplication();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id]);
    $application->candidateProfile->update(['phone' => '0909999999']);
    $page->call('confirm', 'verify')->assertHasErrors('review');
    $page->call('reloadReview')->call('confirm', 'verify')->call('perform')->assertHasNoErrors();
    expect($application->fresh()->status)->toBe(ApplicationStatus::Verified);
});
