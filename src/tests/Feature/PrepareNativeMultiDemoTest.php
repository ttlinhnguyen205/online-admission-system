<?php

use App\Actions\EvaluationRuleManagement;
use App\Actions\NativeRegistrationReadiness;
use App\Actions\NativeWishRegistration;
use App\Enums\AdmissionRoundStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Livewire\Candidate\Profile;
use App\Models\ActivityLog;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\ApplicationSubmissionSnapshot;
use App\Models\EvaluationRuleVersion;
use App\Models\Major;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['app.timezone' => 'Asia/Ho_Chi_Minh', 'admission_registration.native_registration' => true]);
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
});

/** @return array{admin: User, thpt: AdmissionMethod, legacy: AdmissionMethod, rule: EvaluationRuleVersion} */
function multiDemoFixture(): array
{
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    test()->actingAs($admin);
    Major::factory()->create(['code' => 'DEMO-7340301', 'name' => 'Kế toán', 'is_active' => true]);
    $thpt = AdmissionMethod::factory()->create(['code' => 'DEMO-THPT-A00', 'is_active' => true]);
    $legacy = AdmissionMethod::factory()->create(['code' => 'DEMO-HB-D01', 'score_config' => ['weights' => ['MATH' => 1, 'LITERATURE' => 1, 'ENG' => 2]]]);
    $rules = app(EvaluationRuleManagement::class);
    $rule = $rules->createDraft($thpt->id, 'THPT_SCORE', 1, ['subjects' => ['MATH', 'PHYSICS', 'CHEMISTRY'], 'source_year' => 2026,
        'minimum_subject_score' => 1, 'minimum_total_score' => 18, 'policy_reference' => 'DEMO ONLY – NOT OFFICIAL ADMISSION POLICY'], 'Fixture');
    $rules->approve($rule->id);

    return ['admin' => $admin, 'thpt' => $thpt, 'legacy' => $legacy, 'rule' => $rule->fresh()];
}

/** @return array<string, mixed> */
function multiApplyOptions(User $admin): array
{
    return ['--apply' => true, '--confirm' => 'DEMO-2026-NATIVE-MULTI', '--development-database' => ':memory:', '--backup-confirmed' => true, '--admin' => $admin->id];
}

test('default preview and dry run including apply flag write nothing', function (array $options) {
    multiDemoFixture();
    $before = DB::table('activity_logs')->count();
    $this->artisan('admission:prepare-native-multi-demo', $options)->expectsOutputToContain('READ-ONLY')->assertSuccessful();
    $this->assertDatabaseCount('admission_rounds', 0);
    $this->assertDatabaseCount('admission_methods', 2);
    $this->assertDatabaseCount('admission_programs', 0);
    $this->assertDatabaseCount('candidate_major_offerings', 0);
    expect(DB::table('activity_logs')->count())->toBe($before);
})->with([[[]], [['--dry-run' => true]], [['--dry-run' => true, '--apply' => true]]]);

test('apply fails closed without each explicit acknowledgment', function (string $missing) {
    $fixture = multiDemoFixture();
    $options = multiApplyOptions($fixture['admin']);
    unset($options[$missing]);
    $this->artisan('admission:prepare-native-multi-demo', $options)->assertFailed();
    $this->assertDatabaseCount('admission_rounds', 0);
    $this->assertDatabaseCount('admission_methods', 2);
})->with(['--confirm', '--development-database', '--backup-confirmed', '--admin']);

test('production and local non-MySQL connections prevent apply', function (string $environment) {
    $fixture = multiDemoFixture();
    app()->detectEnvironment(fn () => $environment);
    $this->artisan('admission:prepare-native-multi-demo', multiApplyOptions($fixture['admin']))->assertFailed();
    $this->assertDatabaseCount('admission_rounds', 0);
})->with(['production', 'local']);

test('remote MySQL host is rejected before querying schema', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['database.default' => 'remote_demo_guard', 'database.connections.remote_demo_guard' => [
        'driver' => 'mysql', 'host' => '192.0.2.1', 'database' => 'unreachable_development', 'username' => '', 'password' => '',
    ]]);
    $this->artisan('admission:prepare-native-multi-demo', ['--apply' => true])->expectsOutputToContain('loopback')->assertFailed();
    config(['database.default' => 'sqlite']);
    $this->assertDatabaseCount('admission_rounds', 0);
});

test('inactive unverified and non-admin audit actors are rejected', function (array $changes) {
    $fixture = multiDemoFixture();
    User::query()->whereKey($fixture['admin']->id)->update($changes);
    $this->artisan('admission:prepare-native-multi-demo', multiApplyOptions($fixture['admin']))->assertFailed();
    $this->assertDatabaseCount('admission_rounds', 0);
})->with([[['role' => 'staff']], [['status' => 'inactive']], [['email_verified_at' => null]]]);

