<?php

use App\Actions\AdmissionReviewSnapshot;
use App\Actions\AdmissionStatistics;
use App\Actions\CandidateFiles;
use App\Actions\EvaluationRuleManagement;
use App\Actions\NativeAdmissionScoring;
use App\Actions\NativeRoundScoringReport;
use App\Actions\NativeSourceVerification;
use App\Actions\NativeWishRegistration;
use App\Actions\StaffApplicationReview;
use App\Enums\AdmissionRoundStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\EvaluationRules;
use App\Livewire\Admin\NativeScoring;
use App\Livewire\Candidate\AdmissionInformation;
use App\Livewire\Candidate\NativeExamScores;
use App\Livewire\Candidate\Profile;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateExamResult;
use App\Models\CandidateMajorOffering;
use App\Models\CandidateProfile;
use App\Models\CandidateTranscript;
use App\Models\NativeMethodEvaluation;
use App\Models\User;
use App\Support\AdmissionReportFilters;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['admission_registration.native_registration' => true]);
    Storage::fake(CandidateFiles::DISK);
});

function sourceEvidenceImage(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('source.png', file_get_contents(base_path('tests/Fixtures/small.png')))->mimeType('image/png');
}

/** @return array{application: Application, admin: User, transcript: CandidateTranscript, exam: CandidateExamResult} */
function sourceWorkflowFixture(): array
{
    Notification::fake();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    test()->actingAs($admin);
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open, 'native_registration_state' => 'native_open']);
    $first = AdmissionProgram::factory()->for($round)->create();
    $second = AdmissionProgram::factory()->for($round)->for($first->major)->create();
    $offering = CandidateMajorOffering::factory()->for($first)->create();
    foreach ([$first, $second] as $index => $program) {
        $payload = ['subjects' => $index === 0 ? ['MATH', 'PHYSICS', 'CHEMISTRY'] : ['MATH', 'LITERATURE', 'ENG'], 'source_year' => 2026,
            'minimum_subject_score' => 1, 'minimum_total_score' => 18, 'policy_reference' => 'DEMO ONLY – NOT OFFICIAL ADMISSION POLICY'];
        if ($index === 1) {
            $payload['grade_level'] = 12;
        }
        $rules = app(EvaluationRuleManagement::class);
        $rule = $rules->createDraft($program->admission_method_id, $index === 0 ? 'THPT_SCORE' : 'TRANSCRIPT_SCORE', 1, $payload, 'Fixture');
        $rules->approve($rule->id);
        $rules->bind($program->id, $rule->id, null, 'Fixture');
    }
    $application = Application::factory()->for($round)->create(['registration_mode' => 'native']);
    $profile = $application->candidateProfile;
    $values = array_fill_keys(Profile::COMPLETION, 'fixture');
    $values['date_of_birth'] = '2008-01-02';
    $values['citizen_id_issued_date'] = '2022-01-02';
    $values['graduation_year'] = 2026;
    $values['citizen_id'] = fake()->unique()->numerify('############');
    $values['profile_status'] = ProfileStatus::Complete;
    $profile->update($values);
    test()->actingAs($profile->user);
    $uploadTest = Livewire::test(NativeExamScores::class)->set('year', '2026')->set('scores', ['MATH' => '8', 'PHYSICS' => '8', 'CHEMISTRY' => '8'])
        ->set('evidence', sourceEvidenceImage());
    $uploadTest->call('save')->assertHasNoErrors();
    $exam = $profile->examResults()->where('exam_type', 'thpt')->sole();
    $rows = array_map(fn (string $subject): array => ['subject_code' => $subject, 'grade_10' => null, 'grade_11' => null, 'grade_12' => 8], ['MATH', 'LITERATURE', 'ENG']);
    Livewire::test(AdmissionInformation::class)->call('createTranscript')
        ->set('transcriptForm.graduation_year', 2026)->set('transcriptForm.subjects', $rows)
        ->set('transcriptEvidence', [sourceEvidenceImage()])->call('saveTranscript')->assertHasNoErrors();
    $transcript = $profile->transcripts()->sole();
    app(NativeWishRegistration::class)->add($application->id, $offering->id);
    app(NativeWishRegistration::class)->submit($application->id);
    test()->actingAs($admin);

    return compact('application', 'admin', 'transcript', 'exam');
}

