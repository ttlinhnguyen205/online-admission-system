<?php

use App\Actions\CandidateFiles;
use App\Enums\ExamType;
use App\Enums\VerificationStatus;
use App\Livewire\Candidate\AdmissionInformation;
use App\Models\CandidateAdmissionClaim;
use App\Models\CandidateCertificate;
use App\Models\CandidateExamResult;
use App\Models\CandidateProfile;
use App\Models\CandidateTranscript;
use App\Models\CandidateTranscriptScore;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function admissionInformationImage(string $name = 'evidence.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('tests/Fixtures/small.png')));
}

test('candidate admission information page contains four Vietnamese sections', function () {
    $profile = CandidateProfile::factory()->create();

    $response = $this->actingAs($profile->user)->get(route('candidate.admission-information.index'))
        ->assertSee('Thông tin tuyển sinh')
        ->assertSeeInOrder([
            'Chứng chỉ',
            'Xét tuyển thẳng & ưu tiên xét tuyển',
            'Điểm ĐGNL/ĐGTD/V-SAT/SPT',
            'Điểm tổng kết học bạ THPT',
        ])
        ->assertDontSee('Điểm & minh chứng')->assertDontSee('Điểm thi tốt nghiệp THPT');
    expect(substr_count($response->getContent(), 'data-admission-section='))->toBe(4);
});

test('candidate creates edits replaces evidence and deletes a certificate', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $page = Livewire::test(AdmissionInformation::class)->call('createCertificate')
        ->set('certificateForm', [
            'certificate_type' => 'ielts', 'score' => '6.50', 'certificate_number' => 'IELTS-001',
            'issued_at' => '2026-01-15', 'expires_at' => '2028-01-15',
        ])->set('certificateEvidence', admissionInformationImage())->call('saveCertificate')->assertHasNoErrors();
    $certificate = $profile->certificates()->sole();
    $oldPath = $certificate->evidence_path;

    expect($certificate->score)->toBe('6.50');
    expect($certificate->status)->toBe(VerificationStatus::Pending);
    expect($oldPath)->toStartWith('candidate-certificates/'.$profile->id.'/');
    Storage::disk(CandidateFiles::DISK)->assertExists($oldPath);

    $page->call('editCertificate', $certificate->id)->set('certificateForm.score', '7.00')
        ->set('certificateEvidence', admissionInformationImage('replacement.png'))
        ->call('saveCertificate')->assertHasNoErrors();
    expect($certificate->refresh()->score)->toBe('7.00');
    expect($certificate->evidence_path)->not->toBe($oldPath);
    Storage::disk(CandidateFiles::DISK)->assertMissing($oldPath);
    Storage::disk(CandidateFiles::DISK)->assertExists($certificate->evidence_path);

    $page->call('deleteCertificate', $certificate->id)->assertHasNoErrors();
    $this->assertModelMissing($certificate);
    Storage::disk(CandidateFiles::DISK)->assertMissing($certificate->evidence_path);
});

test('certificate types use the canonical allow list without an invented score range', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);

    Livewire::test(AdmissionInformation::class)->call('createCertificate')
        ->set('certificateForm', [
            'certificate_type' => 'unsupported', 'score' => '1500.12', 'certificate_number' => null,
            'issued_at' => null, 'expires_at' => null,
        ])->set('certificateEvidence', admissionInformationImage())->call('saveCertificate')
        ->assertHasErrors('certificateForm.certificate_type');
    $this->assertDatabaseCount('candidate_certificates', 0);

    Livewire::test(AdmissionInformation::class)->call('createCertificate')
        ->set('certificateForm', [
            'certificate_type' => 'sat', 'score' => '1500.12', 'certificate_number' => null,
            'issued_at' => null, 'expires_at' => null,
        ])->set('certificateEvidence', admissionInformationImage())->call('saveCertificate')->assertHasNoErrors();
    expect($profile->certificates()->sole()->score)->toBe('1500.12');
});

test('certificate supports TOEIC and rejects a third decimal place', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);

    Livewire::test(AdmissionInformation::class)->call('createCertificate')
        ->assertSee('value="toeic"', false)->assertSee('TOEIC')
        ->set('certificateForm', [
            'certificate_type' => 'toeic', 'score' => '850.12', 'certificate_number' => null,
            'issued_at' => null, 'expires_at' => null,
        ])->set('certificateEvidence', admissionInformationImage())->call('saveCertificate')->assertHasNoErrors();

    expect($profile->certificates()->sole()->certificate_type->value)->toBe('toeic');

    Livewire::test(AdmissionInformation::class)->call('editCertificate', $profile->certificates()->sole()->id)
        ->set('certificateForm.score', '850.123')->call('saveCertificate')
        ->assertHasErrors('certificateForm.score');
});

