<?php

use App\Actions\CandidateApplications;
use App\Actions\EvaluationRuleManagement;
use App\Actions\NativeRoundActivation;
use App\Actions\NativeWishRegistration;
use App\Actions\ProcessAdmissionRound;
use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Admin\AdmissionRounds;
use App\Livewire\Admin\NativeRoundRegistration;
use App\Livewire\Candidate\ApplicationDetails;
use App\Livewire\Candidate\NativeWishes;
use App\Livewire\Candidate\Profile;
use App\Models\ActivityLog;
use App\Models\AdmissionProgram;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateMajorOffering;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0, 0));
    config(['admission_registration.native_registration' => true]);
});

function activationAdmin(): User
{
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    test()->actingAs($admin);

    return $admin;
}

function activationRound(int $methods = 1): AdmissionRound
{
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open]);
    $first = AdmissionProgram::factory()->for($round)->create(['quota' => 0]);
    CandidateMajorOffering::factory()->for($first)->create();
    $rules = app(EvaluationRuleManagement::class);
    for ($index = 0; $index < $methods; $index++) {
        $program = $index === 0 ? $first : AdmissionProgram::factory()->for($round)->for($first->major)->create(['quota' => 0]);
        $payload = ['subjects' => ['MATH', 'PHYSICS', 'CHEMISTRY'], 'source_year' => 2026,
            'minimum_subject_score' => 0, 'minimum_total_score' => 0, 'policy_reference' => 'Test policy: three subjects'];
        if ($index > 0) {
            $payload['grade_level'] = 12;
        }
        $rule = $rules->createDraft($program->admission_method_id, $index === 0 ? 'THPT_SCORE' : 'TRANSCRIPT_SCORE', 1, $payload, 'Test policy');
        $rules->approve($rule->id);
        $rules->bind($program->id, $rule->id, null, 'Test binding');
    }

    return $round;
}

function activationChange(AdmissionRound $round, string $action): void
{
    $service = app(NativeRoundActivation::class);
    $current = $round->fresh();
    $service->transition($round->id, $action, $service->check($current)['fingerprint'], $current->code, true, 'Explicit demo scope');
}

function activationCandidate(): CandidateProfile
{
    $profile = CandidateProfile::factory()->create();
    $values = array_fill_keys(Profile::COMPLETION, 'fixture');
    $values['date_of_birth'] = '2008-01-02';
    $values['citizen_id_issued_date'] = '2022-01-02';
    $values['graduation_year'] = 2026;
    $values['citizen_id'] = fake()->unique()->numerify('############');
    $values['profile_status'] = ProfileStatus::Complete;
    $profile->update($values);
    test()->actingAs($profile->user);

    return $profile;
}

test('existing and new rounds remain legacy even when global flag is enabled', function () {
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open]);
    activationCandidate();
    $application = app(CandidateApplications::class)->create(['admission_round_id' => $round->id]);
    expect($round->fresh()->nativeRegistrationState())->toBe('legacy');
    expect($application->registration_mode)->toBe('legacy');
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->assertDontSee('Đăng ký theo ngành');
});

test('draft and closed native rounds and global OFF reject candidate creation', function (string $state, bool $enabled) {
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open, 'native_registration_state' => $state]);
    activationCandidate();
    config(['admission_registration.native_registration' => $enabled]);
    expect(fn () => app(CandidateApplications::class)->create(['admission_round_id' => $round->id]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('applications', 0);
})->with([['native_draft', true], ['native_closed', true], ['native_open', false]]);

test('activation requires explicit preparation confirmation and records audit without changing dates', function () {
    $admin = activationAdmin();
    $round = activationRound();
    $dates = $round->only(['start_date', 'end_date', 'status']);
    expect(fn () => activationChange($round, 'activate'))->toThrow(ValidationException::class);
    activationChange($round, 'prepare');
    expect($round->fresh()->nativeRegistrationState())->toBe('native_draft');
    activationChange($round, 'activate');
    $current = $round->fresh();
    expect($current->nativeRegistrationState())->toBe('native_open');
    expect($current->native_activated_by)->toBe($admin->id);
    expect($current->native_activated_at->format('Y-m-d H:i:s'))->toBe('2026-09-14 12:00:00');
    expect($current->only(['start_date', 'end_date', 'status']))->toEqual($dates);
    $this->assertDatabaseHas('activity_logs', ['action' => 'native_registration.activate', 'subject_id' => $round->id]);
    $this->assertDatabaseCount('admission_results', 0);
});

