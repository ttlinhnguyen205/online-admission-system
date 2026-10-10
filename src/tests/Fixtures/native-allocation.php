<?php

use App\Actions\AdmissionQuotaManagement;
use App\Actions\AdmissionReviewSnapshot;
use App\Actions\CandidateFiles;
use App\Actions\EvaluationRuleManagement;
use App\Actions\NativeAdmissionScoring;
use App\Actions\NativeAllocationPolicyManagement;
use App\Actions\NativeDeferredAcceptance;
use App\Actions\NativeSourceVerification;
use App\Actions\NativeWishRegistration;
use App\Actions\StaffApplicationReview;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Livewire\Candidate\Profile;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateExamResult;
use App\Models\CandidateExamSubjectScore;
use App\Models\CandidateMajorOffering;
use App\Models\CandidateTranscript;
use App\Models\CandidateTranscriptScore;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function allocationFixture(bool $verifyAfterSubmission = false): array
{
    test()->travelTo(now()->setDate(2026, 10, 1)->setTime(12, 0));
    config(['admission_registration.native_registration' => true]);
    Notification::fake();
    Storage::fake(CandidateFiles::DISK);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    test()->actingAs($admin);
    $round = AdmissionRound::factory()->create(['start_date' => '2026-10-10', 'end_date' => '2026-10-31', 'status' => 'draft', 'native_registration_state' => 'native_open']);
    $thpt = AdmissionProgram::factory()->for($round)->create(['quota' => 0]);
    $transcript = AdmissionProgram::factory()->for($round)->for($thpt->major)->create(['quota' => 0]);
    $offering = CandidateMajorOffering::factory()->for($thpt)->create();
    $rules = app(EvaluationRuleManagement::class);
    foreach ([$thpt, $transcript] as $program) {
        $isTranscript = $program->id === $transcript->id;
        $payload = ['subjects' => $isTranscript ? ['MATH', 'LITERATURE', 'ENG'] : ['MATH', 'PHYSICS', 'CHEMISTRY'],
            'source_year' => 2026, 'minimum_subject_score' => 1, 'minimum_total_score' => 18, 'policy_reference' => 'Approved isolated fixture'];
        if ($isTranscript) {
            $payload['grade_level'] = 12;
        }
        $rule = $rules->createDraft($program->admission_method_id, $isTranscript ? 'TRANSCRIPT_SCORE' : 'THPT_SCORE', 1, $payload, 'Fixture');
        $rules->approve($rule->id);
        $rules->bind($program->id, $rule->id, null, 'Fixture');
    }
    $quotas = app(AdmissionQuotaManagement::class);
    $quota = $quotas->createDraft($offering->id, 'Fixture');
    $quotas->updateDraft($quota->id, ['total_quota' => 2, 'reason' => 'Fixture', 'limits' => [['program_id' => $thpt->id, 'quota' => 1], ['program_id' => $transcript->id, 'quota' => 1]]]);
    $quotas->approve($quota->id);
    $management = app(NativeAllocationPolicyManagement::class);
    $payload = ['algorithm' => NativeDeferredAcceptance::ALGORITHM, 'method_priority' => [$thpt->admission_method_id, $transcript->admission_method_id], 'rule_equivalences' => [], 'ties' => 'block', 'policy_reference' => 'Approved isolated allocation policy'];
    $policy = $management->createDraft($round->id, $payload);
    $management->approve($policy->id, $policy->content_hash);
    test()->travelTo(now()->setDate(2026, 10, 12));
    $round->update(['status' => 'open']);
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
    $exam = CandidateExamResult::factory()->create(['candidate_profile_id' => $profile->id, 'exam_type' => 'thpt', 'exam_year' => 2026, 'status' => 'verified', 'verified_by' => $admin->id, 'verified_at' => now()]);
    $source = CandidateTranscript::factory()->create(['candidate_profile_id' => $profile->id, 'graduation_year' => 2026, 'status' => 'verified', 'verified_by' => $admin->id, 'verified_at' => now()]);
    foreach (['MATH', 'PHYSICS', 'CHEMISTRY'] as $subject) {
        CandidateExamSubjectScore::factory()->create(['candidate_exam_result_id' => $exam->id, 'subject_code' => $subject, 'score' => 8]);
    }
    foreach (['MATH', 'LITERATURE', 'ENG'] as $subject) {
        CandidateTranscriptScore::factory()->create(['candidate_transcript_id' => $source->id, 'subject_code' => $subject, 'grade_level' => '12', 'score' => 8]);
    }
    if ($verifyAfterSubmission) {
        foreach ([$exam, $source] as $item) {
            $path = ($item instanceof CandidateTranscript ? 'candidate-transcripts/' : 'candidate-exam-results/').$profile->id.'/evidence.png';
            Storage::disk(CandidateFiles::DISK)->put($path, file_get_contents(base_path('tests/Fixtures/small.png')));
            $item->update(['status' => 'pending', 'verified_by' => null, 'verified_at' => null, 'evidence_path' => $path]);
        }
    }
    test()->actingAs($profile->user);
    app(NativeWishRegistration::class)->add($application->id, $offering->id);
    app(NativeWishRegistration::class)->submit($application->id);
    test()->actingAs($admin);
    $staff = User::factory()->create(['role' => UserRole::Staff]);
    if ($verifyAfterSubmission) {
        test()->actingAs($staff);
        $verification = app(NativeSourceVerification::class);
        foreach (['thpt' => $exam, 'transcript' => $source] as $type => $item) {
            $verification->verify($application->id, $type, $item->id, NativeWishRegistration::hash($verification->inspect($item->fresh())));
        }
    }
    $snapshots = app(AdmissionReviewSnapshot::class);
    $review = app(StaffApplicationReview::class);
    $review->start($application->id, $snapshots->fingerprint($snapshots->load($application->id)));
    $review->verify($application->id, $snapshots->fingerprint($snapshots->load($application->id)));
    app(NativeAdmissionScoring::class)->score($application->id);
    test()->actingAs($admin);
    $round->setAttribute('native_registration_state', 'native_closed');
    $round->status = 'closed';
    $round->save();

    return compact('admin', 'staff', 'round', 'policy', 'payload', 'application', 'thpt', 'transcript');
}