test('certificate evidence required message is rendered once', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);

    $page = Livewire::test(AdmissionInformation::class)->call('createCertificate')
        ->set('certificateForm', [
            'certificate_type' => 'ielts', 'score' => '6.50', 'certificate_number' => null,
            'issued_at' => null, 'expires_at' => null,
        ])->call('saveCertificate')->assertHasErrors('certificateEvidence');

    expect(substr_count($page->html(), __('Vui lòng tải ảnh minh chứng.')))->toBe(1);
});

test('candidate creates edits and deletes a descriptive admission claim', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $page = Livewire::test(AdmissionInformation::class)->call('createClaim')
        ->set('claimForm', ['claim_type' => 'Diện theo giấy chứng nhận', 'claim_code' => null, 'description' => 'Thông tin cần đối chiếu'])
        ->set('claimEvidence', admissionInformationImage())->call('saveClaim')->assertHasNoErrors();
    $claim = $profile->admissionClaims()->sole();

    expect($claim->claim_type)->toBe('Diện theo giấy chứng nhận');
    expect($claim->status)->toBe(VerificationStatus::Pending);
    expect($claim->evidence_path)->toStartWith('candidate-admission-claims/'.$profile->id.'/');

    $page->call('editClaim', $claim->id)->set('claimForm.description', 'Thông tin đã chỉnh sửa')
        ->call('saveClaim')->assertHasNoErrors();
    expect($claim->refresh()->description)->toBe('Thông tin đã chỉnh sửa');
    $page->call('deleteClaim', $claim->id)->assertHasNoErrors();
    $this->assertModelMissing($claim);
});

test('candidate records every supported competency exam type', function (string $type) {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);

    $page = Livewire::test(AdmissionInformation::class)->call('createCompetency')
        ->set('competencyForm', [
            'exam_type' => $type, 'overall_score' => '120', 'exam_year' => 2026,
            'exam_date' => null, 'exam_session' => 'Đợt 1', 'registration_number' => 'ABC123',
        ])->set('competencyEvidence', admissionInformationImage())->call('saveCompetency')->assertHasNoErrors();
    $result = $profile->examResults()->sole();

    expect($result->exam_type->value)->toBe($type);
    expect($result->overall_score)->toBe('120');
    expect($result->subjectScores()->count())->toBe(0);
    $page->call('editCompetency', $result->id)->set('competencyForm.overall_score', '150')
        ->call('saveCompetency')->assertHasNoErrors();
    expect($result->refresh()->overall_score)->toBe('150');
    $page->call('deleteCompetency', $result->id)->assertHasNoErrors();
    $this->assertModelMissing($result);
})->with(['dgnl', 'dgtd', 'vsat', 'spt']);

test('competency result rejects THPT and arbitrary exam types', function (string $type) {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);

    Livewire::test(AdmissionInformation::class)->call('createCompetency')
        ->set('competencyForm', [
            'exam_type' => $type, 'overall_score' => '80', 'exam_year' => 2026,
            'exam_date' => null, 'exam_session' => null, 'registration_number' => null,
        ])->set('competencyEvidence', admissionInformationImage())->call('saveCompetency')
        ->assertHasErrors('competencyForm.exam_type');
    $this->assertDatabaseCount('candidate_exam_results', 0);
})->with(['thpt', 'unknown']);

test('competency score is an integer between zero and 150', function (string $score) {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);

    Livewire::test(AdmissionInformation::class)->call('createCompetency')
        ->set('competencyForm', [
            'exam_type' => 'dgnl', 'overall_score' => $score, 'exam_year' => 2026,
            'exam_date' => null, 'exam_session' => null, 'registration_number' => null,
        ])->set('competencyEvidence', admissionInformationImage())->call('saveCompetency')
        ->assertHasErrors('competencyForm.overall_score');
})->with(['150.5', '151']);

