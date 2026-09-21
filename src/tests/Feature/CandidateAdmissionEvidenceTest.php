<?php

use App\Actions\CandidateFiles;
use App\Livewire\Candidate\AdmissionInformation;
use App\Models\CandidateAdmissionClaim;
use App\Models\CandidateCertificate;
use App\Models\CandidateExamResult;
use App\Models\CandidateProfile;
use App\Models\CandidateTranscript;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('candidate privately views evidence for each normalized domain', function (string $modelClass, string $type, string $directory) {
    Storage::fake(CandidateFiles::DISK);
    $record = $modelClass::factory()->create(['evidence_path' => $directory.'/proof.png']);
    $bytes = file_get_contents(base_path('tests/Fixtures/small.png'));
    Storage::disk(CandidateFiles::DISK)->put($record->evidence_path, $bytes);

    $response = $this->actingAs($record->candidateProfile->user)
        ->get(route('candidate.admission-information.evidence', ['type' => $type, 'record' => $record->id]))
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    expect($response->streamedContent())->toBe($bytes);
})->with([
    [CandidateExamResult::class, 'exam-results', 'candidate-exam-results/1'],
    [CandidateTranscript::class, 'transcripts', 'candidate-transcripts/1'],
    [CandidateCertificate::class, 'certificates', 'candidate-certificates/1'],
    [CandidateAdmissionClaim::class, 'admission-claims', 'candidate-admission-claims/1'],
]);

test('foreign candidates cannot view normalized admission evidence', function (string $modelClass, string $type, string $directory) {
    Storage::fake(CandidateFiles::DISK);
    $record = $modelClass::factory()->create(['evidence_path' => $directory.'/proof.png']);
    Storage::disk(CandidateFiles::DISK)->put($record->evidence_path, file_get_contents(base_path('tests/Fixtures/small.png')));

    $this->actingAs(CandidateProfile::factory()->create()->user)
        ->get(route('candidate.admission-information.evidence', ['type' => $type, 'record' => $record->id]))
        ->assertNotFound();
})->with([
    [CandidateExamResult::class, 'exam-results', 'candidate-exam-results/1'],
    [CandidateTranscript::class, 'transcripts', 'candidate-transcripts/1'],
    [CandidateCertificate::class, 'certificates', 'candidate-certificates/1'],
    [CandidateAdmissionClaim::class, 'admission-claims', 'candidate-admission-claims/1'],
]);

test('normalized evidence requires authentication and rejects mismatched or unsafe paths', function () {
    Storage::fake(CandidateFiles::DISK);
    $certificate = CandidateCertificate::factory()->create(['evidence_path' => 'candidate-certificates/1/proof.png']);
    Storage::disk(CandidateFiles::DISK)->put($certificate->evidence_path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $url = route('candidate.admission-information.evidence', ['type' => 'certificates', 'record' => $certificate->id]);

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs($certificate->candidateProfile->user);
    $certificate->update(['evidence_path' => 'candidate-exam-results/1/proof.png']);
    $this->get($url)->assertNotFound();
    $certificate->update(['evidence_path' => 'candidate-certificates/../secret.png']);
    $this->get($url)->assertNotFound();
});

test('normalized evidence paths are not rendered as public URLs', function () {
    $certificate = CandidateCertificate::factory()->create([
        'evidence_path' => 'candidate-certificates/secret/internal-proof.png',
    ]);

    $this->actingAs($certificate->candidateProfile->user);
    Livewire::test(AdmissionInformation::class)
        ->assertDontSee('candidate-certificates/secret/internal-proof.png')
        ->assertSee(route('candidate.admission-information.evidence', [
            'type' => 'certificates', 'record' => $certificate->id,
        ]));
});

test('normalized uploads reject invalid image bytes and files over two MiB', function (string $case) {
    Storage::fake(CandidateFiles::DISK);
    $file = $case === 'invalid'
        ? UploadedFile::fake()->createWithContent('fake.png', 'not an image')
        : UploadedFile::fake()->createWithContent(
            'large.png',
            file_get_contents(base_path('tests/Fixtures/small.png')).str_repeat(' ', 2 * 1024 * 1024),
        );
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);

    Livewire::test(AdmissionInformation::class)->call('createCertificate')
        ->set('certificateForm', [
            'certificate_type' => 'ielts', 'score' => '6.500', 'certificate_number' => null,
            'issued_at' => null, 'expires_at' => null,
        ])->set('certificateEvidence', $file)->call('saveCertificate')->assertHasErrors();
    $this->assertDatabaseCount('candidate_certificates', 0);
})->with(['invalid', 'oversized']);

test('failed normalized evidence replacement keeps the old file and cleans the new file', function () {
    Storage::fake(CandidateFiles::DISK);
    $certificate = CandidateCertificate::factory()->create([
        'evidence_path' => 'candidate-certificates/1/original.png',
    ]);
    Storage::disk(CandidateFiles::DISK)->put($certificate->evidence_path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $this->actingAs($certificate->candidateProfile->user);
    Event::listen('eloquent.saving: '.CandidateCertificate::class, function (): void {
        throw new RuntimeException('Simulated certificate persistence failure');
    });

    try {
        expect(fn () => Livewire::test(AdmissionInformation::class)->call('editCertificate', $certificate->id)
            ->set('certificateEvidence', UploadedFile::fake()->createWithContent(
                'replacement.png',
                file_get_contents(base_path('tests/Fixtures/small.png')),
            ))->call('saveCertificate'))->toThrow(RuntimeException::class);
        expect($certificate->fresh()->evidence_path)->toBe('candidate-certificates/1/original.png');
        expect(Storage::disk(CandidateFiles::DISK)->allFiles())->toBe(['candidate-certificates/1/original.png']);
    } finally {
        Event::forget('eloquent.saving: '.CandidateCertificate::class);
    }
});
