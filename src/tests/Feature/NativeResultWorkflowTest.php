<?php

use App\Actions\AdmissionQuotaManagement;
use App\Actions\AdmissionReviewSnapshot;
use App\Actions\CandidateFiles;
use App\Actions\EvaluationRuleManagement;
use App\Actions\NativeAdmissionScoring;
use App\Actions\NativeAllocationCertification;
use App\Actions\NativeResultWorkflow;
use App\Actions\NativeWishRegistration;
use App\Actions\StaffApplicationReview;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\NativeResults;
use App\Livewire\Candidate\Profile;
use App\Livewire\Candidate\Results;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateExamResult;
use App\Models\CandidateExamSubjectScore;
use App\Models\CandidateMajorOffering;
use App\Models\NativeResultEntry;
use App\Models\NativeResultVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** @return array{version: NativeResultVersion, application: Application, admin: User, source: CandidateExamResult, decisions: list<array{application_id: int, binding_id: int, decision: string, reason: string}>} */
function nativeResultFixture(int $quota = 1): array
{
    config(['admission_registration.native_registration' => true]);
    Notification::fake();
    Storage::fake(CandidateFiles::DISK);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    test()->actingAs($admin);
    $round = AdmissionRound::factory()->create(['status' => 'open', 'native_registration_state' => 'native_open']);
    $program = AdmissionProgram::factory()->for($round)->create();
    $offering = CandidateMajorOffering::factory()->for($program)->create();
    $rules = app(EvaluationRuleManagement::class);
    $rule = $rules->createDraft($program->admission_method_id, 'THPT_SCORE', 1, ['subjects' => ['MATH', 'PHYSICS', 'CHEMISTRY'], 'source_year' => 2026,
        'minimum_subject_score' => 1, 'minimum_total_score' => 18, 'policy_reference' => 'Isolated test fixture'], 'Fixture');
    $rules->approve($rule->id);
    $rules->bind($program->id, $rule->id, null, 'Fixture');
    $quotas = app(AdmissionQuotaManagement::class);
    $q = $quotas->createDraft($offering->id, 'Fixture');
    $quotas->updateDraft($q->id, ['total_quota' => $quota, 'reason' => 'Fixture', 'limits' => [['program_id' => $program->id, 'quota' => $quota]]]);
    $quotas->approve($q->id);
    $application = Application::factory()->for($round)->create(['registration_mode' => 'native']);
    $profile = $application->candidateProfile;
    $values = array_fill_keys(Profile::COMPLETION, 'fixture');
    $values['date_of_birth'] = '2008-01-02';
    $values['citizen_id_issued_date'] = '2022-01-02';
    $values['graduation_year'] = 2026;
    $values['citizen_id'] = fake()->unique()->numerify('############');
    $values['profile_status'] = ProfileStatus::Complete;
    $values['photo_path'] = 'candidate-photos/'.$profile->id.'/photo.png';
    Storage::disk(CandidateFiles::DISK)->put($values['photo_path'], file_get_contents(base_path('tests/Fixtures/small.png')));
    $profile->update($values);
    $source = CandidateExamResult::factory()->create(['candidate_profile_id' => $profile->id, 'exam_type' => 'thpt', 'exam_year' => 2026,
        'status' => 'verified', 'verified_by' => $admin->id, 'verified_at' => now()]);
    foreach (['MATH', 'PHYSICS', 'CHEMISTRY'] as $subject) {
        CandidateExamSubjectScore::factory()->create(['candidate_exam_result_id' => $source->id, 'subject_code' => $subject, 'score' => 8]);
    }
    test()->actingAs($profile->user);
    app(NativeWishRegistration::class)->add($application->id, $offering->id);
    app(NativeWishRegistration::class)->submit($application->id);
    test()->actingAs($admin);
    $review = app(StaffApplicationReview::class);
    $snapshots = app(AdmissionReviewSnapshot::class);
    $review->start($application->id, $snapshots->fingerprint($snapshots->load($application->id)));
    $review->verify($application->id, $snapshots->fingerprint($snapshots->load($application->id)));
    app(NativeAdmissionScoring::class)->score($application->id);
    $round->setAttribute('native_registration_state', 'native_closed');
    $round->status = 'closed';
    $round->save();
    $binding = $application->submissionSnapshots()->sole()->entries()->sole()->bindings()->sole();
    $decisions = [['application_id' => $application->id, 'binding_id' => $binding->id, 'decision' => 'admitted', 'reason' => 'TEST PROPOSAL ONLY']];
    $version = app(NativeResultWorkflow::class)->createDraft($round->id, $decisions, 'test-artifact-not-production', 'Isolated test policy', 'Fixture');

    return compact('version', 'application', 'admin', 'source', 'decisions');
}

/** Test-only certificate isolates the result lifecycle; it does not certify an allocator. */
function trustResultLifecycleFixture(): void
{
    test()->mock(NativeAllocationCertification::class, function ($mock): void {
        $mock->shouldReceive('blockers')->andReturn([]);
    });
}