test('activation blocks incomplete catalog conflicts and engine history', function (string $conflict, string $message) {
    activationAdmin();
    $round = activationRound();
    activationChange($round, 'prepare');
    if ($conflict === 'rule') {
        $round->programs()->update(['evaluation_rule_version_id' => null]);
    }
    if ($conflict === 'offering') {
        CandidateMajorOffering::factory()->for(AdmissionProgram::factory()->for($round)->create())->create();
    }
    if ($conflict === 'legacy') {
        Application::factory()->for($round)->create();
    }
    if ($conflict === 'published') {
        $result = AdmissionResult::factory()->create(['published_at' => now(), 'confirmed_at' => now()]);
        $result->admissionWish->admissionProgram->update(['admission_round_id' => $round->id]);
    }
    if ($conflict === 'engine') {
        ActivityLog::query()->create(['user_id' => auth()->id(), 'action' => 'admission_engine.started',
            'subject_type' => $round->getMorphClass(), 'subject_id' => $round->id, 'created_at' => now()]);
    }
    if ($conflict === 'flag') {
        config(['admission_registration.native_registration' => false]);
    }
    $check = app(NativeRoundActivation::class)->check($round->fresh());
    expect(implode(' ', $check['blockers']))->toContain($message);
    expect(fn () => activationChange($round, 'activate'))->toThrow(ValidationException::class);
    expect($round->fresh()->nativeRegistrationState())->toBe('native_draft');
    $this->assertDatabaseMissing('activity_logs', ['action' => 'native_registration.activate']);
})->with([
    ['rule', 'NOT READY'], ['offering', 'NOT READY'], ['legacy', 'hồ sơ legacy'],
    ['published', 'công bố/xác nhận'], ['engine', 'engine run đang xử lý'], ['flag', 'OFF'],
]);

test('activation refuses unauthorized inactive and unverified admins', function (string $role) {
    $round = AdmissionRound::factory()->create();
    $actor = User::factory()->create(['role' => $role === 'staff' ? UserRole::Staff : ($role === 'candidate' ? UserRole::Candidate : UserRole::Admin),
        'status' => $role === 'inactive' ? UserStatus::Inactive : UserStatus::Active,
        'email_verified_at' => $role === 'unverified' ? null : now()]);
    $this->actingAs($actor);
    expect(fn () => activationChange($round, 'prepare'))->toThrow(AuthorizationException::class);
    Livewire::test(NativeRoundRegistration::class, ['roundId' => $round->id])->assertForbidden();
})->with(['candidate', 'staff', 'inactive', 'unverified']);

test('admin UI confirms scope and refuses a stale activation preview', function () {
    activationAdmin();
    $round = activationRound();
    Livewire::test(AdmissionRounds::class)->call('manageNativeRegistration', $round->id)->assertSee('Đăng ký nguyện vọng native');
    $page = Livewire::test(NativeRoundRegistration::class, ['roundId' => $round->id]);
    $page->call('confirm', 'prepare')->set('confirmationCode', 'WRONG')->set('reason', 'Demo')->set('confirmed', true)
        ->call('apply')->assertHasErrors('native');
    $page->set('confirmationCode', $round->code)->call('apply')->assertHasNoErrors();
    $page->call('confirm', 'activate')->set('confirmationCode', $round->code)->set('reason', 'Demo')->set('confirmed', true);
    $round->programs()->update(['evaluation_rule_version_id' => null]);
    $page->call('apply')->assertHasErrors('native');
    expect($round->fresh()->nativeRegistrationState())->toBe('native_draft');
});

test('native candidate end to end pins one and two method rules without scoring or allocation', function (int $methodCount) {
    Notification::fake();
    $admin = activationAdmin();
    $round = activationRound($methodCount);
    activationChange($round, 'prepare');
    activationChange($round, 'activate');
    $rules = $round->programs()->orderBy('id')->pluck('evaluation_rule_version_id')->all();
    activationCandidate();
    $application = app(CandidateApplications::class)->create(['admission_round_id' => $round->id]);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->assertSee('Đăng ký theo ngành');
    $offering = $round->candidateMajorOfferings()->sole();
    $page = Livewire::test(NativeWishes::class, ['applicationId' => $application->id]);
    $page->set('offeringId', (string) $offering->id)->call('addWish')->assertHasNoErrors()->call('submit')->assertHasNoErrors();
    $snapshot = $application->submissionSnapshots()->sole();
    $before = $snapshot->getAttributes();
    $bindingIds = $snapshot->entries()->sole()->bindings()->orderBy('admission_program_id')->pluck('evaluation_rule_version_id')->all();
    expect($bindingIds)->toBe($rules);
    expect($application->fresh()->status)->toBe(ApplicationStatus::Submitted);
    expect(fn () => app(NativeWishRegistration::class)->submit($application->id))->toThrow(AuthorizationException::class);
    expect(fn () => $snapshot->update(['manifest' => []]))->toThrow(ValidationException::class);
    expect($application->submissionSnapshots()->count())->toBe(1);
    $this->actingAs($admin);
    app(EvaluationRuleManagement::class)->retire($rules[0], 'Retire after submit');
    activationChange($round, 'close');
    expect($snapshot->fresh()->getAttributes())->toBe($before);
    expect(fn () => app(ProcessAdmissionRound::class)->preview($round->id))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('admission_wishes', 0);
    $this->assertDatabaseCount('candidate_scores', 0);
})->with([1, 2]);

