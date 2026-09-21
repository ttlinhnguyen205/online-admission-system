<?php

use App\Actions\CandidateApplications;
use App\Actions\StaffApplicationReview;
use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Livewire\Candidate\Notifications;
use App\Models\Application;
use App\Models\CandidateScore;
use App\Models\User;
use App\Notifications\ApplicationReviewStarted;
use App\Notifications\ApplicationRevisionRequested;
use App\Notifications\ApplicationSubmitted;
use App\Notifications\CandidateScoreVerified;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/Staff/ReviewFixtures.php';

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
});

test('successful submission sends one private notification and a repeated submit sends none', function () {
    $application = reviewApplication(ApplicationStatus::NeedsRevision);
    $application->admissionRound->update(['status' => AdmissionRoundStatus::Open]);
    $application->candidateProfile->update([
        'citizen_id_front_path' => 'candidate-profiles/front.png',
        'citizen_id_back_path' => 'candidate-profiles/back.png',
    ]);
    $candidate = $application->candidateProfile->user;
    $this->actingAs($candidate);

    app(CandidateApplications::class)->submit($application->id);
    $notification = $candidate->notifications()->sole();
    expect($notification->type)->toBe(ApplicationSubmitted::class);
    expect($notification->data['application_id'])->toBe($application->id);
    Livewire::test(Notifications::class)->assertSee('Hồ sơ đã được tiếp nhận')
        ->assertSee(route('candidate.applications.show', $application->id));
    expect(fn () => app(CandidateApplications::class)->submit($application->id))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('notifications', 1);
});

test('rejected submission does not create a notification', function () {
    $application = reviewApplication(ApplicationStatus::NeedsRevision);
    $application->admissionRound->update(['status' => AdmissionRoundStatus::Open]);
    $this->actingAs($application->candidateProfile->user);

    expect(fn () => app(CandidateApplications::class)->submit($application->id))
        ->toThrow(ValidationException::class);
    expect($application->fresh()->status)->toBe(ApplicationStatus::NeedsRevision);
    $this->assertDatabaseCount('notifications', 0);
});

test('review start and revision each notify exactly once with an owned application link', function () {
    $application = reviewApplication(ApplicationStatus::Submitted);
    $candidate = $application->candidateProfile->user;
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $review = app(StaffApplicationReview::class);

    $review->start($application->id, reviewToken($application));
    expect($candidate->notifications()->where('type', ApplicationReviewStarted::class)->count())->toBe(1);
    expect(fn () => $review->start($application->id, reviewToken($application)))->toThrow(ValidationException::class);
    $review->requestRevision($application->id, reviewToken($application), ['revision_reason' => 'Thiếu giấy tờ']);
    expect($candidate->notifications()->where('type', ApplicationRevisionRequested::class)->count())->toBe(1);
    expect(fn () => $review->requestRevision($application->id, reviewToken($application), ['revision_reason' => 'Lặp lại']))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('notifications', 2);

    $this->actingAs($candidate);
    Livewire::test(Notifications::class)->assertSee('Hồ sơ đang được xét duyệt')
        ->assertSee('Hồ sơ cần bổ sung')->assertSee('Thiếu giấy tờ')
        ->assertSee(route('candidate.applications.show', $application->id));
});

test('score verification sends one notification only after readable evidence is verified', function () {
    $application = reviewApplication();
    $score = CandidateScore::factory()->for($application->candidateProfile)->create();
    $candidate = $application->candidateProfile->user;
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $review = app(StaffApplicationReview::class);

    expect(fn () => $review->verifyScore($application->id, $score->id, reviewToken($application)))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('notifications', 0);
    reviewScoreEvidence($score);
    $review->verifyScore($application->id, $score->id, reviewToken($application));
    expect($candidate->notifications()->sole()->type)->toBe(CandidateScoreVerified::class);
    expect(fn () => $review->verifyScore($application->id, $score->id, reviewToken($application)))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('notifications', 1);

    $this->actingAs($candidate);
    Livewire::test(Notifications::class)->assertSee('Minh chứng điểm đã được xác minh')
        ->assertSee(route('candidate.admission-information.index'))
        ->assertSee('Xem thông tin tuyển sinh');
});

test('a rolled back review transition does not leave a notification', function () {
    $application = reviewApplication(ApplicationStatus::Submitted);
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    Event::listen('eloquent.saving: '.Application::class, function (): void {
        throw new RuntimeException('Simulated persistence failure');
    });
    try {
        expect(fn () => app(StaffApplicationReview::class)->start($application->id, reviewToken($application)))
            ->toThrow(RuntimeException::class);
        $this->assertDatabaseCount('notifications', 0);
        expect($application->fresh()->status)->toBe(ApplicationStatus::Submitted);
    } finally {
        Event::forget('eloquent.saving: '.Application::class);
    }
});

test('a notification persistence failure rolls back a successful review mutation', function () {
    $application = reviewApplication(ApplicationStatus::Submitted);
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    Event::listen('eloquent.creating: '.DatabaseNotification::class, function (): void {
        throw new RuntimeException('Simulated notification persistence failure');
    });
    try {
        expect(fn () => app(StaffApplicationReview::class)->start($application->id, reviewToken($application)))
            ->toThrow(RuntimeException::class);
        $this->assertDatabaseCount('notifications', 0);
        expect($application->fresh()->status)->toBe(ApplicationStatus::Submitted);
    } finally {
        Event::forget('eloquent.creating: '.DatabaseNotification::class);
    }
});

test('a recognized notification cannot link to another candidates application', function () {
    $application = reviewApplication();
    $other = User::factory()->create();
    $other->notify(new ApplicationSubmitted($application->id));
    $this->actingAs($other);

    Livewire::test(Notifications::class)->assertSee('Hồ sơ đã được tiếp nhận')
        ->assertDontSee(route('candidate.applications.show', $application->id))
        ->assertDontSee('Xem hồ sơ');
});

test('unknown notification types have no action and cannot expose a supplied URL', function () {
    $application = reviewApplication();
    $candidate = $application->candidateProfile->user;
    $candidate->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'unknown.notification',
        'data' => ['url' => 'https://example.invalid/unsafe', 'application_id' => $application->id],
    ]);
    $this->actingAs($candidate);
    Livewire::test(Notifications::class)->assertSee('Thông báo')
        ->assertDontSee('https://example.invalid/unsafe')
        ->assertDontSee('Xem hồ sơ');
    expect(DatabaseNotification::count())->toBe(1);
});