test('competency evidence required message is rendered once', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);

    $page = Livewire::test(AdmissionInformation::class)->call('createCompetency')
        ->set('competencyForm.exam_type', 'dgnl')
        ->set('competencyForm.overall_score', '150')
        ->call('saveCompetency')
        ->assertHasErrors('competencyEvidence');

    expect(substr_count($page->html(), __('Vui lòng tải ảnh minh chứng.')))->toBe(1);
});

test('transcript stores only nonempty normalized grade cells', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);

    $page = Livewire::test(AdmissionInformation::class)->call('createTranscript')
        ->set('transcriptForm', [
            'school_name' => 'Trường THPT Kiểm thử', 'graduation_year' => 2026,
            'subjects' => [
                ['subject_code' => 'MATH', 'grade_10' => '8.00', 'grade_11' => null, 'grade_12' => '9.00'],
                ['subject_code' => 'ENG', 'grade_10' => '', 'grade_11' => '7.50', 'grade_12' => null],
            ],
        ])->set('transcriptEvidence', [admissionInformationImage()])->call('saveTranscript')->assertHasNoErrors();
    $transcript = $profile->transcripts()->with('scores')->sole();

    expect($transcript->school_name)->toBe('Trường THPT Kiểm thử');
    expect($transcript->scores)->toHaveCount(3);
    expect($transcript->scores->sortBy(fn ($score) => $score->subject_code.$score->grade_level)
        ->map->only(['subject_code', 'grade_level', 'score'])->values()->all())->toBe([
            ['subject_code' => 'ENG', 'grade_level' => 11, 'score' => '7.50'],
            ['subject_code' => 'MATH', 'grade_level' => 10, 'score' => '8.00'],
            ['subject_code' => 'MATH', 'grade_level' => 12, 'score' => '9.00'],
        ]);
    expect($transcript->scores->contains(fn ($score) => $score->score === '0.000'))->toBeFalse();

    $page->call('editTranscript', $transcript->id)
        ->set('transcriptForm.subjects', [
            ['subject_code' => 'ENG', 'grade_10' => '8.50', 'grade_11' => null, 'grade_12' => null],
        ])
        ->call('saveTranscript')->assertHasNoErrors();
    expect($transcript->scores()->where('subject_code', 'ENG')->where('grade_level', 10)->value('score'))->toBe('8.50');
    $page->call('deleteTranscript', $transcript->id)->assertHasNoErrors();
    $this->assertModelMissing($transcript);
});

test('transcript saves when candidate fills a single grid score cell', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);

    Livewire::test(AdmissionInformation::class)->call('createTranscript')
        ->set('transcriptForm.subjects.0.grade_10', '8')
        ->set('transcriptEvidence', [admissionInformationImage()])
        ->call('saveTranscript')
        ->assertHasNoErrors();

    $transcript = $profile->transcripts()->with(['scores', 'evidenceImages'])->sole();
    expect($transcript->scores)->toHaveCount(1);
    expect($transcript->scores->first()->only(['subject_code', 'grade_level', 'score']))->toBe([
        'subject_code' => 'MATH',
        'grade_level' => 10,
        'score' => '8.00',
    ]);
    expect($transcript->evidenceImages)->toHaveCount(1);
});

test('transcript rejects duplicate subjects and requires one actual score', function (array $subjects) {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $page = Livewire::test(AdmissionInformation::class)->call('createTranscript')
        ->set('transcriptForm', ['school_name' => null, 'graduation_year' => 2026, 'subjects' => $subjects])
        ->set('transcriptEvidence', [admissionInformationImage()])->call('saveTranscript');

    $page->assertHasErrors();
    $this->assertDatabaseCount('candidate_transcripts', 0);
    $this->assertDatabaseCount('candidate_transcript_scores', 0);
})->with([
    'duplicate subject' => [[
        ['subject_code' => 'MATH', 'grade_10' => '8', 'grade_11' => null, 'grade_12' => null],
        ['subject_code' => 'MATH', 'grade_10' => null, 'grade_11' => '9', 'grade_12' => null],
    ]],
    'all cells empty' => [[
        ['subject_code' => 'MATH', 'grade_10' => null, 'grade_11' => null, 'grade_12' => null],
    ]],
]);

