<?php

use App\Actions\EvaluationRuleManagement;
use App\Actions\NativeAdmissionScoring;
use App\Actions\NativeWishRegistration;
use App\Enums\AdmissionRoundStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\NativeScoring;
use App\Livewire\Candidate\ApplicationDetails;
use App\Livewire\Candidate\Profile;
use App\Models\ActivityLog;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateExamResult;
use App\Models\CandidateExamSubjectScore;
use App\Models\CandidateMajorOffering;
use App\Models\CandidateProfile;
use App\Models\CandidateTranscript;
use App\Models\CandidateTranscriptScore;
use App\Models\NativeMethodEvaluation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
    config(['admission_registration.native_registration' => true]);
});

/** @return array{application: Application, admin: User, thpt: AdmissionProgram, transcript: AdmissionProgram} */
function scoringFixture(): array
{
    Notification::fake();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    test()->actingAs($admin);
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open, 'native_registration_state' => 'native_open']);
    $thpt = AdmissionProgram::factory()->for($round)->create(['quota' => 0]);
    $transcript = AdmissionProgram::factory()->for($round)->for($thpt->major)->create(['quota' => 0]);
    $offering = CandidateMajorOffering::factory()->for($thpt)->create();
    foreach ([$thpt, $transcript] as $program) {
        $isTranscript = $program->id === $transcript->id;
        $payload = ['subjects' => $isTranscript ? ['MATH', 'LITERATURE', 'ENG'] : ['MATH', 'PHYSICS', 'CHEMISTRY'],
            'source_year' => 2026, 'minimum_subject_score' => 1, 'minimum_total_score' => 18, 'policy_reference' => 'Approved test policy: equal weights'];
        if ($isTranscript) {
            $payload['grade_level'] = 12;
        }
        $management = app(EvaluationRuleManagement::class);
        $rule = $management->createDraft($program->admission_method_id, $isTranscript ? 'TRANSCRIPT_SCORE' : 'THPT_SCORE', 1, $payload, 'Fixture');
        $management->approve($rule->id);
        $management->bind($program->id, $rule->id, null, 'Fixture');
    }
    $application = Application::factory()->for($round)->create(['registration_mode' => 'native']);
    $values = array_fill_keys(Profile::COMPLETION, 'fixture');
    $values['date_of_birth'] = '2008-01-02';
    $values['citizen_id_issued_date'] = '2022-01-02';
    $values['graduation_year'] = 2026;
    $values['citizen_id'] = fake()->unique()->numerify('############');
    $values['profile_status'] = ProfileStatus::Complete;
    $application->candidateProfile->update($values);
    test()->actingAs($application->candidateProfile->user);
    app(NativeWishRegistration::class)->add($application->id, $offering->id);
    app(NativeWishRegistration::class)->submit($application->id);
    test()->actingAs($admin);

    return compact('application', 'admin', 'thpt', 'transcript');
}

/** @param list<float|int> $values */
function scoringSource(Application $application, User $admin, bool $transcript = false, array $values = [8, 8, 8], string $status = 'verified', int $year = 2026, int $grade = 12): void
{
    $attributes = ['candidate_profile_id' => $application->candidate_profile_id, 'status' => $status, 'verified_by' => $status === 'verified' ? $admin->id : null, 'verified_at' => $status === 'verified' ? now() : null];
    $parent = $transcript ? CandidateTranscript::factory()->create([...$attributes, 'graduation_year' => $year]) : CandidateExamResult::factory()->create([...$attributes, 'exam_type' => 'thpt', 'exam_year' => $year]);
    foreach ($values as $index => $value) {
        $subject = ($transcript ? ['MATH', 'LITERATURE', 'ENG'] : ['MATH', 'PHYSICS', 'CHEMISTRY'])[$index];
        if ($transcript) {
            CandidateTranscriptScore::factory()->create(['candidate_transcript_id' => $parent->id, 'subject_code' => $subject, 'grade_level' => $grade, 'score' => $value]);
        } else {
            CandidateExamSubjectScore::factory()->create(['candidate_exam_result_id' => $parent->id, 'subject_code' => $subject, 'score' => $value]);
        }
    }
}

