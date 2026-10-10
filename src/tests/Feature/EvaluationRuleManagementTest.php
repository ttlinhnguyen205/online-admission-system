<?php

use App\Actions\AdmissionEngineSnapshot;
use App\Actions\EvaluationRuleManagement;
use App\Actions\EvaluationTemplateRegistry;
use App\Actions\NativeRegistrationReadiness;
use App\Actions\NativeWishRegistration;
use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Admin\AdmissionPrograms;
use App\Livewire\Admin\CandidateMajorOfferings;
use App\Livewire\Admin\EvaluationRules;
use App\Livewire\Candidate\Profile;
use App\Models\ActivityLog;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateMajorOffering;
use App\Models\EvaluationRuleVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function evaluationPayload(): array
{
    return ['subjects' => ['MATH', 'PHYSICS', 'CHEMISTRY'], 'source_year' => 2026,
        'minimum_subject_score' => 1, 'minimum_total_score' => 18, 'policy_reference' => 'Test-only policy: three subject sum'];
}

function evaluationAdmin(): User
{
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    test()->actingAs($admin);

    return $admin;
}

function evaluationDraft(?AdmissionMethod $method = null, string $template = 'THPT_SCORE'): EvaluationRuleVersion
{
    $method ??= AdmissionMethod::factory()->create();
    $payload = evaluationPayload();
    if ($template === 'TRANSCRIPT_SCORE') {
        $payload['grade_level'] = 12;
    }

    return app(EvaluationRuleManagement::class)->createDraft($method->id, $template, 1, $payload, 'Test configuration');
}

test('admin creates and edits draft without approval or enabling native', function () {
    evaluationAdmin();
    config(['admission_registration.native_registration' => false]);
    $rule = evaluationDraft();
    $payload = evaluationPayload();
    $payload['minimum_total_score'] = 20;

    app(EvaluationRuleManagement::class)->updateDraft($rule->id, $payload, 'Raise threshold');

    expect($rule->fresh()->status)->toBe('draft');
    expect($rule->fresh()->payload['minimum_total_score'])->toBe(20);
    expect($rule->fresh()->approved_at)->toBeNull();
    expect(NativeWishRegistration::enabled())->toBeFalse();
    $this->assertDatabaseHas('activity_logs', ['action' => 'evaluation_rule.draft_updated', 'subject_id' => $rule->id]);
});

test('approval seals validated payload and records approver and audit', function () {
    $admin = evaluationAdmin();
    $rule = evaluationDraft();
    $this->travelTo(now()->setTime(12, 0));

    app(EvaluationRuleManagement::class)->approve($rule->id);

    $approved = $rule->fresh();
    expect($approved->status)->toBe('approved');
    expect($approved->approved_by)->toBe($admin->id);
    expect($approved->approved_at->equalTo(now()))->toBeTrue();
    expect(app(EvaluationTemplateRegistry::class)->validApproved($approved, $approved->admission_method_id))->toBeTrue();
    $this->assertDatabaseHas('activity_logs', ['action' => 'evaluation_rule.approved', 'subject_id' => $rule->id]);
    expect(fn () => $approved->update(['payload' => evaluationPayload() + ['expression' => 'SQL']]))->toThrow(ValidationException::class);
    expect(fn () => $approved->delete())->toThrow(ValidationException::class);
    expect(fn () => app(EvaluationRuleManagement::class)->updateDraft($rule->id, evaluationPayload(), 'Edit approved'))->toThrow(ValidationException::class);
});

test('new version preserves approved history and never activates itself', function () {
    evaluationAdmin();
    $program = AdmissionProgram::factory()->create();
    $rules = app(EvaluationRuleManagement::class);
    $first = evaluationDraft($program->admissionMethod);
    $rules->approve($first->id);
    $rules->bind($program->id, $first->id, null, 'Initial binding');
    $before = $first->fresh()->getAttributes();

    $next = $rules->newVersion($first->id, 'New policy');

    expect($next->version)->toBe(2);
    expect($next->status)->toBe('draft');
    expect($first->fresh()->getAttributes())->toBe($before);
    expect($program->fresh()->evaluation_rule_version_id)->toBe($first->id);
    $log = ActivityLog::query()->where('action', 'evaluation_rule.draft_created')->where('subject_id', $next->id)->sole();
    expect($log->new_values['previous_rule_id'])->toBe($first->id);
    expect($log->new_values['reason'])->toBe('New policy');
});