test('invalid approved THPT content blocks preparation', function () {
    $fixture = multiDemoFixture();
    DB::table('evaluation_rule_versions')->where('id', $fixture['rule']->id)->update(['content_hash' => str_repeat('0', 64)]);
    $this->artisan('admission:prepare-native-multi-demo', multiApplyOptions($fixture['admin']))->assertFailed();
    $this->assertDatabaseCount('admission_rounds', 0);
});

test('rerun refuses zero in a field expected to remain null', function () {
    $fixture = multiDemoFixture();
    $this->artisan('admission:prepare-native-multi-demo', multiApplyOptions($fixture['admin']))->assertSuccessful();
    $program = AdmissionProgram::firstOrFail();
    $program->update(['minimum_score' => 0]);
    $before = $program->fresh()->getAttributes();
    $logs = DB::table('activity_logs')->count();
    $this->artisan('admission:prepare-native-multi-demo', multiApplyOptions($fixture['admin']))->assertFailed();
    expect($program->fresh()->getAttributes())->toBe($before);
    expect(DB::table('activity_logs')->count())->toBe($logs);
    $this->assertDatabaseCount('admission_rounds', 1);
    $this->assertDatabaseCount('admission_programs', 2);
});

test('missing mandatory migration or unique constraint prevents writes', function (string $missing) {
    $fixture = multiDemoFixture();
    if ($missing === 'migration') {
        DB::table('migrations')->where('migration', '2026_10_10_002531_create_native_registration_tables')->delete();
    } else {
        DB::statement('DROP INDEX admission_methods_code_unique');
    }
    $this->artisan('admission:prepare-native-multi-demo', multiApplyOptions($fixture['admin']))->assertFailed();
    $this->assertDatabaseCount('admission_rounds', 0);
})->with(['migration', 'unique']);

test('apply creates only catalog preserves existing data and rerun creates no duplicates', function () {
    $fixture = multiDemoFixture();
    $before = array_map(fn ($row) => $row->fresh()->getAttributes(), $fixture);
    $old = AdmissionRound::factory()->create(['code' => 'DEMO-2026-NATIVE']);
    $oldApp = Application::factory()->for($old)->create(['registration_mode' => 'native']);
    $oldSnapshot = ApplicationSubmissionSnapshot::create(['application_id' => $oldApp->id, 'submission_version' => 1, 'submitted_at' => now(), 'sealed_at' => now(),
        'registration_mode' => 'native', 'readiness' => 'rules_pinned', 'catalog_fingerprint' => str_repeat('a', 64), 'manifest' => [], 'content_hash' => str_repeat('b', 64)]);
    $oldValues = [$old->fresh()->getAttributes(), $oldApp->fresh()->getAttributes(), $oldSnapshot->fresh()->getAttributes()];
    $this->artisan('admission:prepare-native-multi-demo', multiApplyOptions($fixture['admin']))->expectsOutputToContain('NOT READY')->assertSuccessful();
    $round = AdmissionRound::where('code', 'DEMO-2026-NATIVE-MULTI')->sole();
    expect($round->status)->toBe(AdmissionRoundStatus::Draft);
    expect($round->nativeRegistrationState())->toBe('legacy');
    expect($round->start_date->format('Y-m-d H:i:s'))->toBe('2026-10-10 00:00:00');
    expect($round->end_date->format('Y-m-d H:i:s'))->toBe('2026-10-31 23:59:59');
    $method = AdmissionMethod::where('code', 'DEMO-HB-EQ1')->sole();
    expect($method->score_config)->toBe(['weights' => ['MATH' => 1, 'LITERATURE' => 1, 'ENG' => 1]]);
    expect($method->description)->toContain('candidate_transcripts', 'DEMO ONLY');
    $this->assertDatabaseCount('admission_programs', 2);
    $this->assertDatabaseCount('candidate_major_offerings', 1);
    $this->assertDatabaseHas('admission_programs', ['admission_method_id' => $fixture['thpt']->id, 'evaluation_rule_version_id' => $fixture['rule']->id, 'quota' => 0, 'minimum_score' => null, 'tuition_fee' => null]);
    $this->assertDatabaseHas('admission_programs', ['admission_method_id' => $method->id, 'evaluation_rule_version_id' => null]);
    $counts = DB::table('activity_logs')->count();
    $this->artisan('admission:prepare-native-multi-demo', multiApplyOptions($fixture['admin']))->assertSuccessful();
    expect(DB::table('activity_logs')->count())->toBe($counts);
    expect(array_map(fn ($row) => $row->fresh()->getAttributes(), $fixture))->toBe($before);
    expect([$old->fresh()->getAttributes(), $oldApp->fresh()->getAttributes(), $oldSnapshot->fresh()->getAttributes()])->toBe($oldValues);
    $this->assertDatabaseCount('applications', 1);
    $this->assertDatabaseCount('application_submission_snapshots', 1);
    $this->assertDatabaseCount('evaluation_rule_versions', 1);
    $this->assertDatabaseCount('admission_results', 0);
});

