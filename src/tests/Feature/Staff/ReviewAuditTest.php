<?php

use App\Actions\StaffApplicationReview;
use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\ApplicationDetails;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\CandidateScore;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/ReviewFixtures.php';

test('review audit records exact safe workflow changes actor subject and server request metadata', function () {
    $this->freezeTime();
    $application = reviewApplication();
    $actor = User::factory()->create(['role' => UserRole::Staff]);
    $this->actingAs($actor);
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.2', 'HTTP_USER_AGENT' => str_repeat('a', 1200)]);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->call('confirm', 'revision')->set('form.revision_reason', 'Review reason')->call('perform')->assertHasNoErrors();
    $entry = ActivityLog::query()->sole();
    expect($entry->action)->toBe('application.revision_requested');
    expect($entry->user_id)->toBe($actor->id);
    expect($entry->subject_type)->toBe((new Application)->getMorphClass());
    expect($entry->subject_id)->toBe($application->id);
    expect($entry->old_values)->toBe(['status' => 'under_review', 'reviewed_by' => null, 'reviewed_at' => null, 'revision_reason' => null]);
    expect($entry->new_values)->toBe(['status' => 'needs_revision', 'reviewed_by' => $actor->id, 'reviewed_at' => now()->format('Y-m-d H:i:s'), 'revision_reason' => 'Review reason']);
    expect($entry->created_at->format('Y-m-d H:i:s'))->toBe(now()->format('Y-m-d H:i:s'));
    expect(strlen($entry->user_agent ?? ''))->toBeLessThanOrEqual(1000);
    expect(json_encode($entry->getAttributes()))->not->toContain('candidate-photos/')->not->toContain('citizen_id')->not->toContain('password');
});

test('audit persistence failure rolls back every review mutation', function (string $operation, bool $throw) {
    $application = reviewApplication($operation === 'start' ? ApplicationStatus::Submitted : ApplicationStatus::UnderReview);
    $document = CandidateDocument::factory()->for($application)->create();
    Storage::disk('candidate-private')->put($document->file_path, '%PDF-1.4');
    $score = CandidateScore::factory()->for($application->candidateProfile)->create();
    if ($operation === 'verify') {
        $document->update(['status' => 'verified']);
        $score->update(['verified' => true]);
    }
    $before = [$application->fresh()->getAttributes(), $document->fresh()->getAttributes(), $score->fresh()->getAttributes()];
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', $operation, match ($operation) {
        'verifyDocument', 'rejectDocument' => $document->id, 'verifyScore' => $score->id, default => null,
    });
    if ($operation === 'revision') {
        $page->set('form.revision_reason', 'Revise');
    }
    if ($operation === 'rejectDocument') {
        $page->set('form.rejection_reason', 'Replace');
    }
    Event::listen('eloquent.creating: '.ActivityLog::class, function () use ($throw) {
        if ($throw) {
            throw new RuntimeException('Audit unavailable');
        }

        return false;
    });
    try {
        if ($throw) {
            expect(fn () => $page->call('perform'))->toThrow(RuntimeException::class);
        } else {
            $page->call('perform')->assertHasErrors('review');
        }
        expect([$application->fresh()->getAttributes(), $document->fresh()->getAttributes(), $score->fresh()->getAttributes()])->toBe($before);
        $this->assertDatabaseCount('activity_logs', 0);
    } finally {
        Event::forget('eloquent.creating: '.ActivityLog::class);
    }
})->with(['start', 'revision', 'verify', 'verifyDocument', 'rejectDocument', 'verifyScore'])->with([true, false]);

test('document and score audit payloads contain only workflow values and their own subjects', function () {
    $application = reviewApplication();
    $document = CandidateDocument::factory()->for($application)->create();
    Storage::disk('candidate-private')->put($document->file_path, '%PDF-1.4 sensitive bytes');
    $score = CandidateScore::factory()->for($application->candidateProfile)->create();
    $actor = User::factory()->create(['role' => UserRole::Staff]);
    $this->actingAs($actor);
    $review = app(StaffApplicationReview::class);
    $review->verifyDocument($application->id, $document->id, reviewToken($application));
    $review->verifyScore($application->id, $score->id, reviewToken($application));
    $logs = ActivityLog::query()->orderBy('id')->get();
    expect($logs[0]->subject->is($document))->toBeTrue();
    expect(array_keys($logs[0]->new_values))->toBe(['status', 'verified_by', 'verified_at', 'rejection_reason']);
    expect($logs[1]->subject->is($score))->toBeTrue();
    expect($logs[1]->old_values)->toBe(['verified' => 0, 'verified_by' => null]);
    expect($logs[1]->new_values)->toBe(['verified' => true, 'verified_by' => $actor->id]);
    expect($logs->map(fn (ActivityLog $log) => $log->getAttributes())->toJson())
        ->not->toContain($document->file_path)->not->toContain('sensitive bytes')->not->toContain('score_type');
});

test('review actions call existing parent and child policy abilities', function (string $ability) {
    $application = reviewApplication();
    $document = CandidateDocument::factory()->for($application)->create();
    Storage::disk('candidate-private')->put($document->file_path, '%PDF-1.4');
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $token = reviewToken($application);
    Gate::before(fn (User $user, string $requested) => $requested === $ability ? false : null);
    expect(fn () => app(StaffApplicationReview::class)->verifyDocument($application->id, $document->id, $token))->toThrow(AuthorizationException::class);
    expect($document->fresh()->status->value)->toBe('pending');
    $this->assertDatabaseCount('activity_logs', 0);
})->with(['review', 'verify']);

test('review history survives reviewer deletion and never claims a missing actor name', function () {
    $application = reviewApplication();
    $actor = User::factory()->create(['role' => UserRole::Staff]);
    $this->actingAs($actor);
    app(StaffApplicationReview::class)->requestRevision($application->id, reviewToken($application), ['revision_reason' => '<script>history</script>']);
    $actor->delete();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->assertSee('Reviewer unavailable')
        ->assertDontSee('<script>history</script>', false);
    expect(ActivityLog::query()->sole()->user_id)->toBeNull();
});
