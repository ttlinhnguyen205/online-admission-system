<?php

use App\Actions\CandidateApplications;
use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\StaffApplicationSubmitted;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Staff/ReviewFixtures.php';

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
});

test('submission notifies active verified staff once and displays the application code on their page', function () {
    $staff = User::factory()->count(2)->create(['role' => UserRole::Staff]);
    $excluded = collect([
        User::factory()->create(['role' => UserRole::Admin]),
        User::factory()->create(['role' => UserRole::Staff, 'status' => UserStatus::Locked]),
        User::factory()->create(['role' => UserRole::Staff, 'status' => UserStatus::Inactive]),
        User::factory()->unverified()->create(['role' => UserRole::Staff]),
    ]);
    $application = reviewApplication(ApplicationStatus::NeedsRevision);
    $application->admissionRound->update(['status' => AdmissionRoundStatus::Open]);
    $this->actingAs($application->candidateProfile->user);

    app(CandidateApplications::class)->submit($application->id);

    foreach ($staff as $recipient) {
        $notification = $recipient->notifications()->sole();
        expect($notification->type)->toBe(StaffApplicationSubmitted::class);
        expect($notification->data['application_id'])->toBe($application->id);
    }
    foreach ($excluded as $recipient) {
        expect($recipient->notifications()->count())->toBe(0);
    }
    expect(fn () => app(CandidateApplications::class)->submit($application->id))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('notifications', 3);

    $this->actingAs($staff->first())->get(route('notifications.index'))
        ->assertSeeText('Có hồ sơ mới cần xét duyệt')
        ->assertSeeText($application->application_code);
});

test('failed submission does not notify staff', function () {
    $staff = User::factory()->create(['role' => UserRole::Staff]);
    $application = reviewApplication(ApplicationStatus::NeedsRevision);
    $application->admissionRound->update(['status' => AdmissionRoundStatus::Open]);
    $application->wishes()->delete();
    $this->actingAs($application->candidateProfile->user);

    expect(fn () => app(CandidateApplications::class)->submit($application->id))->toThrow(ValidationException::class);

    expect($staff->notifications()->count())->toBe(0);
    expect($application->fresh()->status)->toBe(ApplicationStatus::NeedsRevision);
});