test('submitted multi method workflow reports current scores then detects changed normalized source without rewriting history', function () {
    $fixture = sourceWorkflowFixture();
    $application = $fixture['application'];
    $snapshot = $application->submissionSnapshots()->sole();
    $sealed = $snapshot->getAttributes();
    $summary = app(AdmissionStatistics::class)->build($fixture['admin'], AdmissionReportFilters::from(['roundFilter' => (string) $application->admission_round_id]));
    expect($summary['metrics']['Hồ sơ Native đã nộp'])->toBe(1);
    expect($summary['metrics']['Nguyện vọng Native (theo ngành)'])->toBe(1);
    $report = app(NativeRoundScoringReport::class);
    expect($report->build($application->admission_round_id)['counts']['not_scored'])->toBe(2);
    foreach (['thpt' => $fixture['exam'], 'transcript' => $fixture['transcript']] as $type => $source) {
        $verification = app(NativeSourceVerification::class);
        $verification->verify($application->id, $type, $source->id, NativeWishRegistration::hash($verification->inspect($source)));
    }
    app(NativeAdmissionScoring::class)->score($application->id);
    expect($report->build($application->admission_round_id)['counts']['eligible'])->toBe(2);
    $evaluations = NativeMethodEvaluation::orderBy('id')->get()->map->getAttributes()->all();
    $fixture['exam']->subjectScores()->first()->update(['score' => 7]);

    $readiness = $report->build($application->admission_round_id);

    expect($readiness['counts']['stale'])->toBe(1)->and($readiness['counts']['eligible'])->toBe(1);
    expect($snapshot->fresh()->getAttributes())->toBe($sealed);
    expect(NativeMethodEvaluation::orderBy('id')->get()->map->getAttributes()->all())->toBe($evaluations);
    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('admission_wishes', 0);
});