test('a stale draft model cannot overwrite payload after another action approves it', function () {
    evaluationAdmin();
    $draft = evaluationDraft();
    app(EvaluationRuleManagement::class)->approve($draft->id);
    $sealed = $draft->fresh()->getAttributes();
    $payload = evaluationPayload();
    $payload['minimum_total_score'] = 22;

    expect(fn () => $draft->update(['payload' => $payload]))->toThrow(ValidationException::class);

    expect($draft->fresh()->getAttributes())->toBe($sealed);
});

test('binding rejects draft retired wrong method unsupported and corrupt rules atomically', function (string $case) {
    $admin = evaluationAdmin();
    $program = AdmissionProgram::factory()->create();
    $rule = evaluationDraft($case === 'wrong_method' ? null : $program->admissionMethod);
    $rules = app(EvaluationRuleManagement::class);
    if ($case !== 'draft') {
        $rules->approve($rule->id);
    }
    if ($case === 'retired') {
        $rules->retire($rule->id, 'Retire');
    }
    if ($case === 'unsupported') {
        DB::table('evaluation_rule_versions')->where('id', $rule->id)->update(['template_identifier' => 'APTITUDE_SCORE']);
    }
    if ($case === 'corrupt') {
        DB::table('evaluation_rule_versions')->where('id', $rule->id)->update(['content_hash' => str_repeat('0', 64)]);
    }
    if ($case === 'scalar_payload') {
        DB::table('evaluation_rule_versions')->where('id', $rule->id)->update(['payload' => json_encode('invalid')]);
    }
    if ($case === 'template_version') {
        DB::table('evaluation_rule_versions')->where('id', $rule->id)->update(['template_version' => 2]);
    }
    if ($case === 'disabled_allowlist') {
        config(['admission_registration.approved_templates' => []]);
    }
    $logs = ActivityLog::query()->count();

    expect(fn () => $rules->bind($program->id, $rule->id, null, 'Bind'))->toThrow(ValidationException::class);

    expect($program->fresh()->evaluation_rule_version_id)->toBeNull();
    expect(ActivityLog::query()->count())->toBe($logs);
})->with(['draft', 'retired', 'wrong_method', 'unsupported', 'corrupt', 'scalar_payload', 'template_version', 'disabled_allowlist']);

test('binding records old and new references while preserving legacy engine and published results', function () {
    evaluationAdmin();
    $program = AdmissionProgram::factory()->create();
    $result = AdmissionResult::factory()->create(['published_at' => now(), 'confirmed_at' => now()]);
    $beforeResult = $result->fresh()->getAttributes();
    $engine = app(AdmissionEngineSnapshot::class);
    $beforeEngine = $engine->fingerprint($engine->load($program->admission_round_id));
    $legacyAttributes = $program->fresh()->only(['quota', 'minimum_score', 'status']);
    $rule = evaluationDraft($program->admissionMethod);
    app(EvaluationRuleManagement::class)->approve($rule->id);

    app(EvaluationRuleManagement::class)->bind($program->id, $rule->id, null, 'Manual assignment');

    $log = ActivityLog::query()->where('action', 'evaluation_rule.program_bound')->sole();
    expect($log->old_values['evaluation_rule_version_id'])->toBeNull();
    expect($log->new_values['evaluation_rule_version_id'])->toBe($rule->id);
    expect($log->new_values['reason'])->toBe('Manual assignment');
    expect($engine->fingerprint($engine->load($program->admission_round_id)))->toBe($beforeEngine);
    expect($result->fresh()->getAttributes())->toBe($beforeResult);
    expect($program->fresh()->only(['quota', 'minimum_score', 'status']))->toBe($legacyAttributes);
});

test('duplicate approval and stale binding are rejected with no second audit', function () {
    evaluationAdmin();
    $program = AdmissionProgram::factory()->create();
    $rules = app(EvaluationRuleManagement::class);
    $first = evaluationDraft($program->admissionMethod);
    $rules->approve($first->id);
    expect(fn () => $rules->approve($first->id))->toThrow(ValidationException::class);
    expect(ActivityLog::query()->where('action', 'evaluation_rule.approved')->count())->toBe(1);
    $rules->bind($program->id, $first->id, null, 'First binding');
    $next = $rules->newVersion($first->id, 'New version');
    $rules->approve($next->id);

    expect(fn () => $rules->bind($program->id, $next->id, null, 'Stale form'))->toThrow(ValidationException::class);

    expect($program->fresh()->evaluation_rule_version_id)->toBe($first->id);
    expect(ActivityLog::query()->where('action', 'evaluation_rule.program_bound')->count())->toBe(1);
});

