<?php

use App\Actions\CandidateFiles;
use App\Enums\ExamType;
use App\Enums\VerificationStatus;
use App\Livewire\Candidate\AdmissionInformation;
use App\Livewire\Candidate\Notifications;
use App\Livewire\Candidate\Profile;
use App\Models\Application;
use App\Models\CandidateCertificate;
use App\Models\CandidateExamResult;
use App\Models\CandidateProfile;
use App\Models\CandidateTranscript;
use App\Models\CandidateTranscriptEvidence;
use App\Models\User;
use App\Notifications\ApplicationSubmitted;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

function correctionImage(string $name = 'page.png', string $fixture = 'small.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('tests/Fixtures/'.$fixture)));
}

test('new valid profile photo previews before save and then displays the persisted photo', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create(['photo_path' => 'candidate-photos/old.png']);
    Storage::disk(CandidateFiles::DISK)->put($profile->photo_path, file_get_contents(base_path('tests/Fixtures/portrait.png')));
    $this->actingAs($profile->user);

    $page = Livewire::test(Profile::class)->set('photo', correctionImage('new.png', 'portrait.png'))
        ->assertHasNoErrors()->assertSee('Ảnh hồ sơ mới chưa lưu');
    $page->assertSee($page->get('photo')->temporaryUrl());
    expect($profile->fresh()->photo_path)->toBe('candidate-photos/old.png');

    $page->call('save')->assertHasNoErrors()->assertSet('photo', null)->assertDontSee('Ảnh hồ sơ mới chưa lưu');
    expect($profile->refresh()->photo_path)->not->toBe('candidate-photos/old.png');
    Storage::disk(CandidateFiles::DISK)->assertExists($profile->photo_path);
    Storage::disk(CandidateFiles::DISK)->assertMissing('candidate-photos/old.png');
});

test('invalid profile photo shows an immediate error and preserves stored photo', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create(['photo_path' => 'candidate-photos/old.png']);
    Storage::disk(CandidateFiles::DISK)->put($profile->photo_path, file_get_contents(base_path('tests/Fixtures/portrait.png')));
    $this->actingAs($profile->user);

    Livewire::test(Profile::class)->set('photo', correctionImage())
        ->assertHasErrors('photo')->assertDontSee('Ảnh hồ sơ mới chưa lưu')
        ->call('save')->assertHasErrors('photo');
    expect($profile->fresh()->photo_path)->toBe('candidate-photos/old.png');
    Storage::disk(CandidateFiles::DISK)->assertExists($profile->photo_path);
});

test('priority objects retain leading zeros through selection save reload and editing', function (string $value) {
    $user = User::factory()->create();
    $this->actingAs($user);
    Livewire::test(Profile::class)->assertSee('Đối tượng '.$value)->assertSee('value="'.$value.'"', false)
        ->set('form.priority_object', $value)->call('save')->assertHasNoErrors();
    expect($user->candidateProfile()->sole()->getRawOriginal('priority_object'))->toBe($value);
    Livewire::test(Profile::class)->assertSet('form.priority_object', $value)
        ->set('form.priority_object', $value === '06' ? '01' : '06')->call('save')->assertHasNoErrors();
    Livewire::test(Profile::class)->assertSet('form.priority_object', $value === '06' ? '01' : '06');
})->with(['01', '02', '03', '04', '05', '06']);

test('no priority is stored as null when cleared and remains null on reload', function () {
    $profile = CandidateProfile::factory()->create(['priority_object' => '04']);
    $this->actingAs($profile->user);
    Livewire::test(Profile::class)->set('form.priority_object', '')->call('save')->assertHasNoErrors();
    expect($profile->fresh()->priority_object)->toBeNull();
    Livewire::test(Profile::class)->assertSet('form.priority_object', null)->call('save')->assertHasNoErrors();
});