test('staff verifies native application using sealed wishes and verified normalized sources instead of legacy wishes', function () {
    $fixture = sourceWorkflowFixture();
    $application = $fixture['application'];
    $path = 'candidate-photos/'.$application->candidate_profile_id.'/profile.png';
    Storage::disk(CandidateFiles::DISK)->put($path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $application->candidateProfile->update(['photo_path' => $path]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $review = app(StaffApplicationReview::class);
    $snapshots = app(AdmissionReviewSnapshot::class);
    $review->start($application->id, $snapshots->fingerprint($snapshots->load($application->id)));
    expect(fn () => $review->verify($application->id, $snapshots->fingerprint($snapshots->load($application->id))))->toThrow(ValidationException::class);
    $oldFingerprint = $snapshots->fingerprint($snapshots->load($application->id));
    foreach (['thpt' => $fixture['exam'], 'transcript' => $fixture['transcript']] as $type => $source) {
        $verification = app(NativeSourceVerification::class);
        $verification->verify($application->id, $type, $source->id, NativeWishRegistration::hash($verification->inspect($source)));
    }
    expect(fn () => $review->verify($application->id, $oldFingerprint))->toThrow(ValidationException::class);

    $review->verify($application->id, $snapshots->fingerprint($snapshots->load($application->id)));

    expect($application->fresh()->getRawOriginal('status'))->toBe('verified');
    $this->assertDatabaseCount('admission_wishes', 0);
    $this->assertDatabaseCount('native_method_evaluations', 0);
    $this->assertDatabaseCount('admission_results', 0);
    expect($application->submissionSnapshots()->sole()->sealed_at)->not->toBeNull();
});

test('UI stores pending THPT source then Staff verifies both normalized sources and explicitly scores', function () {
    $fixture = sourceWorkflowFixture();
    $snapshot = $fixture['application']->submissionSnapshots()->sole();
    $before = $snapshot->getAttributes();
    expect($fixture['exam']->getRawOriginal('status'))->toBe('pending');
    $staff = User::factory()->create(['role' => UserRole::Staff]);
    $this->actingAs($staff);
    foreach (['thpt' => $fixture['exam'], 'transcript' => $fixture['transcript']] as $type => $source) {
        Livewire::test(NativeScoring::class, ['applicationId' => $fixture['application']->id])->call('confirmSource', $type, $source->id)
            ->call('verifySource')->assertHasErrors('sourceConfirmed')->set('sourceConfirmed', true)->call('verifySource')->assertHasNoErrors();
        expect($source->fresh()->getRawOriginal('status'))->toBe('verified');
        expect($source->fresh()->verified_by)->toBe($staff->id);
        $this->get(route('candidate.admission-information.evidence', ['type' => $type === 'thpt' ? 'exam-results' : 'transcript-images', 'record' => $type === 'thpt' ? $source->id : $source->evidenceImages()->sole()->id]))->assertSuccessful();
    }
    $this->assertDatabaseCount('native_method_evaluations', 0);
    Livewire::test(NativeScoring::class, ['applicationId' => $fixture['application']->id])->set('confirmed', true)->call('run')->assertHasNoErrors();
    expect(NativeMethodEvaluation::pluck('status')->all())->toBe(['eligible', 'eligible']);
    expect(NativeMethodEvaluation::pluck('score')->all())->toBe(['24.000', '24.000']);
    expect($snapshot->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('admission_results', 0);
});

test('pending source cannot be verified after its reviewed fingerprint changes', function () {
    $fixture = sourceWorkflowFixture();
    $service = app(NativeSourceVerification::class);
    $expected = NativeWishRegistration::hash($service->inspect($fixture['exam']));
    $fixture['exam']->subjectScores()->where('subject_code', 'MATH')->update(['score' => 9]);
    expect(fn () => $service->verify($fixture['application']->id, 'thpt', $fixture['exam']->id, $expected))->toThrow(ValidationException::class);
    expect($fixture['exam']->fresh()->getRawOriginal('status'))->toBe('pending');
});

test('wrong year missing subjects and missing evidence prevent verification', function (string $problem) {
    $fixture = sourceWorkflowFixture();
    $source = $fixture['exam'];
    match ($problem) {
        'year' => $source->update(['exam_year' => 2027]),
        'subjects' => $source->subjectScores()->where('subject_code', 'MATH')->delete(),
        'evidence' => Storage::disk(CandidateFiles::DISK)->delete($source->evidence_path),
    };
    $service = app(NativeSourceVerification::class);
    $expected = NativeWishRegistration::hash($service->inspect($source));
    expect(fn () => $service->verify($fixture['application']->id, 'thpt', $source->id, $expected))->toThrow(ValidationException::class);
    expect($source->fresh()->getRawOriginal('status'))->toBe('pending');
})->with(['year', 'subjects', 'evidence']);

test('source verification and reviewer evidence access reject Candidate', function () {
    $fixture = sourceWorkflowFixture();
    $this->actingAs($fixture['application']->candidateProfile->user);
    $service = app(NativeSourceVerification::class);
    $expected = NativeWishRegistration::hash($service->inspect($fixture['exam']));
    expect(fn () => $service->verify($fixture['application']->id, 'thpt', $fixture['exam']->id, $expected))->toThrow(HttpException::class);
    $this->actingAs(CandidateProfile::factory()->create()->user);
    $this->get(route('candidate.admission-information.evidence', ['type' => 'exam-results', 'record' => $fixture['exam']->id]))->assertNotFound();
});

test('THPT form rejects duplicate years invalid points and Staff access', function () {
    $fixture = sourceWorkflowFixture();
    $this->actingAs($fixture['application']->candidateProfile->user);
    Livewire::test(NativeExamScores::class)->set('year', '2026')->set('scores', ['MATH' => 8, 'PHYSICS' => 8, 'CHEMISTRY' => 8])
        ->set('evidence', sourceEvidenceImage())->call('save')->assertHasErrors('year');
    Livewire::test(NativeExamScores::class)->set('year', '2025')->set('scores.MATH', 11)->call('save')->assertHasErrors(['scores.MATH', 'evidence']);
    $this->assertDatabaseCount('candidate_exam_results', 1);
    $this->actingAs($fixture['admin']);
    Livewire::test(NativeExamScores::class)->assertForbidden();
});

test('demo transcript rule UI defaults correctly and approves binds a separate version preserving old rule', function () {
    $fixture = sourceWorkflowFixture();
    $program = $fixture['application']->admissionRound->programs()->orderByDesc('id')->firstOrFail();
    $program->admissionMethod->update(['code' => 'DEMO-HB-EQ1']);
    $old = $program->evaluationRule;
    $before = $old->getAttributes();
    $payload = [...$old->payload, 'minimum_total_score' => 19];
    Livewire::test(EvaluationRules::class, ['programId' => $program->id])->assertSet('template', 'TRANSCRIPT_SCORE')
        ->set('template', 'THPT_SCORE')->set('payload', $payload)->set('reason', 'Correct demo template')->call('saveDraft')->assertHasErrors('rules')
        ->set('template', 'TRANSCRIPT_SCORE')->set('payload', $payload)->call('saveDraft')->assertHasNoErrors()
        ->call('approve')->set('reason', 'Bind corrected demo version')->call('bind')->assertHasNoErrors();
    expect($program->fresh()->evaluationRule->template_identifier)->toBe('TRANSCRIPT_SCORE');
    expect($program->fresh()->evaluationRule->version)->toBe(2);
    expect($old->fresh()->getAttributes())->toBe($before);
});