test('equal weight THPT and transcript rules enforce individual and total thresholds', function (bool $transcript, array $values, string $status, string $score) {
    $fixture = scoringFixture();
    scoringSource($fixture['application'], $fixture['admin'], $transcript, $values);
    $results = app(NativeAdmissionScoring::class)->score($fixture['application']->id);
    $result = $results[$transcript ? 1 : 0];
    expect($result->status)->toBe($status);
    expect($result->score)->toBe($score);
    expect($results[$transcript ? 0 : 1]->status)->toBe('pending_data');
})->with([[false, [8, 8, 8], 'eligible', '24.000'], [false, [5, 5, 5], 'ineligible', '15.000'], [false, [0.5, 10, 10], 'ineligible', '20.500'],
    [true, [8, 8, 8], 'eligible', '24.000'], [true, [5, 5, 5], 'ineligible', '15.000'], [true, [0.5, 10, 10], 'ineligible', '20.500'], [false, [6, 6, 6], 'eligible', '18.000']]);

test('missing unverified wrong year and wrong grade sources remain pending', function (bool $transcript, array $scores, string $status, int $year, int $grade) {
    $fixture = scoringFixture();
    scoringSource($fixture['application'], $fixture['admin'], $transcript, $scores, $status, $year, $grade);
    $result = app(NativeAdmissionScoring::class)->score($fixture['application']->id)[$transcript ? 1 : 0];
    expect($result->status)->toBe('pending_data');
    expect($result->score)->toBeNull();
})->with([[false, [8, 8], 'verified', 2026, 12], [false, [8, 8, 8], 'pending', 2026, 12], [false, [8, 8, 8], 'verified', 2025, 12],
    [true, [8, 8], 'verified', 2026, 12], [true, [8, 8, 8], 'pending', 2026, 12], [true, [8, 8, 8], 'verified', 2025, 12], [true, [8, 8, 8], 'verified', 2026, 11], [false, [11, 8, 8], 'verified', 2026, 12]]);

test('multiple verified sources require resolution without selecting maximum or latest', function (bool $transcript) {
    $fixture = scoringFixture();
    scoringSource($fixture['application'], $fixture['admin'], $transcript, [6, 6, 6]);
    scoringSource($fixture['application'], $fixture['admin'], $transcript, [9, 9, 9]);
    $result = app(NativeAdmissionScoring::class)->score($fixture['application']->id)[$transcript ? 1 : 0];
    expect($result->status)->toBe('needs_resolution');
    expect($result->score)->toBeNull();
    expect($result->provenance['sources'])->toHaveCount(2);
})->with([false, true]);

test('two methods score independently preserve sealed history and reruns are idempotent', function () {
    $fixture = scoringFixture();
    $application = $fixture['application'];
    $snapshot = $application->submissionSnapshots()->with('entries.bindings')->sole();
    $before = [$snapshot->getAttributes(), $snapshot->entries->map->getAttributes()->all(), $snapshot->entries->sole()->bindings->map->getAttributes()->all(), $application->fresh()->getAttributes()];
    scoringSource($application, $fixture['admin'], false, [8.123, 8.124, 8.125]);
    scoringSource($application, $fixture['admin'], true, [5, 5, 5]);
    $first = app(NativeAdmissionScoring::class)->score($application->id);
    expect($first[0]->score)->toBe('24.372');
    expect($first[0]->status)->toBe('eligible');
    expect($first[1]->status)->toBe('ineligible');
    $saved = NativeMethodEvaluation::orderBy('id')->get()->map->getAttributes()->all();
    $auditCount = ActivityLog::where('action', 'native_scoring.evaluated')->count();
    $this->travel(1)->minutes();
    app(NativeAdmissionScoring::class)->score($application->id);
    expect(NativeMethodEvaluation::orderBy('id')->get()->map->getAttributes()->all())->toBe($saved);
    expect(ActivityLog::where('action', 'native_scoring.evaluated')->count())->toBe($auditCount);
    $snapshot = $snapshot->fresh()->load('entries.bindings');
    expect([$snapshot->getAttributes(), $snapshot->entries->map->getAttributes()->all(), $snapshot->entries->sole()->bindings->map->getAttributes()->all(), $application->fresh()->getAttributes()])->toBe($before);
    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('admission_wishes', 0);
    $this->assertDatabaseCount('native_method_evaluations', 2);
});