test('candidate navigation follows the journey and next links are outside forms', function () {
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $response = $this->get(route('candidate.profile.edit'));
    $response->assertSeeInOrder(['Hồ sơ cá nhân', 'Thông tin tuyển sinh', 'Đăng ký nguyện vọng', 'Kết quả xét tuyển', 'Thông báo'])
        ->assertDontSee('Đăng ký xét tuyển');

    foreach ([
        ['profile.edit', 'Thông tin tuyển sinh', 'admission-information.index'],
        ['admission-information.index', 'Đăng ký nguyện vọng', 'applications.index'],
        ['applications.index', 'Kết quả xét tuyển', 'results.index'],
        ['results.index', 'Thông báo', 'notifications.index'],
    ] as [$page, $label, $next]) {
        $html = $this->get(route('candidate.'.$page))->assertSee('Tiếp theo: '.$label)->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);
        $links = $xpath->query('//a[contains(., "Tiếp theo:")]');
        expect($links->length)->toBe(1);
        expect($links->item(0)->getAttribute('href'))->toBe(route('candidate.'.$next));
        expect($xpath->query('ancestor::form', $links->item(0))->length)->toBe(0);
    }
    $this->get(route('candidate.notifications.index'))->assertDontSee('Tiếp theo:');
    $view = file_get_contents(resource_path('views/livewire/candidate/profile.blade.php'));
    expect($view)->toContain('grid items-start')->not->toMatch('/(?:min-h-|h-screen|h-full)/');
});

test('one certificate is loaded for editing and a stale new form cannot create another', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $stale = Livewire::test(AdmissionInformation::class);
    $certificate = CandidateCertificate::factory()->for($profile)->create(['certificate_type' => 'ielts']);
    $stale->set('certificateForm.certificate_type', 'sat')->set('certificateForm.score', '1400')
        ->set('certificateEvidence', correctionImage())->call('saveCertificate')->assertHasErrors('certificateForm');
    expect($profile->certificates()->count())->toBe(1);
    Livewire::test(AdmissionInformation::class)->assertSet('certificateId', $certificate->id)
        ->assertSet('certificateForm.certificate_type', 'ielts')->call('createCertificate')
        ->assertSet('certificateId', $certificate->id)->set('certificateForm.certificate_type', 'sat')
        ->call('saveCertificate')->assertHasNoErrors();
    expect($profile->certificates()->sole()->certificate_type->value)->toBe('sat');
});

test('one competency entry is loaded for editing without counting or changing THPT', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $thpt = CandidateExamResult::factory()->for($profile)->create(['exam_type' => ExamType::Thpt]);
    $this->actingAs($profile->user);
    $stale = Livewire::test(AdmissionInformation::class);
    $exam = CandidateExamResult::factory()->for($profile)->create(['exam_type' => ExamType::Dgnl]);
    $stale->set('competencyForm.exam_type', 'spt')->set('competencyForm.overall_score', '80')
        ->set('competencyEvidence', correctionImage())->call('saveCompetency')->assertHasErrors('competencyForm');
    Livewire::test(AdmissionInformation::class)->assertSet('competencyId', $exam->id)
        ->call('createCompetency')->assertSet('competencyId', $exam->id)
        ->set('competencyForm.exam_type', 'vsat')->call('saveCompetency')->assertHasNoErrors();
    expect($profile->examResults()->count())->toBe(2);
    expect($thpt->fresh()->exam_type)->toBe(ExamType::Thpt);
    expect($exam->fresh()->exam_type)->toBe(ExamType::Vsat);
});

test('verified single entries cannot be replaced through the create action', function (string $model, string $create, array $attributes) {
    $record = $model::factory()->create([...$attributes, 'status' => VerificationStatus::Verified]);
    $this->actingAs($record->candidateProfile->user);
    Livewire::test(AdmissionInformation::class)->call($create)->assertForbidden();
    expect($record->fresh()->status)->toBe(VerificationStatus::Verified);
})->with([
    [CandidateCertificate::class, 'createCertificate', []],
    [CandidateExamResult::class, 'createCompetency', ['exam_type' => ExamType::Dgnl]],
]);

