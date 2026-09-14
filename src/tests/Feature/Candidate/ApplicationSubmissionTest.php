<?php

use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Livewire\Candidate\ApplicationDetails;
use App\Livewire\Candidate\Documents;
use App\Livewire\Candidate\Profile;
use App\Models\AdmissionProgram;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
});

function readyApplication(): Application
{
    $application = Application::factory()->create();
    $application->admissionRound->update(['status' => AdmissionRoundStatus::Open]);
    $application->candidateProfile->update([
        'profile_status' => ProfileStatus::Complete, 'date_of_birth' => '2008-01-02', 'gender' => 'Female',
        'citizen_id' => fake()->unique()->numerify('############'), 'phone' => '0901234567', 'address' => 'Hanoi',
        'province_code' => '01', 'high_school_name' => 'Demo school', 'graduation_year' => 2026, 'photo_path' => 'candidate-photos/example.png',
    ]);
    AdmissionWish::factory()->for($application)->create();

    return $application;
}

test('draft and needs revision submit with zero documents and preserve review metadata', function (ApplicationStatus $status) {
    $application = readyApplication();
    $reviewer = User::factory()->create(['role' => UserRole::Staff]);
    $application->update([
        'status' => $status, 'submitted_at' => $status === ApplicationStatus::NeedsRevision ? now()->subDay() : null,
        'reviewed_by' => $reviewer->id, 'reviewed_at' => now()->subHour(), 'revision_reason' => 'Previous review note',
    ]);
    $wish = $application->wishes()->sole();
    $originalWish = $wish->getAttributes();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmSubmission')->assertSet('showSubmission', true)
        ->call('submit')->assertHasNoErrors()->assertSet('showSubmission', false)->assertSee('Read-only');
    $application->refresh();
    expect($application->status)->toBe(ApplicationStatus::Submitted);
    expect($application->submitted_at->format('Y-m-d H:i:s'))->toBe('2026-09-14 12:00:00');
    expect($application->reviewed_by)->toBe($reviewer->id);
    expect($application->reviewed_at->format('Y-m-d H:i:s'))->toBe('2026-09-14 11:00:00');
    expect($application->revision_reason)->toBe('Previous review note');
    expect($wish->fresh()->getAttributes())->toBe($originalWish);
    $this->assertDatabaseCount('candidate_documents', 0);
    $this->travel(1)->minutes();
    $page->call('submit')->assertForbidden();
    expect($application->fresh()->submitted_at->format('Y-m-d H:i:s'))->toBe('2026-09-14 12:00:00');
})->with([ApplicationStatus::Draft, ApplicationStatus::NeedsRevision]);

test('submission requires saved completion status without requiring staff verification', function (ProfileStatus $status) {
    $application = readyApplication();
    $application->candidateProfile->update(['profile_status' => $status]);
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('submit');
    if ($status === ProfileStatus::Incomplete) {
        $page->assertHasErrors('profile');
        expect($application->fresh()->status)->toBe(ApplicationStatus::Draft);
        expect($application->fresh()->submitted_at)->toBeNull();
    } else {
        $page->assertHasNoErrors();
        expect($application->fresh()->status)->toBe(ApplicationStatus::Submitted);
    }
})->with(ProfileStatus::cases());

test('submission rechecks every persisted completion field even on verified profiles', function (string $field) {
    $application = readyApplication();
    $application->candidateProfile->update(['profile_status' => ProfileStatus::Verified]);
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmSubmission');
    $application->candidateProfile->update([$field => null]);
    $page->call('submit')->assertHasErrors('profile');
    expect($application->fresh()->status)->toBe(ApplicationStatus::Draft);
    expect($application->fresh()->submitted_at)->toBeNull();
})->with(Profile::COMPLETION);

test('submission rejects an application without wishes', function () {
    $application = readyApplication();
    $application->wishes()->delete();
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('submit')->assertHasErrors('wishes')->assertSee('Add at least one admission wish');
    expect($application->fresh()->status)->toBe(ApplicationStatus::Draft);
});

test('submission revalidates every program after confirmation', function (string $condition) {
    $application = readyApplication();
    $wish = AdmissionWish::factory()->for($application)->create(['priority' => 2]);
    $program = $wish->admissionProgram;
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmSubmission');
    match ($condition) {
        'cross-round' => $wish->update(['admission_program_id' => AdmissionProgram::factory()->create()->id]),
        'inactive-program' => $program->update(['status' => 'inactive']),
        'inactive-major' => $program->major->update(['is_active' => false]),
        'inactive-method' => $program->admissionMethod->update(['is_active' => false]),
        'zero-quota' => $program->update(['quota' => 0]),
    };
    $page->call('submit')->assertHasErrors('wishes');
    expect($application->fresh()->status)->toBe(ApplicationStatus::Draft);
    expect($application->fresh()->submitted_at)->toBeNull();
    $this->assertModelExists($wish);
})->with(['cross-round', 'inactive-program', 'inactive-major', 'inactive-method', 'zero-quota']);