test('sealed result proposal is idempotent immutable and creates a new historical version for changed reason', function () {
    $fixture = nativeResultFixture();
    $version = $fixture['version'];
    $workflow = app(NativeResultWorkflow::class);
    $same = $workflow->createDraft($version->admission_round_id, $fixture['decisions'], $version->algorithm_version, $version->policy_reference, 'Fixture');
    expect($same->id)->toBe($version->id);
    expect(fn () => $version->update(['policy_reference' => 'Changed']))->toThrow(ValidationException::class);
    expect(fn () => $version->entries()->sole()->update(['score' => 30]))->toThrow(ValidationException::class);
    expect(fn () => $version->delete())->toThrow(ValidationException::class);
    $new = $workflow->createDraft($version->admission_round_id, $fixture['decisions'], $version->algorithm_version, $version->policy_reference, 'Revised proposal');
    expect($new->version)->toBe(2)->and($version->fresh()->status)->toBe('draft');
    test()->assertDatabaseCount('admission_results', 0);
});

test('production certification refuses approval and publication even with valid numeric constraints', function () {
    $fixture = nativeResultFixture();
    $version = $fixture['version'];
    $workflow = app(NativeResultWorkflow::class);
    expect($workflow->check($version->id))->toContain(NativeAllocationCertification::BLOCKER);
    expect(fn () => $workflow->approve($version->id, $version->content_hash))->toThrow(ValidationException::class);
    expect(fn () => $workflow->publish($version->id, $version->content_hash))->toThrow(ValidationException::class);
    $this->actingAs($fixture['application']->candidateProfile->user);
    Livewire::test(Results::class)->assertDontSee('TEST PROPOSAL ONLY');
    expect($version->fresh()->status)->toBe('draft');
});

