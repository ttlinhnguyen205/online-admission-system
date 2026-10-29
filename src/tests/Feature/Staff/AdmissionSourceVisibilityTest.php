<?php

use App\Actions\CandidateApplications;
use App\Actions\CandidateFiles;
use App\Actions\StaffApplicationReview;
use App\Enums\ApplicationStatus;
use App\Livewire\Admin\ApplicationDetails;
use App\Livewire\Candidate\NativeExamScores;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\CandidateAdmissionClaim;
use App\Models\CandidateCertificate;
use App\Models\CandidateExamResult;
use App\Models\CandidateTranscript;
use App\Models\User;
use App\Support\AdmissionSourceEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/ReviewFixtures.php';

function legacySource(Application $application, bool $flat = false): CandidateExamResult
{
    $path = 'candidate-exam-results/'.($flat ? 'historical.png' : $application->candidate_profile_id.'/exam.png');
    Storage::disk(CandidateFiles::DISK)->put($path, file_get_contents(base_path('tests/Fixtures/small.png')));

    return CandidateExamResult::factory()->for($application->candidateProfile)->create(['exam_type' => 'thpt', 'evidence_path' => $path]);
}

function legacySourceUrl(Application $application, CandidateExamResult $source): string
{
    return route('candidate.admission-information.evidence', ['type' => 'exam-results', 'record' => $source->id, 'application' => $application->id]);
}