test('transcript parent child and evidence creation roll back together', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    Event::listen('eloquent.creating: '.CandidateTranscriptScore::class, function (): void {
        throw new RuntimeException('Simulated transcript score persistence failure');
    });

    try {
        expect(fn () => Livewire::test(AdmissionInformation::class)->call('createTranscript')
            ->set('transcriptForm', [
                'school_name' => null, 'graduation_year' => 2026,
                'subjects' => [['subject_code' => 'MATH', 'grade_10' => '8', 'grade_11' => null, 'grade_12' => null]],
            ])->set('transcriptEvidence', [admissionInformationImage()])->call('saveTranscript'))
            ->toThrow(RuntimeException::class);
        $this->assertDatabaseCount('candidate_transcripts', 0);
        $this->assertDatabaseCount('candidate_transcript_scores', 0);
        expect(Storage::disk(CandidateFiles::DISK)->allFiles())->toBe([]);
    } finally {
        Event::forget('eloquent.creating: '.CandidateTranscriptScore::class);
    }
});

test('candidate forms reject verification metadata injection', function (string $form, string $create, string $save) {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $page = Livewire::test(AdmissionInformation::class)->call($create)->set($form.'.status', 'verified');

    $page->call($save)->assertHasErrors($form);
})->with([
    ['certificateForm', 'createCertificate', 'saveCertificate'],
    ['claimForm', 'createClaim', 'saveClaim'],
    ['competencyForm', 'createCompetency', 'saveCompetency'],
    ['transcriptForm', 'createTranscript', 'saveTranscript'],
]);

test('verified normalized records are immutable for their candidate', function (string $modelClass) {
    $record = $modelClass::factory()->create(['status' => VerificationStatus::Verified]);
    $user = $record->candidateProfile->user;

    expect(Gate::forUser($user)->allows('update', $record))->toBeFalse();
    expect(Gate::forUser($user)->allows('delete', $record))->toBeFalse();
})->with([
    CandidateCertificate::class, CandidateAdmissionClaim::class, CandidateExamResult::class, CandidateTranscript::class,
]);

test('candidate cannot edit another candidate normalized record', function (string $method, string $modelClass) {
    $own = CandidateProfile::factory()->create();
    $foreign = $modelClass::factory()->create();
    $this->actingAs($own->user);

    expect(fn () => Livewire::test(AdmissionInformation::class)->call($method, $foreign->id))
        ->toThrow(ModelNotFoundException::class);
})->with([
    ['editCertificate', CandidateCertificate::class],
    ['editClaim', CandidateAdmissionClaim::class],
    ['editCompetency', CandidateExamResult::class],
    ['editTranscript', CandidateTranscript::class],
]);

test('editing rejected normalized records resubmits them as pending', function (string $modelClass, string $edit, string $save) {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $reviewer = User::factory()->create();
    $attributes = [
        'status' => VerificationStatus::Rejected, 'verified_by' => $reviewer->id,
        'verified_at' => now(), 'rejection_reason' => 'Cần chỉnh sửa',
    ];
    if ($modelClass === CandidateExamResult::class) {
        $attributes['exam_type'] = ExamType::Dgnl;
    }
    $record = $modelClass::factory()->for($profile)->create($attributes);
    $this->actingAs($profile->user);
    Livewire::test(AdmissionInformation::class)->call($edit, $record->id)->call($save)->assertHasNoErrors();

    expect($record->refresh()->status)->toBe(VerificationStatus::Pending);
    expect($record->verified_by)->toBeNull();
    expect($record->verified_at)->toBeNull();
    expect($record->rejection_reason)->toBeNull();
})->with([
    [CandidateCertificate::class, 'editCertificate', 'saveCertificate'],
    [CandidateAdmissionClaim::class, 'editClaim', 'saveClaim'],
    [CandidateExamResult::class, 'editCompetency', 'saveCompetency'],
]);

test('rejected transcript resubmission preserves rows and clears review metadata', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $transcript = CandidateTranscript::factory()->for($profile)->create([
        'status' => VerificationStatus::Rejected, 'verified_by' => User::factory(),
        'verified_at' => now(), 'rejection_reason' => 'Cần chỉnh sửa',
    ]);
    CandidateTranscriptScore::factory()->for($transcript, 'transcript')->create();
    $this->actingAs($profile->user);

    Livewire::test(AdmissionInformation::class)->call('editTranscript', $transcript->id)
        ->call('saveTranscript')->assertHasNoErrors();

    expect($transcript->refresh()->status)->toBe(VerificationStatus::Pending);
    expect($transcript->verified_by)->toBeNull();
    expect($transcript->rejection_reason)->toBeNull();
    expect($transcript->scores()->count())->toBe(1);
});
