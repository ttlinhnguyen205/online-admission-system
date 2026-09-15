<?php

use App\Actions\StaffApplicationReview;
use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\ApplicationDetails;
use App\Livewire\Candidate\Scores;
use App\Models\ActivityLog;
use App\Models\CandidateScore;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/ReviewFixtures.php';

test('score verification preserves candidate data and records only supported verification metadata', function () {
    $application = reviewApplication();
    $score = CandidateScore::factory()->for($application->candidateProfile)->create(['score' => '1200.125']);
    $before = $score->fresh()->only(['candidate_profile_id', 'score_type', 'subject_code', 'subject_name', 'score', 'exam_year']);
    $actor = User::factory()->create(['role' => UserRole::Staff]);
    $this->actingAs($actor);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', 'verifyScore', $score->id)->call('perform')->assertHasNoErrors();
    expect($score->fresh()->verified)->toBeTrue();
    expect($score->fresh()->verified_by)->toBe($actor->id);
    expect($score->fresh()->only(array_keys($before)))->toBe($before);
    expect($score->fresh()->getAttributes())->not->toHaveKeys(['status', 'rejection_reason', 'verified_at']);
    expect(ActivityLog::query()->sole()->action)->toBe('score.verified');
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(Scores::class)->call('edit', $score->id)->assertForbidden();
});

test('already verified scores retain the original verifier', function () {
    $application = reviewApplication();
    $original = User::factory()->create(['role' => UserRole::Staff]);
    $score = CandidateScore::factory()->for($application->candidateProfile)->create(['verified' => true, 'verified_by' => $original->id]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    expect(fn () => app(StaffApplicationReview::class)->verifyScore($application->id, $score->id, reviewToken($application)))->toThrow(ValidationException::class);
    expect($score->fresh()->verified_by)->toBe($original->id);
    $this->assertDatabaseCount('activity_logs', 0);
});

test('foreign profile score cannot be reviewed under this application', function () {
    $application = reviewApplication();
    $score = CandidateScore::factory()->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', 'verifyScore', $score->id)->assertNotFound();
    expect($score->fresh()->verified)->toBeFalse();
});

test('stale score edits deletions and verification invalidate confirmation', function (string $change) {
    $application = reviewApplication();
    $score = CandidateScore::factory()->for($application->candidateProfile)->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', 'verifyScore', $score->id);
    match ($change) {
        'score' => $score->update(['score' => '7.000']),
        'type' => $score->update(['score_type' => 'sat']),
        'verified' => $score->update(['verified' => true]),
        'delete' => $score->delete(),
    };
    $page->call('perform')->assertHasErrors('review');
    $this->assertDatabaseCount('activity_logs', 0);
})->with(['score', 'type', 'verified', 'delete']);

test('score verification requires under review lifecycle', function (ApplicationStatus $state) {
    $application = reviewApplication($state);
    $score = CandidateScore::factory()->for($application->candidateProfile)->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    expect(fn () => app(StaffApplicationReview::class)->verifyScore($application->id, $score->id, reviewToken($application)))->toThrow(ValidationException::class);
    expect($score->fresh()->verified)->toBeFalse();
})->with(array_filter(ApplicationStatus::cases(), fn ($s) => $s !== ApplicationStatus::UnderReview));

test('candidate cannot verify and reviewers have no reject or unverify score ability', function () {
    $application = reviewApplication();
    $score = CandidateScore::factory()->for($application->candidateProfile)->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $token = reviewToken($application);
    expect(Gate::allows('reject', $score))->toBeFalse();
    expect(Gate::allows('unverify', $score))->toBeFalse();
    $this->actingAs($application->candidateProfile->user);
    expect(fn () => app(StaffApplicationReview::class)->verifyScore($application->id, $score->id, $token))->toThrow(HttpException::class);
    expect($score->fresh()->verified)->toBeFalse();
});

test('score confirmation refuses injected metadata', function (string $field) {
    $application = reviewApplication();
    $score = CandidateScore::factory()->for($application->candidateProfile)->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', 'verifyScore', $score->id)
        ->set('form.'.$field, 'injected')->call('perform')->assertHasErrors('form');
    expect($score->fresh()->verified)->toBeFalse();
})->with(['verified', 'verified_by', 'score', 'candidate_profile_id', 'verified_at']);