test('uploaded source stays draft until submission then Legacy staff can view and verify it', function () {
    Notification::fake();
    $application = reviewApplication(ApplicationStatus::Draft);
    $application->update(['submitted_at' => null]);
    $application->admissionRound->update(['status' => 'open', 'start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(NativeExamScores::class)->set('year', '2026')->set('scores', ['MATH' => '8'])
        ->set('evidence', UploadedFile::fake()->createWithContent('exam.png', file_get_contents(base_path('tests/Fixtures/small.png'))))
        ->call('save')->assertHasNoErrors();
    $source = $application->candidateProfile->examResults()->sole();
    expect($source->getRawOriginal('status'))->toBe('pending');
    expect($application->fresh()->status)->toBe(ApplicationStatus::Draft);
    expect(AdmissionSourceEvidence::load($application->fresh()))->toBeEmpty();
    $staff = User::factory()->create(['role' => 'staff']);
    $this->actingAs($staff)->get(route('admin.applications.index'))->assertDontSee($application->application_code);
    $this->get(legacySourceUrl($application, $source))->assertNotFound();
    $this->actingAs($application->candidateProfile->user);
    app(CandidateApplications::class)->submit($application->id);
    expect($application->fresh()->status)->toBe(ApplicationStatus::Submitted);
    $this->actingAs($staff)->get(route('admin.applications.index'))->assertSee($application->application_code);
    $this->get(route('admin.applications.show', $application->id))->assertOk()->assertSee('Minh chứng thông tin xét tuyển');
    $this->get(legacySourceUrl($application, $source))->assertOk()->assertHeader('Content-Type', 'image/png');
    $review = app(StaffApplicationReview::class);
    $review->start($application->id, reviewToken($application));
    expect(fn () => $review->verify($application->id, reviewToken($application)))->toThrow(ValidationException::class);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->call('confirm', 'verifyAdmissionSource', $source->id, 'exam-results')->call('perform')->assertHasNoErrors();
    expect($source->fresh()->getRawOriginal('status'))->toBe('verified');
    expect($source->fresh()->verified_by)->toBe($staff->id);
    $review->verify($application->id, reviewToken($application));
    expect($application->fresh()->status)->toBe(ApplicationStatus::Verified);
    $this->assertDatabaseCount('candidate_exam_results', 1);
    $this->assertDatabaseCount('candidate_scores', 0);
    $this->assertDatabaseCount('candidate_documents', 0);
    $this->assertDatabaseHas('activity_logs', ['action' => 'admission_source.verified', 'subject_id' => $source->id]);
});

test('historical flat evidence is readable but another profile folder and mismatched application are denied', function () {
    $application = reviewApplication();
    $source = legacySource($application, true);
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    $this->get(legacySourceUrl($application, $source))->assertOk();
    $other = Application::factory()->create(['status' => 'submitted', 'submitted_at' => now()]);
    $this->get(legacySourceUrl($other, $source))->assertNotFound();
    $source->update(['evidence_path' => 'candidate-exam-results/'.$other->candidate_profile_id.'/foreign.png']);
    Storage::disk(CandidateFiles::DISK)->put($source->evidence_path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $this->get(legacySourceUrl($application, $source))->assertNotFound();
    expect(fn () => app(StaffApplicationReview::class)->reviewAdmissionSource($application->id, 'exam-results', $source->id, reviewToken($application), true))->toThrow(ValidationException::class);
    expect($source->fresh()->getRawOriginal('status'))->toBe('pending');
});

test('failed source or audit save rolls back review and never reports success', function (string $model) {
    $application = reviewApplication();
    $source = legacySource($application);
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->call('confirm', 'verifyAdmissionSource', $source->id, 'exam-results');
    $event = 'eloquent.saving: '.$model;
    Event::listen($event, fn () => false);
    try {
        $page->call('perform')->assertHasErrors('review')->assertSet('showConfirmation', true)
            ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['dataset']['variant'] === 'danger')
            ->assertNotDispatched('toast-show', fn (string $event, array $params): bool => $params['dataset']['variant'] === 'success');
        expect($source->fresh()->getRawOriginal('status'))->toBe('pending');
        $this->assertDatabaseCount('activity_logs', 0);
    } finally {
        Event::forget($event);
    }
})->with([CandidateExamResult::class, ActivityLog::class]);

test('rejected source blocks review and candidate can replace it without duplication after revision request', function () {
    Notification::fake();
    $application = reviewApplication();
    $application->admissionRound->update(['status' => 'open', 'start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $source = legacySource($application);
    $staff = User::factory()->create(['role' => 'staff']);
    $this->actingAs($staff);
    $review = app(StaffApplicationReview::class);
    $review->reviewAdmissionSource($application->id, 'exam-results', $source->id, reviewToken($application), false, ['rejection_reason' => 'Ảnh không rõ']);
    expect($source->fresh()->rejection_reason)->toBe('Ảnh không rõ');
    expect(fn () => $review->verify($application->id, reviewToken($application)))->toThrow(ValidationException::class);
    $review->requestRevision($application->id, reviewToken($application), ['revision_reason' => 'Tải lại minh chứng rõ nét']);
    $this->get(route('admin.applications.index'))->assertDontSee($application->application_code);
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(NativeExamScores::class)->assertSee('Ảnh không rõ')->call('edit', $source->id)
        ->set('scores', ['MATH' => '8'])->set('evidence', UploadedFile::fake()->createWithContent('new.png', file_get_contents(base_path('tests/Fixtures/small.png'))))
        ->call('save')->assertHasNoErrors();
    expect($source->fresh()->getRawOriginal('status'))->toBe('pending');
    app(CandidateApplications::class)->submit($application->id);
    $this->actingAs($staff)->get(route('admin.applications.index'))->assertSee($application->application_code);
    $this->assertDatabaseCount('candidate_exam_results', 1);
});

test('source changes invalidate the review snapshot', function () {
    $application = reviewApplication();
    $source = legacySource($application);
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    $token = reviewToken($application);
    $source->update(['exam_year' => 2025]);
    expect(fn () => app(StaffApplicationReview::class)->reviewAdmissionSource($application->id, 'exam-results', $source->id, $token, true))->toThrow(ValidationException::class);
    expect($source->fresh()->getRawOriginal('status'))->toBe('pending');
});

test('candidate and inactive staff cannot review source evidence', function (bool $candidate) {
    $application = reviewApplication();
    $source = legacySource($application);
    $actor = $candidate ? User::factory()->create(['role' => 'candidate']) : User::factory()->create(['role' => 'staff', 'status' => 'inactive']);
    $this->actingAs($actor)->get(legacySourceUrl($application, $source))->assertStatus($candidate ? 404 : 403);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->assertForbidden();
})->with([true, false]);

test('Legacy reviewers can view and audit each normalized source type', function (string $type, string $model) {
    $application = reviewApplication();
    $path = 'candidate-'.$type.'/'.$application->candidate_profile_id.'/source.png';
    Storage::disk(CandidateFiles::DISK)->put($path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $source = $model::factory()->for($application->candidateProfile)->create(['evidence_path' => $path]);
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    $this->get(route('admin.applications.show', $application->id))->assertOk();
    $this->get(route('candidate.admission-information.evidence', ['type' => $type, 'record' => $source->id, 'application' => $application->id]))->assertOk();
    app(StaffApplicationReview::class)->reviewAdmissionSource($application->id, $type, $source->id, reviewToken($application), true);
    expect($source->fresh()->getRawOriginal('status'))->toBe('verified');
    $entry = ActivityLog::sole();
    expect($entry->subject_type)->toBe($source->getMorphClass());
    expect(array_keys($entry->new_values))->toEqualCanonicalizing(['status', 'verified_by', 'verified_at', 'rejection_reason']);
})->with([
    ['exam-results', CandidateExamResult::class],
    ['transcripts', CandidateTranscript::class],
    ['certificates', CandidateCertificate::class],
    ['admission-claims', CandidateAdmissionClaim::class],
]);

test('Native applications cannot use the Legacy source review action', function () {
    Storage::fake(CandidateFiles::DISK);
    $application = Application::factory()->create(['registration_mode' => 'native', 'status' => 'under_review', 'submitted_at' => now()]);
    $source = legacySource($application);
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    expect(fn () => app(StaffApplicationReview::class)->reviewAdmissionSource($application->id, 'exam-results', $source->id, reviewToken($application), true))
        ->toThrow(HttpException::class);
    expect($source->fresh()->getRawOriginal('status'))->toBe('pending');
});