test('close blocks stale candidate mutations and preserves history', function (string $operation) {
    $admin = activationAdmin();
    $round = activationRound();
    activationChange($round, 'prepare');
    activationChange($round, 'activate');
    $profile = activationCandidate();
    $application = app(CandidateApplications::class)->create(['admission_round_id' => $round->id]);
    $native = app(NativeWishRegistration::class);
    $offering = $round->candidateMajorOfferings()->sole();
    $native->add($application->id, $offering->id);
    $wish = $application->nativeWishes()->sole();
    $this->actingAs($admin);
    activationChange($round, 'close');
    $this->actingAs($profile->user);
    $action = match ($operation) {
        'add' => fn () => $native->add($application->id, $offering->id),
        'delete' => fn () => $native->delete($application->id, $wish->id, [$wish->id]),
        'reorder' => fn () => $native->reorder($application->id, [$wish->id], [$wish->id]),
        'submit' => fn () => $native->submit($application->id),
    };
    expect($action)->toThrow(HttpException::class);
    Livewire::test(NativeWishes::class, ['applicationId' => $application->id])->assertSee('Đăng ký native đã đóng')->assertDontSee('Thêm nguyện vọng');
    expect($application->nativeWishes()->count())->toBe(1);
    expect($application->submissionSnapshots()->count())->toBe(0);
})->with(['add', 'delete', 'reorder', 'submit']);

test('native manual requests reject before and after the application period', function (string $time) {
    activationAdmin();
    $round = activationRound();
    activationChange($round, 'prepare');
    activationChange($round, 'activate');
    activationCandidate();
    $application = app(CandidateApplications::class)->create(['admission_round_id' => $round->id]);
    $this->travelTo($time);
    expect(fn () => app(NativeWishRegistration::class)->add($application->id, $round->candidateMajorOfferings()->sole()->id))->toThrow(HttpException::class);
    expect(fn () => app(CandidateApplications::class)->create(['admission_round_id' => $round->id]))->toThrow(ValidationException::class);
})->with(['2025-12-31 12:00:00', '2027-01-01 12:00:00']);

test('native registration rejects another owner duplicate major and global OFF mutations', function () {
    activationAdmin();
    $round = activationRound();
    activationChange($round, 'prepare');
    activationChange($round, 'activate');
    $profile = activationCandidate();
    $application = app(CandidateApplications::class)->create(['admission_round_id' => $round->id]);
    $native = app(NativeWishRegistration::class);
    $offeringId = $round->candidateMajorOfferings()->sole()->id;
    $native->add($application->id, $offeringId);
    expect(fn () => $native->add($application->id, $offeringId))->toThrow(ValidationException::class);
    activationCandidate();
    expect(fn () => $native->add($application->id, $offeringId))->toThrow(ModelNotFoundException::class);
    $this->actingAs($profile->user);
    config(['admission_registration.native_registration' => false]);
    expect(fn () => $native->submit($application->id))->toThrow(HttpException::class);
    expect($application->nativeWishes()->count())->toBe(1);
});

test('native round never rolls back to legacy and engine fails closed even without applications', function () {
    activationAdmin();
    $round = activationRound();
    activationChange($round, 'prepare');
    $round = $round->fresh();
    $round->setAttribute('native_registration_state', 'legacy');
    expect(fn () => $round->save())->toThrow(ValidationException::class);
    expect(fn () => app(ProcessAdmissionRound::class)->preview($round->id))->toThrow(ValidationException::class);
    expect(fn () => app(ProcessAdmissionRound::class)->process($round->id, 'stale'))->toThrow(ValidationException::class);
    expect($round->fresh()->nativeRegistrationState())->toBe('native_draft');
    $this->assertDatabaseCount('admission_results', 0);
});

