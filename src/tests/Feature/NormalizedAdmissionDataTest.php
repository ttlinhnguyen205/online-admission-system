<?php

use App\Enums\CertificateType;
use App\Enums\ExamType;
use App\Enums\VerificationStatus;
use App\Models\CandidateAdmissionClaim;
use App\Models\CandidateCertificate;
use App\Models\CandidateExamResult;
use App\Models\CandidateExamSubjectScore;
use App\Models\CandidateProfile;
use App\Models\CandidateScore;
use App\Models\CandidateTranscript;
use App\Models\CandidateTranscriptScore;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('normalized admission data tables contain the required columns', function () {
    $columns = [
        'candidate_exam_results' => ['id', 'candidate_profile_id', 'exam_type', 'exam_year', 'exam_date', 'exam_session',
            'registration_number', 'overall_score', 'evidence_path', 'status', 'verified_by', 'verified_at',
            'rejection_reason', 'created_at', 'updated_at'],
        'candidate_exam_subject_scores' => ['id', 'candidate_exam_result_id', 'subject_code', 'subject_name', 'score', 'created_at', 'updated_at'],
        'candidate_transcripts' => ['id', 'candidate_profile_id', 'school_name', 'graduation_year', 'evidence_path', 'status',
            'verified_by', 'verified_at', 'rejection_reason', 'created_at', 'updated_at'],
        'candidate_transcript_scores' => ['id', 'candidate_transcript_id', 'subject_code', 'subject_name', 'grade_level',
            'score', 'created_at', 'updated_at'],
        'candidate_certificates' => ['id', 'candidate_profile_id', 'certificate_type', 'score', 'certificate_number',
            'issued_at', 'expires_at', 'evidence_path', 'status', 'verified_by', 'verified_at', 'rejection_reason',
            'created_at', 'updated_at'],
        'candidate_admission_claims' => ['id', 'candidate_profile_id', 'claim_type', 'claim_code', 'description',
            'evidence_path', 'status', 'verified_by', 'verified_at', 'rejection_reason', 'created_at', 'updated_at'],
    ];

    foreach ($columns as $table => $expected) {
        expect(Schema::hasTable($table))->toBeTrue();
        expect(Schema::getColumnListing($table))->toEqualCanonicalizing($expected);
    }
});

test('candidate profile exposes each normalized admission data relationship', function () {
    $profile = CandidateProfile::factory()->create();
    $exam = CandidateExamResult::factory()->for($profile)->create();
    $transcript = CandidateTranscript::factory()->for($profile)->create();
    $certificate = CandidateCertificate::factory()->for($profile)->create();
    $claim = CandidateAdmissionClaim::factory()->for($profile)->create();

    expect($profile->examResults()->sole()->is($exam))->toBeTrue();
    expect($profile->transcripts()->sole()->is($transcript))->toBeTrue();
    expect($profile->certificates()->sole()->is($certificate))->toBeTrue();
    expect($profile->admissionClaims()->sole()->is($claim))->toBeTrue();
});

test('exam and transcript child relationships retain decimal precision', function () {
    $exam = CandidateExamResult::factory()->create(['overall_score' => '987.654']);
    $examScore = CandidateExamSubjectScore::factory()->for($exam, 'examResult')->create(['score' => '8.125']);
    $transcript = CandidateTranscript::factory()->create();
    $transcriptScore = CandidateTranscriptScore::factory()->for($transcript, 'transcript')->create(['score' => '9.375', 'grade_level' => 12]);

    expect($exam->refresh()->overall_score)->toBe('987.654');
    expect($exam->subjectScores()->sole()->is($examScore))->toBeTrue();
    expect($examScore->refresh()->score)->toBe('8.125');
    expect($examScore->examResult->is($exam))->toBeTrue();
    expect($transcript->scores()->sole()->is($transcriptScore))->toBeTrue();
    expect($transcriptScore->refresh()->score)->toBe('9.375');
    expect($transcriptScore->grade_level)->toBe(12);
    expect($transcriptScore->transcript->is($transcript))->toBeTrue();
});