test('new current rule and retirement never replace pinned approved version', function () {
    $fixture = scoringFixture();
    scoringSource($fixture['application'], $fixture['admin']);
    $program = $fixture['thpt']->fresh();
    $old = $program->evaluationRule;
    $management = app(EvaluationRuleManagement::class);
    $new = $management->createDraft($program->admission_method_id, 'THPT_SCORE', 1, [...$old->payload, 'minimum_total_score' => 30], 'New policy');
    $management->approve($new->id);
    $management->bind($program->id, $new->id, $old->id, 'New binding');
    $management->retire($old->id, 'Retired after submission');
    $result = app(NativeAdmissionScoring::class)->score($fixture['application']->id)[0];
    expect($result->evaluation_rule_version_id)->toBe($old->id);
    expect($result->status)->toBe('eligible');
});

test('tampered sealed snapshot stops scoring without partial writes', function () {
    $fixture = scoringFixture();
    DB::table('application_submission_snapshots')->update(['content_hash' => str_repeat('0', 64)]);
    expect(fn () => app(NativeAdmissionScoring::class)->score($fixture['application']->id))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('native_method_evaluations', 0);
});

test('unsupported templates stay unsupported and corrupt pinned rule needs resolution', function (string $change, string $status) {
    $fixture = scoringFixture();
    $ruleId = $fixture['thpt']->fresh()->evaluation_rule_version_id;
    DB::table('evaluation_rule_versions')->where('id', $ruleId)->update($change === 'template' ? ['template_identifier' => 'APTITUDE_SCORE'] : ['content_hash' => str_repeat('0', 64)]);
    expect(app(NativeAdmissionScoring::class)->score($fixture['application']->id)[0]->status)->toBe($status);
})->with([['template', 'unsupported'], ['hash', 'needs_resolution']]);

test('Admin and Staff may explicitly score but Candidate cannot', function (string $role, bool $allowed) {
    $fixture = scoringFixture();
    $user = User::factory()->create(['role' => $role]);
    $this->actingAs($user);
    if ($allowed) {
        Livewire::test(NativeScoring::class, ['applicationId' => $fixture['application']->id])->call('run')->assertHasErrors('confirmed')->set('confirmed', true)->call('run')->assertHasNoErrors();
        $this->assertDatabaseCount('native_method_evaluations', 2);
    } else {
        expect(fn () => app(NativeAdmissionScoring::class)->score($fixture['application']->id))->toThrow(HttpException::class);
        Livewire::test(NativeScoring::class, ['applicationId' => $fixture['application']->id])->assertForbidden();
        $this->assertDatabaseCount('native_method_evaluations', 0);
    }
})->with([['admin', true], ['staff', true], ['candidate', false]]);

test('Candidate only sees neutral internal evaluation notice and cannot view other application', function () {
    $fixture = scoringFixture();
    scoringSource($fixture['application'], $fixture['admin']);
    app(NativeAdmissionScoring::class)->score($fixture['application']->id);
    $this->actingAs($fixture['application']->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $fixture['application']->id])->assertSee('chưa được công bố')->assertDontSee('24.000')->assertDontSee('Đủ điều kiện');
    $outsider = CandidateProfile::factory()->create();
    $this->actingAs($outsider->user);
    expect(fn () => Livewire::test(ApplicationDetails::class, ['application' => $fixture['application']->id]))->toThrow(ModelNotFoundException::class);
});