test('both declaration checkboxes disclose separate forms and save pending requests only', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $original = $profile->fresh()->getAttributes();
    $this->actingAs($profile->user);
    $page = Livewire::test(AdmissionInformation::class)->assertDontSee('Thông tin đề nghị và căn cứ minh chứng');
    foreach (['direct_admission', 'priority_admission'] as $type) {
        $page->set('claimSelections.'.$type, true)->assertSee('Thông tin đề nghị và căn cứ minh chứng')
            ->set('declarations.'.$type.'.description', 'Đề nghị đối chiếu giấy tờ')
            ->set('declarationEvidence.'.$type, correctionImage())->call('saveDeclaration', $type)->assertHasNoErrors();
    }
    expect($profile->admissionClaims()->orderBy('claim_type')->pluck('claim_type')->all())->toBe(['direct_admission', 'priority_admission']);
    expect($profile->admissionClaims()->where('status', VerificationStatus::Pending)->count())->toBe(2);
    expect($profile->fresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('applications', 0);
    Livewire::test(AdmissionInformation::class)->assertSet('claimSelections.direct_admission', true)
        ->assertSet('claimSelections.priority_admission', true)
        ->set('declarations.direct_admission.description', 'Cập nhật')->call('saveDeclaration', 'direct_admission')->assertHasNoErrors();
    expect($profile->admissionClaims()->count())->toBe(2);
});

test('transcript grid contains all canonical subjects in three independent collapsible classes', function () {
    $subjects = [
        'MATH' => 'Toán', 'LITERATURE' => 'Ngữ văn', 'ENG' => 'Tiếng Anh', 'PHYSICS' => 'Vật lý',
        'CHEMISTRY' => 'Hóa học', 'BIOLOGY' => 'Sinh học', 'HISTORY' => 'Lịch sử', 'GEOGRAPHY' => 'Địa lý',
        'CIVICS' => 'GDCD', 'ECONOMIC_LAW' => 'KTPL', 'INDUSTRIAL_TECH' => 'Công nghệ Công nghiệp',
        'INFORMATICS' => 'Tin học', 'JAPANESE' => 'Tiếng Nhật', 'KOREAN' => 'Tiếng Hàn',
        'CHINESE' => 'Tiếng Trung', 'FRENCH' => 'Tiếng Pháp', 'RUSSIAN' => 'Tiếng Nga',
    ];
    expect(config('admission_data.subjects'))->toBe($subjects);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $page = Livewire::test(AdmissionInformation::class)->call('createTranscript');
    expect($page->get('transcriptForm.subjects'))->toHaveCount(17);
    foreach ([10, 11, 12] as $grade) {
        $page->assertSee('aria-controls="transcript-grade-'.$grade.'-scores"', false)->assertSee('Lớp '.$grade);
    }
    foreach ($subjects as $label) {
        $page->assertSee($label);
    }
    $page->assertDontSee('addTranscriptSubject')->assertSee('sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', false);
    expect(config('admission_data.exam_types.thpt.label'))->toBe('THPT');
});