test('subject scores are unique within their parent and key', function () {
    $exam = CandidateExamResult::factory()->create();
    CandidateExamSubjectScore::factory()->for($exam, 'examResult')->create(['subject_code' => 'MATH']);
    expect(fn () => CandidateExamSubjectScore::factory()->for($exam, 'examResult')->create(['subject_code' => 'MATH']))
        ->toThrow(QueryException::class);

    $transcript = CandidateTranscript::factory()->create();
    CandidateTranscriptScore::factory()->for($transcript, 'transcript')->create(['subject_code' => 'MATH', 'grade_level' => 10]);
    expect(fn () => CandidateTranscriptScore::factory()->for($transcript, 'transcript')->create(['subject_code' => 'MATH', 'grade_level' => 10]))
        ->toThrow(QueryException::class);
    CandidateTranscriptScore::factory()->for($transcript, 'transcript')->create(['subject_code' => 'MATH', 'grade_level' => 11]);
});

test('transcript grade levels reject unsupported values', function () {
    $transcript = CandidateTranscript::factory()->create();

    expect(fn () => DB::table('candidate_transcript_scores')->insert([
        'candidate_transcript_id' => $transcript->id, 'subject_code' => 'MATH', 'subject_name' => 'Toán',
        'grade_level' => 9, 'score' => 8.25,
    ]))->toThrow(QueryException::class);
});

test('normalized verification statuses reject unsupported values', function (string $modelClass) {
    expect(fn () => $modelClass::factory()->create(['status' => 'unsupported']))->toThrow(ValueError::class);
})->with([
    CandidateExamResult::class, CandidateTranscript::class, CandidateCertificate::class, CandidateAdmissionClaim::class,
]);

test('database constraints reject unsupported normalized types and statuses', function (string $table, array $attributes) {
    $profile = CandidateProfile::factory()->create();

    expect(fn () => DB::table($table)->insert([
        'candidate_profile_id' => $profile->id,
        ...$attributes,
    ]))->toThrow(QueryException::class);
})->with([
    'exam type' => ['candidate_exam_results', ['exam_type' => 'unsupported', 'exam_year' => 2026, 'status' => 'pending']],
    'certificate type' => ['candidate_certificates', ['certificate_type' => 'unsupported', 'score' => 1, 'status' => 'pending']],
    'exam status' => ['candidate_exam_results', ['exam_type' => 'thpt', 'exam_year' => 2026, 'status' => 'unsupported']],
    'transcript status' => ['candidate_transcripts', ['graduation_year' => 2026, 'status' => 'unsupported']],
    'certificate status' => ['candidate_certificates', ['certificate_type' => 'ielts', 'score' => 1, 'status' => 'unsupported']],
    'claim status' => ['candidate_admission_claims', ['claim_type' => 'test_claim', 'status' => 'unsupported']],
]);

test('normalized lookup and uniqueness indexes exist', function () {
    $indexes = fn (string $table): array => collect(Schema::getIndexes($table))->pluck('name')->all();

    expect($indexes('candidate_exam_results'))->toContain('candidate_exam_results_lookup_index', 'candidate_exam_results_status_index', 'candidate_exam_results_verified_by_index');
    expect($indexes('candidate_exam_subject_scores'))->toContain('candidate_exam_subject_unique');
    expect($indexes('candidate_transcripts'))->toContain('candidate_transcripts_lookup_index', 'candidate_transcripts_status_index', 'candidate_transcripts_verified_by_index');
    expect($indexes('candidate_transcript_scores'))->toContain('candidate_transcript_subject_grade_unique');
    expect($indexes('candidate_certificates'))->toContain('candidate_certificates_lookup_index', 'candidate_certificates_status_index', 'candidate_certificates_verified_by_index');
    expect($indexes('candidate_admission_claims'))->toContain('candidate_admission_claims_lookup_index', 'candidate_admission_claims_status_index', 'candidate_admission_claims_verified_by_index');
});