test('readiness requires every accepted method and rejects empty offering', function () {
    evaluationAdmin();
    $first = AdmissionProgram::factory()->create();
    $offering = CandidateMajorOffering::factory()->for($first)->create();
    $second = AdmissionProgram::factory()->for($first->admissionRound)->for($first->major)->create();
    $readiness = app(NativeRegistrationReadiness::class);
    expect($readiness->check($offering))->toMatchArray(['status' => 'NOT READY', 'total' => 2, 'approved' => 0, 'missing' => 2]);
    $rules = app(EvaluationRuleManagement::class);
    foreach ([$first, $second] as $index => $program) {
        $rule = evaluationDraft($program->admissionMethod, $index === 0 ? 'THPT_SCORE' : 'TRANSCRIPT_SCORE');
        $rules->approve($rule->id);
        $rules->bind($program->id, $rule->id, null, 'Demo manual binding');
    }

    expect($readiness->check($offering))->toMatchArray(['status' => 'READY', 'total' => 2, 'approved' => 2, 'missing' => 0]);
    $first->update(['status' => 'inactive']);
    $second->update(['status' => 'inactive']);
    expect($readiness->check($offering))->toMatchArray(['status' => 'NOT READY', 'total' => 0]);
});

test('unsupported readiness explains template and no DGNL conversion occurs', function () {
    $admin = evaluationAdmin();
    $program = AdmissionProgram::factory()->create();
    $program->admissionMethod->update(['code' => 'DGNL']);
    $offering = CandidateMajorOffering::factory()->for($program)->create();
    $rule = EvaluationRuleVersion::factory()->create(['admission_method_id' => $program->admission_method_id,
        'template_identifier' => 'APTITUDE_SCORE', 'status' => 'approved', 'approved_by' => $admin->id, 'approved_at' => now()]);
    $program->evaluation_rule_version_id = $rule->id;
    $program->save();

    $state = app(NativeRegistrationReadiness::class)->check($offering);

    expect($state)->toMatchArray(['status' => 'NOT READY', 'unsupported' => 1, 'missing' => 1]);
    expect($state['methods'][0]['reason'])->toContain('HSA/V-ACT');
    expect($program->admissionMethod->fresh()->code)->toBe('DGNL');
    expect(fn () => evaluationDraft($program->admissionMethod, 'APTITUDE_SCORE'))->toThrow(ValidationException::class);
});