test('multiple transcript images preview save privately replace and delete with their parent', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $page = Livewire::test(AdmissionInformation::class)->call('createTranscript')
        ->set('transcriptForm.subjects.0.grade_10', '8')
        ->set('transcriptForm.subjects.0.grade_11', '8.5')
        ->set('transcriptForm.subjects.0.grade_12', '9')
        ->set('transcriptEvidence', [correctionImage('one.png'), correctionImage('two.png')])
        ->assertHasNoErrors()->assertSee('Trang học bạ mới 1')->assertSee('Trang học bạ mới 2')
        ->call('saveTranscript')->assertHasNoErrors();
    $transcript = $profile->transcripts()->sole();
    expect($transcript->evidence_path)->toBeNull();
    expect($transcript->scores()->count())->toBe(3);
    $images = $transcript->evidenceImages()->get();
    expect($images)->toHaveCount(2);
    expect($images->pluck('original_name')->all())->toBe(['one.png', 'two.png']);
    foreach ($images as $image) {
        Storage::disk(CandidateFiles::DISK)->assertExists($image->path);
        $page->assertDontSee($image->path);
        $response = $this->get(route('candidate.admission-information.evidence', ['type' => 'transcript-images', 'record' => $image->id]))
            ->assertOk()->assertHeader('Content-Type', 'image/png');
        expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    }
    $page->call('editTranscript', $transcript->id)->set('removedTranscriptEvidence', [$images[0]->id])
        ->set('transcriptEvidence', [correctionImage('replacement.png')])->call('saveTranscript')->assertHasNoErrors();
    expect($transcript->evidenceImages()->count())->toBe(2);
    Storage::disk(CandidateFiles::DISK)->assertMissing($images[0]->path);
    Storage::disk(CandidateFiles::DISK)->assertExists($images[1]->path);
    $paths = $transcript->evidenceImages()->pluck('path')->all();
    $page->call('deleteTranscript', $transcript->id)->assertHasNoErrors();
    $this->assertDatabaseCount('candidate_transcript_evidence', 0);
    Storage::disk(CandidateFiles::DISK)->assertMissing($paths);
});

test('foreign transcript evidence cannot be viewed or removed', function () {
    Storage::fake(CandidateFiles::DISK);
    $own = CandidateTranscript::factory()->create();
    $foreign = CandidateTranscript::factory()->create();
    $image = $foreign->evidenceImages()->create(['path' => 'candidate-transcripts/'.$foreign->candidate_profile_id.'/foreign.png']);
    $this->actingAs($own->candidateProfile->user);
    $this->get(route('candidate.admission-information.evidence', ['type' => 'transcript-images', 'record' => $image->id]))->assertNotFound();
    expect(fn () => Livewire::test(AdmissionInformation::class)->call('editTranscript', $own->id)
        ->set('transcriptForm.subjects.0.grade_10', '8')->set('removedTranscriptEvidence', [$image->id])
        ->call('saveTranscript'))->toThrow(ModelNotFoundException::class);
    $this->assertModelExists($image);
});

test('verified transcript refuses evidence changes even when verified after editing began', function () {
    Storage::fake(CandidateFiles::DISK);
    $transcript = CandidateTranscript::factory()->create();
    $image = $transcript->evidenceImages()->create(['path' => 'candidate-transcripts/'.$transcript->candidate_profile_id.'/old.png']);
    $this->actingAs($transcript->candidateProfile->user);
    $page = Livewire::test(AdmissionInformation::class)->call('editTranscript', $transcript->id)
        ->set('transcriptForm.subjects.0.grade_10', '8')->set('removedTranscriptEvidence', [$image->id])
        ->set('transcriptEvidence', [correctionImage()]);
    $transcript->update(['status' => VerificationStatus::Verified]);
    $page->call('saveTranscript')->assertForbidden();
    $this->assertModelExists($image);
    expect($transcript->evidenceImages()->count())->toBe(1);
});