test('candidate sorts and deletes wishes and cannot submit a stale method catalog', function () {
    Notification::fake();
    $admin = activationAdmin();
    $round = activationRound(2);
    $secondOfferingProgram = AdmissionProgram::factory()->for($round)->create(['status' => 'inactive']);
    $secondOffering = CandidateMajorOffering::factory()->for($secondOfferingProgram)->create(['is_selectable' => false]);
    activationChange($round, 'prepare');
    activationChange($round, 'activate');
    $profile = activationCandidate();
    $application = app(CandidateApplications::class)->create(['admission_round_id' => $round->id]);
    $offering = $round->candidateMajorOfferings()->whereKeyNot($secondOffering->id)->sole();
    $page = Livewire::test(NativeWishes::class, ['applicationId' => $application->id])
        ->set('offeringId', (string) $offering->id)->call('addWish')->assertHasNoErrors();
    $this->actingAs($admin);
    $program = $round->programs()->where('status', 'active')->firstOrFail();
    $rules = app(EvaluationRuleManagement::class);
    $next = $rules->newVersion($program->evaluation_rule_version_id, 'Policy update');
    $rules->approve($next->id);
    $rules->bind($program->id, $next->id, $program->evaluation_rule_version_id, 'Policy update');
    $this->actingAs($profile->user);
    $page->call('submit')->assertHasErrors('wishes');
    expect($application->submissionSnapshots()->count())->toBe(0);
    $page->call('reloadWishes')->call('submit')->assertHasNoErrors();
    expect($application->submissionSnapshots()->sole()->entries()->sole()->bindings()->where('evaluation_rule_version_id', $next->id)->exists())->toBeTrue();
});

test('candidate reorders and deletes native wishes in an activated round', function () {
    activationAdmin();
    $round = activationRound();
    $program = AdmissionProgram::factory()->for($round)->create();
    $second = CandidateMajorOffering::factory()->for($program)->create();
    $payload = ['subjects' => ['MATH', 'PHYSICS', 'CHEMISTRY'], 'source_year' => 2026,
        'minimum_subject_score' => 0, 'minimum_total_score' => 0, 'policy_reference' => 'Test policy'];
    $rules = app(EvaluationRuleManagement::class);
    $rule = $rules->createDraft($program->admission_method_id, 'THPT_SCORE', 1, $payload, 'Test');
    $rules->approve($rule->id);
    $rules->bind($program->id, $rule->id, null, 'Test');
    activationChange($round, 'prepare');
    activationChange($round, 'activate');
    activationCandidate();
    $application = app(CandidateApplications::class)->create(['admission_round_id' => $round->id]);
    $first = $round->candidateMajorOfferings()->whereKeyNot($second->id)->sole();
    $page = Livewire::test(NativeWishes::class, ['applicationId' => $application->id]);
    foreach ([$first, $second] as $offering) {
        $page->set('offeringId', (string) $offering->id)->call('addWish')->assertHasNoErrors();
    }
    $wish = $application->nativeWishes()->where('candidate_major_offering_id', $second->id)->sole();
    $page->call('moveWish', $wish->id, 'up')->assertHasNoErrors();
    expect($application->nativeWishes()->orderBy('priority')->pluck('candidate_major_offering_id')->all())->toBe([$second->id, $first->id]);
    $page->call('deleteWish', $wish->id)->assertHasNoErrors();
    expect($application->nativeWishes()->sole()->priority)->toBe(1);
});

test('native draft blocks native mutations and cannot fall back to legacy', function () {
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open, 'native_registration_state' => 'native_draft']);
    $profile = activationCandidate();
    $application = Application::factory()->for($round)->for($profile)->create(['registration_mode' => 'native']);
    expect(fn () => app(NativeWishRegistration::class)->submit($application->id))->toThrow(HttpException::class);
    expect($application->submissionSnapshots()->count())->toBe(0);
    expect(fn () => $application->update(['registration_mode' => 'legacy']))->toThrow(ValidationException::class);
});

test('unconfirmed activation has no writes and stale catalog checks cannot be reused', function () {
    activationAdmin();
    $round = activationRound();
    $service = app(NativeRoundActivation::class);
    $fingerprint = $service->check($round->fresh())['fingerprint'];
    expect(fn () => $service->transition($round->id, 'prepare', $fingerprint, $round->code, false, 'Demo'))->toThrow(ValidationException::class);
    $round->candidateMajorOfferings()->update(['is_selectable' => false]);
    expect(fn () => $service->transition($round->id, 'prepare', $fingerprint, $round->code, true, 'Demo'))->toThrow(ValidationException::class);
    expect($round->fresh()->nativeRegistrationState())->toBe('legacy');
    $this->assertDatabaseMissing('activity_logs', ['action' => 'native_registration.prepare']);
});