test('recalculation replaces only derived evaluation when verified source data changes', function () {
    $fixture = scoringFixture();
    scoringSource($fixture['application'], $fixture['admin']);
    $service = app(NativeAdmissionScoring::class);
    $first = $service->score($fixture['application']->id)[0];
    $snapshotHash = $fixture['application']->submissionSnapshots()->sole()->content_hash;
    CandidateExamSubjectScore::where('subject_code', 'MATH')->update(['score' => '5.000']);
    $this->travel(1)->minutes();
    $second = $service->score($fixture['application']->id)[0];
    expect($second->id)->toBe($first->id);
    expect($second->score)->toBe('21.000');
    expect($second->input_fingerprint)->not->toBe($first->input_fingerprint);
    expect($second->provenance['sources'][0]['scores'])->toHaveCount(3);
    expect($fixture['application']->submissionSnapshots()->sole()->content_hash)->toBe($snapshotHash);
    $this->assertDatabaseCount('native_method_evaluations', 2);
});

test('verification status without verifier metadata cannot authorize scoring', function () {
    $fixture = scoringFixture();
    scoringSource($fixture['application'], $fixture['admin']);
    CandidateExamResult::query()->update(['verified_by' => null, 'verified_at' => null]);
    expect(app(NativeAdmissionScoring::class)->score($fixture['application']->id)[0]->status)->toBe('pending_data');
});

test('stale inactive or unverified reviewer is denied after account change', function (array $changes) {
    $fixture = scoringFixture();
    User::query()->whereKey($fixture['admin']->id)->update($changes);
    expect(fn () => app(NativeAdmissionScoring::class)->score($fixture['application']->id))->toThrow(HttpException::class);
    $this->assertDatabaseCount('native_method_evaluations', 0);
})->with([[['status' => 'inactive']], [['email_verified_at' => null]], [['role' => 'candidate']]]);

test('legacy applications and withdrawn native draft are not eligible for scoring', function (string $mode, string $status) {
    $fixture = scoringFixture();
    Application::query()->whereKey($fixture['application']->id)->update(['registration_mode' => $mode, 'status' => $status]);
    expect(fn () => app(NativeAdmissionScoring::class)->score($fixture['application']->id))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('native_method_evaluations', 0);
})->with([['legacy', 'submitted'], ['native', 'draft'], ['native', 'needs_revision']]);

test('audit failure rolls back all scoring outputs', function () {
    $fixture = scoringFixture();
    ActivityLog::creating(function ($log): void {
        if ($log->action === 'native_scoring.evaluated') {
            throw new RuntimeException('Audit failed');
        }
    });
    try {
        expect(fn () => app(NativeAdmissionScoring::class)->score($fixture['application']->id))->toThrow(RuntimeException::class);
    } finally {
        ActivityLog::flushEventListeners();
    }
    $this->assertDatabaseCount('native_method_evaluations', 0);
});

test('source ownership and exam type are enforced independently of numeric scores', function () {
    $fixture = scoringFixture();
    scoringSource($fixture['application'], $fixture['admin']);
    CandidateExamResult::query()->update(['exam_type' => 'dgnl']);
    $other = Application::factory()->create();
    scoringSource($other, $fixture['admin']);
    expect(app(NativeAdmissionScoring::class)->score($fixture['application']->id)[0]->status)->toBe('pending_data');
});

test('tampering second method rolls back first method evaluation', function () {
    $fixture = scoringFixture();
    $entry = $fixture['application']->submissionSnapshots()->sole()->entries()->sole();
    $binding = $entry->bindings()->orderByDesc('id')->firstOrFail();
    DB::table('wish_method_bindings')->where('id', $binding->id)->update(['binding_hash' => str_repeat('0', 64)]);
    expect(fn () => app(NativeAdmissionScoring::class)->score($fixture['application']->id))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('native_method_evaluations', 0);
    expect(ActivityLog::where('action', 'native_scoring.evaluated')->count())->toBe(0);
});

test('missing migration yields actionable warning without scoring or Candidate error', function () {
    $fixture = scoringFixture();
    Schema::drop('native_method_evaluations');
    Livewire::test(NativeScoring::class, ['applicationId' => $fixture['application']->id])->assertSee('Migration tính điểm native chưa được áp dụng');
    expect(fn () => app(NativeAdmissionScoring::class)->score($fixture['application']->id))->toThrow(ValidationException::class);
    $this->actingAs($fixture['application']->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $fixture['application']->id])->assertSee('Chờ đánh giá nội bộ');
});