test('transcript image persistence failure rolls back removals and cleans all new files', function () {
    Storage::fake(CandidateFiles::DISK);
    $transcript = CandidateTranscript::factory()->create();
    $image = $transcript->evidenceImages()->create(['path' => 'candidate-transcripts/'.$transcript->candidate_profile_id.'/old.png']);
    Storage::disk(CandidateFiles::DISK)->put($image->path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $this->actingAs($transcript->candidateProfile->user);
    $page = Livewire::test(AdmissionInformation::class)->call('editTranscript', $transcript->id)
        ->set('transcriptForm.subjects.0.grade_10', '8')->set('removedTranscriptEvidence', [$image->id])
        ->set('transcriptEvidence', [correctionImage('one.png'), correctionImage('two.png')]);
    $attempt = 0;
    Event::listen('eloquent.creating: '.CandidateTranscriptEvidence::class, function () use (&$attempt): void {
        if (++$attempt === 2) {
            throw new RuntimeException('Simulated second image failure');
        }
    });
    try {
        expect(fn () => $page->call('saveTranscript'))->toThrow(RuntimeException::class);
        $this->assertModelExists($image);
        expect($transcript->evidenceImages()->count())->toBe(1);
        expect(Storage::disk(CandidateFiles::DISK)->files('candidate-transcripts/'.$transcript->candidate_profile_id))->toBe([$image->path]);
    } finally {
        Event::forget('eloquent.creating: '.CandidateTranscriptEvidence::class);
    }
});

test('transcript rejects an oversized individual image without writing permanent evidence', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $large = UploadedFile::fake()->createWithContent('large.png', file_get_contents(base_path('tests/Fixtures/small.png')).str_repeat(' ', 2 * 1024 * 1024));
    Livewire::test(AdmissionInformation::class)->call('createTranscript')
        ->set('transcriptForm.subjects.0.grade_10', '8')->set('transcriptEvidence', [correctionImage(), $large])
        ->assertHasErrors('transcriptEvidence.1')->call('saveTranscript')->assertHasErrors('transcriptEvidence.1');
    $this->assertDatabaseCount('candidate_transcript_evidence', 0);
    $this->assertDatabaseCount('candidate_transcripts', 0);
});

test('removing the final transcript image without replacement rolls back the removal', function () {
    Storage::fake(CandidateFiles::DISK);
    $transcript = CandidateTranscript::factory()->create(['evidence_path' => null]);
    $image = $transcript->evidenceImages()->create(['path' => 'candidate-transcripts/'.$transcript->candidate_profile_id.'/only.png']);
    Storage::disk(CandidateFiles::DISK)->put($image->path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $this->actingAs($transcript->candidateProfile->user);

    Livewire::test(AdmissionInformation::class)->call('editTranscript', $transcript->id)
        ->set('transcriptForm.subjects.0.grade_10', '8')->set('removedTranscriptEvidence', [$image->id])
        ->call('saveTranscript')->assertHasErrors('transcriptEvidence');
    $this->assertModelExists($image);
    Storage::disk(CandidateFiles::DISK)->assertExists($image->path);
});

test('a nonimage transcript upload cannot preview or persist after another editor clears errors', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    Livewire::test(AdmissionInformation::class)->call('createTranscript')
        ->set('transcriptForm.subjects.0.grade_10', '8')
        ->set('transcriptEvidence', [UploadedFile::fake()->create('document.pdf', 10, 'application/pdf')])
        ->assertHasErrors('transcriptEvidence.0')->call('createCertificate')
        ->assertDontSee('Trang học bạ mới 1')->call('saveTranscript')->assertHasErrors('transcriptEvidence.0');
    $this->assertDatabaseCount('candidate_transcript_evidence', 0);
});