test('invalid fixed template payload cannot create a draft', function (array $changes) {
    evaluationAdmin();
    $method = AdmissionMethod::factory()->create();
    expect(fn () => app(EvaluationRuleManagement::class)->createDraft($method->id, 'THPT_SCORE', 1,
        array_replace(evaluationPayload(), $changes), 'Create'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('evaluation_rule_versions', 0);
})->with([
    'arbitrary expression' => [['expression' => 'score * 100']],
    'repeated subjects' => [['subjects' => ['MATH', 'MATH', 'PHYSICS']]],
    'unknown subject' => [['subjects' => ['MATH', 'FAKE', 'PHYSICS']]],
    'too few subjects' => [['subjects' => ['MATH']]],
    'invalid threshold' => [['minimum_total_score' => 31]],
    'invalid subject threshold' => [['minimum_subject_score' => -1]],
    'no policy' => [['policy_reference' => ' ']],
    'invalid year' => [['source_year' => 1999]],
]);

test('approval rejects a tampered ranking contract', function () {
    evaluationAdmin();
    $rule = evaluationDraft();
    $rule->update(['ranking_contract' => 'cross-scale']);

    expect(fn () => app(EvaluationRuleManagement::class)->approve($rule->id))->toThrow(ValidationException::class);

    expect($rule->fresh()->status)->toBe('draft');
    $this->assertDatabaseMissing('activity_logs', ['action' => 'evaluation_rule.approved']);
});

test('comparison scopes differ across methods templates and versions even on same scale', function () {
    evaluationAdmin();
    $rules = app(EvaluationRuleManagement::class);
    $thpt = evaluationDraft();
    $rules->approve($thpt->id);
    $transcript = evaluationDraft(null, 'TRANSCRIPT_SCORE');
    $rules->approve($transcript->id);
    $next = $rules->newVersion($thpt->id, 'New version');
    $rules->approve($next->id);
    $registry = app(EvaluationTemplateRegistry::class);
    $scopes = array_map(fn ($rule) => $registry->comparisonScope($rule->fresh()), [$thpt, $transcript, $next]);

    expect(array_unique($scopes))->toHaveCount(3);
});

test('non-admin roles cannot manage rules through policy UI or service', function (UserRole $role) {
    $actor = User::factory()->create(['role' => $role]);
    $this->actingAs($actor);
    $rule = EvaluationRuleVersion::factory()->create();

    expect(Gate::allows('viewAny', EvaluationRuleVersion::class))->toBeFalse();
    expect(Gate::allows('update', $rule))->toBeFalse();
    expect(Gate::allows('approve', $rule))->toBeFalse();
    expect(fn () => evaluationDraft())->toThrow(AuthorizationException::class);
    Livewire::test(EvaluationRules::class)->assertForbidden();
})->with(array_filter(UserRole::cases(), fn (UserRole $role): bool => $role !== UserRole::Admin));

test('inactive and unverified admins cannot manage rules', function (string $condition) {
    $admin = User::factory()->create(['role' => UserRole::Admin,
        'status' => $condition === 'inactive' ? UserStatus::Inactive : UserStatus::Active, 'email_verified_at' => $condition === 'unverified' ? null : now()]);
    $this->actingAs($admin);

    expect(fn () => evaluationDraft())->toThrow(AuthorizationException::class);
})->with(['inactive', 'unverified']);

test('admin UI creates approves and manually binds a saved rule', function () {
    evaluationAdmin();
    $program = AdmissionProgram::factory()->create();
    $page = Livewire::test(EvaluationRules::class, ['programId' => $program->id])
        ->set('payload', evaluationPayload())->set('reason', 'Manual configuration')->call('saveDraft')->assertHasNoErrors();
    $rule = EvaluationRuleVersion::query()->sole();
    $page->call('approve')->assertHasNoErrors()
        ->set('reason', 'Manual binding')->call('bind')->assertHasNoErrors();

    expect($program->fresh()->evaluation_rule_version_id)->toBe($rule->id);
    Livewire::test(AdmissionPrograms::class)->assertSee('Rule hợp lệ')->call('manageRules', $program->id)->assertSee('Quản lý quy tắc đánh giá');
    CandidateMajorOffering::factory()->for($program)->create();
    Livewire::test(CandidateMajorOfferings::class)->assertSee('READY');
});

test('retirement and program rebinding preserve sealed native snapshots', function () {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
    config(['admission_registration.native_registration' => true]);
    $round = AdmissionRound::factory()->create(['native_registration_state' => 'native_open', 'status' => AdmissionRoundStatus::Open]);
    $application = Application::factory()->for($round)->create(['registration_mode' => 'native']);
    $values = array_fill_keys(Profile::COMPLETION, 'fixture');
    $values['date_of_birth'] = '2008-01-02';
    $values['citizen_id_issued_date'] = '2022-01-02';
    $values['graduation_year'] = 2026;
    $values['citizen_id'] = fake()->unique()->numerify('############');
    $values['profile_status'] = ProfileStatus::Complete;
    $application->candidateProfile->update($values);
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $offering = CandidateMajorOffering::factory()->for($program)->create();
    $admin = evaluationAdmin();
    $rules = app(EvaluationRuleManagement::class);
    $first = evaluationDraft($program->admissionMethod);
    $rules->approve($first->id);
    $rules->bind($program->id, $first->id, null, 'Initial');
    $secondProgram = AdmissionProgram::factory()->for($application->admissionRound)->for($program->major)->create();
    $transcript = evaluationDraft($secondProgram->admissionMethod, 'TRANSCRIPT_SCORE');
    $rules->approve($transcript->id);
    $rules->bind($secondProgram->id, $transcript->id, null, 'Second method');
    $this->actingAs($application->candidateProfile->user);
    $native = app(NativeWishRegistration::class);
    $native->add($application->id, $offering->id);
    $native->submit($application->id);
    $snapshot = $application->submissionSnapshots()->sole();
    $binding = $snapshot->entries()->sole()->bindings()->where('evaluation_rule_version_id', $first->id)->sole();
    $before = $binding->getAttributes();
    $manifest = $snapshot->manifest;
    $this->actingAs($admin);
    $next = $rules->newVersion($first->id, 'New version');
    $rules->approve($next->id);
    $rules->bind($program->id, $next->id, $first->id, 'Explicit replacement');

    $rules->retire($first->id, 'Old version retired');

    expect($binding->fresh()->getAttributes())->toBe($before);
    expect($snapshot->fresh()->manifest)->toBe($manifest);
    expect($snapshot->entries()->sole()->bindings()->count())->toBe(2);
    expect($application->fresh()->status)->toBe(ApplicationStatus::Submitted);
    $this->assertDatabaseCount('candidate_scores', 0);
    $this->assertDatabaseCount('candidate_exam_results', 0);
    $this->assertDatabaseCount('admission_results', 0);
});