test('late catalog conflict rolls back round creation and audit', function () {
    $fixture = multiDemoFixture();
    AdmissionMethod::factory()->create(['code' => 'DEMO-HB-EQ1', 'name' => 'conflict']);
    $logs = DB::table('activity_logs')->count();
    $this->artisan('admission:prepare-native-multi-demo', multiApplyOptions($fixture['admin']))->expectsOutputToContain('Conflict')->assertFailed();
    $this->assertDatabaseCount('admission_rounds', 0);
    expect(DB::table('activity_logs')->count())->toBe($logs);
});

test('catalog lock precedes writes and audit failure rolls back all catalog rows', function () {
    $fixture = multiDemoFixture();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    ActivityLog::creating(fn () => throw new RuntimeException('Audit failure'));
    try {
        $this->artisan('admission:prepare-native-multi-demo', multiApplyOptions($fixture['admin']))->assertFailed();
    } finally {
        ActivityLog::flushEventListeners();
    }
    $this->assertDatabaseCount('admission_rounds', 0);
    $this->assertDatabaseCount('admission_methods', 2);
    $lock = array_search('select "id" from "admission_rounds" order by "id" asc', $queries, true);
    expect($lock)->not->toBeFalse();
    expect(collect(array_slice($queries, 0, $lock))->contains(fn ($sql) => str_starts_with($sql, 'insert')))->toBeFalse();
});

test('Admin approved transcript binding enables one wish two pins and consistent sealed snapshot', function () {
    Notification::fake();
    $fixture = multiDemoFixture();
    $this->artisan('admission:prepare-native-multi-demo', multiApplyOptions($fixture['admin']))->assertSuccessful();
    $round = AdmissionRound::where('code', 'DEMO-2026-NATIVE-MULTI')->sole();
    $offering = $round->candidateMajorOfferings()->sole();
    expect(app(NativeRegistrationReadiness::class)->check($offering))->toMatchArray(['status' => 'NOT READY', 'total' => 2, 'approved' => 1, 'missing' => 1, 'unsupported' => 0]);
    $method = AdmissionMethod::where('code', 'DEMO-HB-EQ1')->sole();
    $program = $round->programs()->where('admission_method_id', $method->id)->sole();
    $rules = app(EvaluationRuleManagement::class);
    $hb = $rules->createDraft($method->id, 'TRANSCRIPT_SCORE', 1, ['subjects' => ['MATH', 'LITERATURE', 'ENG'], 'grade_level' => 12, 'source_year' => 2026,
        'minimum_subject_score' => 1, 'minimum_total_score' => 18, 'policy_reference' => 'DEMO ONLY – NOT OFFICIAL ADMISSION POLICY'], 'Test Admin decision');
    $rules->approve($hb->id);
    $rules->bind($program->id, $hb->id, null, 'Test Admin binding');
    expect(app(NativeRegistrationReadiness::class)->check($offering))->toMatchArray(['status' => 'READY', 'total' => 2, 'approved' => 2, 'missing' => 0]);
    $this->artisan('admission:prepare-native-multi-demo', multiApplyOptions($fixture['admin']))->assertSuccessful();
    DB::table('admission_rounds')->where('id', $round->id)->update(['status' => 'open', 'native_registration_state' => 'native_open']);
    $application = Application::factory()->for($round)->create(['registration_mode' => 'native']);
    $values = array_fill_keys(Profile::COMPLETION, 'fixture');
    $values['date_of_birth'] = '2008-01-02';
    $values['citizen_id_issued_date'] = '2022-01-02';
    $values['graduation_year'] = 2026;
    $values['citizen_id'] = fake()->unique()->numerify('############');
    $values['profile_status'] = ProfileStatus::Complete;
    $application->candidateProfile->update($values);
    $this->actingAs($application->candidateProfile->user);
    $native = app(NativeWishRegistration::class);
    $native->add($application->id, $offering->id);
    $native->submit($application->id);
    $snapshot = $application->submissionSnapshots()->sole();
    $entry = $snapshot->entries()->sole();
    expect($entry->priority)->toBe(1);
    expect($entry->payload['major_name'])->toBe('Kế toán');
    expect($entry->bindings()->pluck('evaluation_rule_version_id')->sort()->values()->all())->toBe([$fixture['rule']->id, $hb->id]);
    expect($snapshot->sealed_at)->not->toBeNull();
    expect($snapshot->content_hash)->toBe(hash('sha256', json_encode($snapshot->manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    expect($entry->content_hash)->toBe(hash('sha256', json_encode($entry->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    foreach ($entry->bindings as $binding) {
        expect($binding->binding_hash)->toBe(hash('sha256', json_encode($binding->catalog_reference, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
    $this->assertDatabaseCount('application_submission_snapshots', 1);
    $this->assertDatabaseCount('submission_wish_entries', 1);
    $this->assertDatabaseCount('wish_method_bindings', 2);
    $this->assertDatabaseCount('admission_results', 0);
    $this->actingAs($fixture['admin']);
    $this->artisan('admission:prepare-native-multi-demo', multiApplyOptions($fixture['admin']))->assertFailed();
    expect($snapshot->fresh()->content_hash)->toBe($snapshot->content_hash);
});