test('legacy transcript evidence can be replaced by normalized images on resubmission', function () {
    Storage::fake(CandidateFiles::DISK);
    $transcript = CandidateTranscript::factory()->create([
        'evidence_path' => 'candidate-transcripts/legacy.png', 'status' => VerificationStatus::Rejected,
        'rejection_reason' => 'Thiếu trang', 'verified_by' => User::factory(), 'verified_at' => now(),
    ]);
    Storage::disk(CandidateFiles::DISK)->put($transcript->evidence_path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $this->actingAs($transcript->candidateProfile->user);

    Livewire::test(AdmissionInformation::class)->call('editTranscript', $transcript->id)
        ->set('transcriptForm.subjects.0.grade_12', '9')->set('removeLegacyTranscriptEvidence', true)
        ->set('transcriptEvidence', [correctionImage()])->call('saveTranscript')->assertHasNoErrors();
    expect($transcript->refresh()->evidence_path)->toBeNull();
    expect($transcript->status)->toBe(VerificationStatus::Pending);
    expect($transcript->verified_by)->toBeNull();
    expect($transcript->verified_at)->toBeNull();
    expect($transcript->rejection_reason)->toBeNull();
    expect($transcript->evidenceImages()->count())->toBe(1);
    Storage::disk(CandidateFiles::DISK)->assertMissing('candidate-transcripts/legacy.png');
});

test('a verified declaration cannot be changed through its checkbox form', function () {
    $profile = CandidateProfile::factory()->create();
    $claim = $profile->admissionClaims()->create(['claim_type' => 'direct_admission', 'description' => 'Đã đối chiếu', 'status' => VerificationStatus::Verified]);
    $this->actingAs($profile->user);

    Livewire::test(AdmissionInformation::class)->set('declarations.direct_admission.description', 'Thay đổi')
        ->call('saveDeclaration', 'direct_admission')->assertForbidden();
    expect($claim->fresh()->description)->toBe('Đã đối chiếu');
});

test('recognized notification with a foreign destination becomes read without redirecting', function () {
    $application = Application::factory()->create();
    $candidate = User::factory()->create();
    $candidate->notify(new ApplicationSubmitted($application->id));
    $notification = $candidate->notifications()->sole();
    $this->actingAs($candidate);

    Livewire::test(Notifications::class)->call('openNotification', $notification->id)->assertNoRedirect();
    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('notification click reads only the selected notification and redirects to its owned destination idempotently', function () {
    $application = Application::factory()->create();
    $candidate = $application->candidateProfile->user;
    $candidate->notify(new ApplicationSubmitted($application->id));
    $notification = $candidate->notifications()->sole();
    $candidate->notify(new ApplicationSubmitted($application->id));
    $this->actingAs($candidate);
    $this->get(route('candidate.results.index'))->assertSee('Tiếp theo: Thông báo');
    $this->get(route('candidate.notifications.index'))->assertOk();
    expect($candidate->unreadNotifications()->count())->toBe(2);

    Livewire::test(Notifications::class)->call('openNotification', $notification->id)
        ->assertRedirect(route('candidate.applications.show', $application->id));
    expect($candidate->unreadNotifications()->count())->toBe(1);
    $readAt = $notification->fresh()->read_at;
    $this->travel(1)->minutes();
    Livewire::test(Notifications::class)->call('openNotification', $notification->id)
        ->assertRedirect(route('candidate.applications.show', $application->id));
    expect($notification->fresh()->read_at->equalTo($readAt))->toBeTrue();
});

test('notification click cannot read another candidates notification', function () {
    $application = Application::factory()->create();
    $candidate = $application->candidateProfile->user;
    $candidate->notify(new ApplicationSubmitted($application->id));
    $notification = $candidate->notifications()->sole();
    $this->actingAs(User::factory()->create());
    expect(fn () => Livewire::test(Notifications::class)->call('openNotification', $notification->id))->toThrow(ModelNotFoundException::class);
    expect($notification->fresh()->read_at)->toBeNull();
});

test('unknown notification is marked read without using a supplied arbitrary URL', function () {
    $candidate = User::factory()->create();
    $notification = $candidate->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'unknown', 'data' => ['url' => 'https://evil.invalid'],
    ]);
    $this->actingAs($candidate);
    Livewire::test(Notifications::class)->call('openNotification', $notification->id)->assertNoRedirect()
        ->assertSee('Đã đọc')->assertDontSee('Chưa đọc')->assertDontSee('https://evil.invalid')
        ->assertDontSee('Đánh dấu đã đọc');
    expect($notification->fresh()->read_at)->not->toBeNull();
});