test('reviewer deletion sets normalized verification references to null', function (string $modelClass) {
    $reviewer = User::factory()->create();
    $record = $modelClass::factory()->for($reviewer, 'verifier')->create([
        'status' => VerificationStatus::Verified, 'verified_at' => now(),
    ]);

    $reviewer->delete();

    expect($record->refresh()->verified_by)->toBeNull();
    expect($record->verifier)->toBeNull();
})->with([
    CandidateExamResult::class, CandidateTranscript::class, CandidateCertificate::class, CandidateAdmissionClaim::class,
]);

test('candidate ownership foreign keys restrict profile deletion', function (string $modelClass) {
    $profile = CandidateProfile::factory()->create();
    $modelClass::factory()->for($profile)->create();

    expect(fn () => $profile->delete())->toThrow(QueryException::class);
})->with([
    CandidateExamResult::class, CandidateTranscript::class, CandidateCertificate::class, CandidateAdmissionClaim::class,
]);

test('deleting an exam or transcript cascades to its score rows', function () {
    $exam = CandidateExamResult::factory()->create();
    CandidateExamSubjectScore::factory()->for($exam, 'examResult')->create();
    $transcript = CandidateTranscript::factory()->create();
    CandidateTranscriptScore::factory()->for($transcript, 'transcript')->create();

    $exam->delete();
    $transcript->delete();

    expect(CandidateExamSubjectScore::query()->count())->toBe(0);
    expect(CandidateTranscriptScore::query()->count())->toBe(0);
});

test('canonical exam certificate and subject definitions preserve phase seven codes', function () {
    expect(array_column(ExamType::cases(), 'value'))->toBe(['thpt', 'dgnl', 'dgtd', 'vsat', 'spt']);
    expect(array_column(CertificateType::cases(), 'value'))->toBe(['ielts', 'sat']);
    expect(array_column(VerificationStatus::cases(), 'value'))->toBe(['pending', 'verified', 'rejected']);
    expect(array_keys(config('admission_data.exam_types')))->toBe(['thpt', 'dgnl', 'dgtd', 'vsat', 'spt']);
    expect(array_keys(config('admission_data.certificate_types')))->toBe(['ielts', 'sat']);
    expect(array_keys(config('admission_data.subjects')))->toBe(['MATH', 'LITERATURE', 'ENG', 'PHYSICS', 'CHEMISTRY']);
    expect(config('admission_data.certificate_types.ielts.score.max'))->toBeNull();
    expect(config('admission_data.certificate_types.sat.score.max'))->toBeNull();
});

test('certificate dates types and decimal scores are cast without shared range assumptions', function () {
    $certificate = CandidateCertificate::factory()->create([
        'certificate_type' => CertificateType::Sat, 'score' => '1500.125', 'issued_at' => '2026-01-15',
        'expires_at' => '2031-01-15', 'status' => VerificationStatus::Rejected,
    ])->refresh();

    expect($certificate->certificate_type)->toBe(CertificateType::Sat);
    expect($certificate->score)->toBe('1500.125');
    expect($certificate->issued_at->toDateString())->toBe('2026-01-15');
    expect($certificate->expires_at->toDateString())->toBe('2031-01-15');
    expect($certificate->status)->toBe(VerificationStatus::Rejected);
});

test('legacy candidate scores remain independent and functional', function () {
    $score = CandidateScore::factory()->create([
        'score_type' => 'thpt', 'subject_code' => 'MATH', 'score' => '8.125',
        'exam_year' => 2026, 'verified' => true,
    ])->refresh();

    expect(Schema::hasColumns('candidate_scores', [
        'candidate_profile_id', 'score_type', 'subject_code', 'subject_name', 'score',
        'exam_year', 'evidence_path', 'verified', 'verified_by',
    ]))->toBeTrue();
    expect($score->score)->toBe('8.125');
    expect($score->verified)->toBeTrue();
    expect($score->candidateProfile)->toBeInstanceOf(CandidateProfile::class);
});