test('approval publication and candidate lookup lifecycle respects certification boundary and sealed submission', function () {
    $fixture = nativeResultFixture();
    $snapshot = $fixture['application']->submissionSnapshots()->sole();
    $before = $snapshot->getAttributes();
    trustResultLifecycleFixture();
    $version = $fixture['version'];
    $workflow = app(NativeResultWorkflow::class);
    $workflow->approve($version->id, $version->content_hash);
    $this->actingAs($fixture['application']->candidateProfile->user);
    Livewire::test(Results::class)->assertDontSee('TEST PROPOSAL ONLY');
    $this->actingAs($fixture['admin']);
    $workflow->publish($version->id, $version->content_hash);
    $published = $version->fresh()->getAttributes();
    $workflow->publish($version->id, $version->content_hash);
    expect($version->fresh()->getAttributes())->toBe($published);
    $this->actingAs($fixture['application']->candidateProfile->user);
    Livewire::test(Results::class)->assertSee('TEST PROPOSAL ONLY')->assertSee('Trúng tuyển');
    $this->actingAs(User::factory()->create());
    Livewire::test(Results::class)->assertDontSee('TEST PROPOSAL ONLY');
    expect($snapshot->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('admission_wishes', 0);
});

test('hard Q and q prevent approval independently of certificate', function () {
    $fixture = nativeResultFixture(0);
    trustResultLifecycleFixture();
    $version = $fixture['version'];
    $workflow = app(NativeResultWorkflow::class);
    expect($workflow->check($version->id))->toContain('Phân bổ vượt Q.', 'Phân bổ vượt q hoặc q chưa xác định.');
    expect(fn () => $workflow->approve($version->id, $version->content_hash))->toThrow(ValidationException::class);
    expect($version->fresh()->status)->toBe('draft');
});

test('source changes make a result proposal stale and cannot be published after approval', function () {
    $fixture = nativeResultFixture();
    trustResultLifecycleFixture();
    $version = $fixture['version'];
    $workflow = app(NativeResultWorkflow::class);
    $workflow->approve($version->id, $version->content_hash);
    $fixture['source']->subjectScores()->first()->update(['score' => 7]);
    expect($workflow->check($version->id))->toContain('Đầu vào đã stale; cần phiên bản kết quả mới.');
    expect(fn () => $workflow->publish($version->id, $version->content_hash))->toThrow(ValidationException::class);
    expect($version->fresh()->status)->toBe('approved');
});

test('tampered persisted result is rejected before publication', function () {
    $fixture = nativeResultFixture();
    trustResultLifecycleFixture();
    $version = $fixture['version'];
    DB::table('native_result_entries')->where('native_result_version_id', $version->id)->update(['score' => 30]);
    expect(fn () => app(NativeResultWorkflow::class)->approve($version->id, $version->content_hash))->toThrow(ValidationException::class);
});

test('unauthorized actor cannot approve publish or see native management UI', function (UserRole $role) {
    $fixture = nativeResultFixture();
    $this->actingAs(User::factory()->create(['role' => $role]));
    $version = $fixture['version'];
    expect(fn () => app(NativeResultWorkflow::class)->approve($version->id, $version->content_hash))->toThrow(HttpException::class);
    expect(fn () => app(NativeResultWorkflow::class)->publish($version->id, $version->content_hash))->toThrow(HttpException::class);
    Livewire::test(NativeResults::class, ['roundId' => $version->admission_round_id])->assertForbidden();
})->with([UserRole::Staff, UserRole::Candidate]);

test('native UI requires confirmation and rejects a blocked draft without deleting history', function () {
    $fixture = nativeResultFixture();
    $version = $fixture['version'];
    Livewire::test(NativeResults::class, ['roundId' => $version->admission_round_id])->call('select', $version->id)
        ->assertSee('BLOCKED')->call('reject')->assertHasErrors('confirmed')
        ->set('confirmed', true)->set('rejectionReason', 'Missing approved coordination policy')->call('reject')->assertHasNoErrors();
    expect($version->fresh()->status)->toBe('rejected');
    expect($version->entries()->count())->toBe(1);
});

test('cleanup fails closed for native history and retains sealed records', function () {
    $fixture = nativeResultFixture();
    $this->artisan('dev:clear-candidates', ['--dry-run' => true])->assertFailed();
    expect($fixture['version']->fresh())->not->toBeNull();
    expect($fixture['application']->submissionSnapshots()->sole()->sealed_at)->not->toBeNull();
});

test('wrong approval fingerprint cannot replace the reviewed version', function () {
    $fixture = nativeResultFixture();
    expect(fn () => app(NativeResultWorkflow::class)->approve($fixture['version']->id, str_repeat('0', 64)))->toThrow(ValidationException::class);
});

test('duplicate application or foreign binding rolls back a proposal', function () {
    $fixture = nativeResultFixture();
    $version = $fixture['version'];
    $decisions = $fixture['decisions'];
    expect(fn () => app(NativeResultWorkflow::class)->createDraft($version->admission_round_id, [...$decisions, ...$decisions], 'fixture', 'Fixture', 'Duplicate'))->toThrow(ValidationException::class);
    $decisions[0]['binding_id'] = 999999;
    expect(fn () => app(NativeResultWorkflow::class)->createDraft($version->admission_round_id, $decisions, 'fixture', 'Fixture', 'Wrong binding'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('native_result_versions', 1);
});

test('publication is hidden before its date and approval metadata cannot be omitted', function () {
    $fixture = nativeResultFixture();
    trustResultLifecycleFixture();
    $version = $fixture['version'];
    $workflow = app(NativeResultWorkflow::class);
    $workflow->approve($version->id, $version->content_hash);
    $workflow->publish($version->id, $version->content_hash);
    $owner = $fixture['application']->candidateProfile->user;
    DB::table('native_result_versions')->where('id', $version->id)->update(['published_at' => now()->addDay()]);
    expect(NativeResultEntry::query()->visibleToCandidate($owner)->count())->toBe(0);
    DB::table('native_result_versions')->where('id', $version->id)->update(['published_at' => now(), 'approved_by' => null]);
    expect(NativeResultEntry::query()->visibleToCandidate($owner)->count())->toBe(0);
});

test('inactive and unverified administrators cannot approve', function (string $field) {
    $fixture = nativeResultFixture();
    $fixture['admin']->forceFill([$field => $field === 'status' ? 'inactive' : null])->save();
    expect(fn () => app(NativeResultWorkflow::class)->approve($fixture['version']->id, $fixture['version']->content_hash))->toThrow(HttpException::class);
})->with(['status', 'email_verified_at']);

test('rejected proposal keeps its reason and cannot later be approved or published', function () {
    $fixture = nativeResultFixture();
    $workflow = app(NativeResultWorkflow::class);
    $version = $fixture['version'];
    $workflow->reject($version->id, $version->content_hash, 'Missing approved coordination policy');
    expect($version->fresh()->rejection_reason)->toBe('Missing approved coordination policy');
    expect(fn () => $workflow->approve($version->id, $version->content_hash))->toThrow(ValidationException::class);
    expect(fn () => $workflow->publish($version->id, $version->content_hash))->toThrow(ValidationException::class);
});

test('claimed algorithm name and feature flags never certify a production result', function () {
    $fixture = nativeResultFixture();
    config(['admission_registration.native_allocation' => true]);
    $version = $fixture['version'];
    $new = app(NativeResultWorkflow::class)->createDraft($version->admission_round_id, $fixture['decisions'], 'claimed-approved-v999', 'Claimed approved policy', 'Unverified claim');
    expect(fn () => app(NativeResultWorkflow::class)->approve($new->id, $new->content_hash))->toThrow(ValidationException::class);
});

test('database enforces one publication slot per round independently of application locking', function () {
    $fixture = nativeResultFixture();
    $version = $fixture['version'];
    $new = app(NativeResultWorkflow::class)->createDraft($version->admission_round_id, $fixture['decisions'], $version->algorithm_version, $version->policy_reference, 'Second draft');
    DB::table('native_result_versions')->where('id', $version->id)->update(['publication_slot' => 1]);
    expect(fn () => DB::table('native_result_versions')->where('id', $new->id)->update(['publication_slot' => 1]))->toThrow(QueryException::class);
});