test('submission rejects noncontiguous priorities rather than silently changing preferences', function () {
    $application = readyApplication();
    $application->wishes()->sole()->update(['priority' => 7]);
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('submit')->assertHasErrors('wishes');
    expect($application->fresh()->status)->toBe(ApplicationStatus::Draft);
    expect($application->wishes()->sole()->priority)->toBe(7);
});

test('submission and resubmission reject every nonopen round status', function (AdmissionRoundStatus $roundStatus, ApplicationStatus $status) {
    $application = readyApplication();
    $application->update(['status' => $status]);
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmSubmission');
    $application->admissionRound->update(['status' => $roundStatus]);
    $page->call('submit')->assertHasErrors('round');
    expect($application->fresh()->status)->toBe($status);
    expect($application->fresh()->submitted_at)->toBeNull();
})->with([AdmissionRoundStatus::Draft, AdmissionRoundStatus::Closed, AdmissionRoundStatus::Processing, AdmissionRoundStatus::Published])
    ->with([ApplicationStatus::Draft, ApplicationStatus::NeedsRevision]);

test('submission timestamps honor inclusive round boundaries and configured timezones', function (string $time, bool $allowed, string $timezone) {
    config(['app.timezone' => $timezone]);
    $this->travelTo(CarbonImmutable::parse($time, $timezone));
    $application = readyApplication();
    $application->admissionRound->update(['year' => 2000, 'start_date' => '2000-12-31 23:59:00', 'end_date' => '2001-01-01 00:01:00']);
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('submit');
    if ($allowed) {
        $page->assertHasNoErrors();
        expect($application->fresh()->submitted_at->format('Y-m-d H:i:s'))->toBe($time);
    } else {
        $page->assertHasErrors('round');
        expect($application->fresh()->status)->toBe(ApplicationStatus::Draft);
    }
})->with([['2000-12-31 23:58:59', false], ['2000-12-31 23:59:00', true], ['2001-01-01 00:01:00', true], ['2001-01-01 00:01:01', false]])
    ->with(['UTC', 'Asia/Ho_Chi_Minh']);

test('submission rechecks stale application lifecycle and denies all later statuses', function (ApplicationStatus $status) {
    $application = readyApplication();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmSubmission');
    $application->update(['status' => $status]);
    $page->call('submit')->assertForbidden();
    expect($application->fresh()->status)->toBe($status);
    expect($application->fresh()->submitted_at)->toBeNull();
})->with([ApplicationStatus::Submitted, ApplicationStatus::UnderReview, ApplicationStatus::Verified, ApplicationStatus::Processing, ApplicationStatus::Completed]);

test('submission save failure rolls back timestamps and status', function () {
    $application = readyApplication();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id]);
    Event::listen('eloquent.saving: '.Application::class, fn () => false);
    try {
        $page->call('submit')->assertHasErrors('submission');
        expect($application->fresh()->status)->toBe(ApplicationStatus::Draft);
        expect($application->fresh()->submitted_at)->toBeNull();
    } finally {
        Event::forget('eloquent.saving: '.Application::class);
    }
});

test('submission locks documents by lifecycle and leaves closed round draft document permissions unchanged', function () {
    $application = readyApplication();
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('submit')->assertHasNoErrors();
    Livewire::test(Documents::class, ['application' => $application->id])->call('create')->assertForbidden();
    $application->update(['status' => ApplicationStatus::NeedsRevision]);
    $application->admissionRound->update(['status' => AdmissionRoundStatus::Closed]);
    Livewire::test(Documents::class, ['application' => $application->id])->call('create')->assertHasNoErrors()->assertSet('showEditor', true);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('submit')->assertHasErrors('round');
});

test('submission requirements do not invent score verification or document requirements', function () {
    $application = readyApplication();
    $this->assertDatabaseCount('candidate_scores', 0);
    $this->assertDatabaseCount('candidate_documents', 0);
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('submit')->assertHasNoErrors();
    expect($application->fresh()->status)->toBe(ApplicationStatus::Submitted);
});

test('submission rejects injected workflow ownership and unexpected form fields', function (string $field) {
    $application = readyApplication();
    $original = $application->fresh()->getAttributes();
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->set('form.'.$field, 'tampered')->call('submit')->assertHasErrors('form');
    expect($application->fresh()->getAttributes())->toBe($original);
})->with(['status', 'submitted_at', 'reviewed_by', 'reviewed_at', 'revision_reason', 'application_code', 'candidate_profile_id', 'admission_round_id', 'unexpected']);

test('submission must authorize its focused policy ability', function () {
    $application = readyApplication();
    $this->actingAs($application->candidateProfile->user);
    Gate::before(fn (User $user, string $ability) => $ability === 'submit' ? false : null);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('submit')->assertForbidden();
    expect($application->fresh()->status)->toBe(ApplicationStatus::Draft);
    expect($application->fresh()->submitted_at)->toBeNull();
});

test('locked lifecycle state prevents submission even if a gate grants broader access', function () {
    $application = readyApplication();
    $application->update(['status' => ApplicationStatus::UnderReview]);
    $this->actingAs($application->candidateProfile->user);
    Gate::before(fn () => true);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('submit')->assertForbidden();
    expect($application->fresh()->status)->toBe(ApplicationStatus::UnderReview);
    expect($application->fresh()->submitted_at)->toBeNull();
});
