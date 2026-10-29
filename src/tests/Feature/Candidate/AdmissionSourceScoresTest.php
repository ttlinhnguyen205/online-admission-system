<?php

use App\Actions\CandidateFiles;
use App\Livewire\Candidate\AdmissionInformation;
use App\Livewire\Candidate\Scores;
use App\Models\CandidateAdmissionClaim;
use App\Models\CandidateCertificate;
use App\Models\CandidateExamResult;
use App\Models\CandidateProfile;
use App\Models\CandidateTranscript;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('saved admission information appears in scores without copying rows and reflects verification changes', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    Livewire::test(AdmissionInformation::class)->call('createCompetency')->set('competencyForm', [
        'exam_type' => 'dgnl', 'overall_score' => '120', 'exam_year' => 2026,
        'exam_date' => null, 'exam_session' => null, 'registration_number' => null,
    ])->set('competencyEvidence', UploadedFile::fake()->createWithContent('exam.png', file_get_contents(base_path('tests/Fixtures/small.png'))))
        ->call('saveCompetency')->assertHasNoErrors();
    $source = $profile->examResults()->sole();
    $url = route('candidate.admission-information.evidence', ['type' => 'exam-results', 'record' => $source->id]);
    $page = Livewire::test(Scores::class)->assertSee('DGNL')->assertSee('120')->assertSee('Chờ xét duyệt')->assertSee($url, false);
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    $source->update(['status' => 'verified']);
    $page->call('$refresh')->assertSee('Đã xác minh');
    $source->update(['status' => 'rejected', 'rejection_reason' => 'Ảnh cần bổ sung']);
    $page->call('$refresh')->assertSee('Bị từ chối')->assertSee('Ảnh cần bổ sung');
    $this->assertDatabaseCount('candidate_scores', 0);
    $this->assertDatabaseCount('candidate_exam_results', 1);
});

test('scores display all source types including transcript image collections and THPT subject points', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $exam = CandidateExamResult::factory()->for($profile)->create(['exam_type' => 'thpt']);
    $exam->subjectScores()->create(['subject_code' => 'MATH', 'subject_name' => 'Toán kiểm thử', 'score' => 8.75]);
    $transcript = CandidateTranscript::factory()->for($profile)->create(['school_name' => 'Trường kiểm thử']);
    $transcript->scores()->create(['subject_code' => 'ENG', 'subject_name' => 'Anh kiểm thử', 'grade_level' => '12', 'score' => 9.25]);
    $path = 'candidate-transcripts/'.$profile->id.'/image.png';
    Storage::disk(CandidateFiles::DISK)->put($path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $image = $transcript->evidenceImages()->create(['path' => $path, 'original_name' => 'image.png', 'mime_type' => 'image/png', 'size' => 100, 'sort_order' => 0]);
    CandidateCertificate::factory()->for($profile)->create(['score' => 7.5]);
    CandidateAdmissionClaim::factory()->for($profile)->create(['description' => '<script>alert(1)</script>']);
    $url = route('candidate.admission-information.evidence', ['type' => 'transcript-images', 'record' => $image->id]);
    $this->actingAs($profile->user);
    Livewire::test(Scores::class)->assertSee('Toán kiểm thử')->assertSee('8,75')->assertSee('Anh kiểm thử')->assertSee('9,25')
        ->assertSee('IELTS')->assertSee('7,5')->assertSee($url, false)->assertDontSee('<script>alert(1)</script>', false)
        ->assertViewHas('sourceRecords', fn ($records) => $records->total() === 4);
    $this->get($url)->assertOk();
    $this->assertDatabaseCount('candidate_scores', 0);
});

test('source pagination is isolated and never includes another candidates records', function () {
    $profile = CandidateProfile::factory()->create();
    CandidateCertificate::factory()->for($profile)->count(16)->create();
    $foreign = CandidateAdmissionClaim::factory()->create(['description' => 'Foreign secret']);
    $this->actingAs($profile->user);
    Livewire::test(Scores::class)->assertDontSee('Foreign secret')
        ->assertViewHas('sourceRecords', fn ($records) => $records->total() === 16 && $records->count() === 15)
        ->call('setPage', 2, 'sourcePage')
        ->assertViewHas('sourceRecords', fn ($records) => $records->count() === 1)
        ->assertViewHas('records', fn ($records) => $records->currentPage() === 1);
    $this->get(route('candidate.admission-information.evidence', ['type' => 'admission-claims', 'record' => $foreign->id]))->assertNotFound();
});

test('candidate can open historical transcript images but not paths belonging to another profile', function () {
    Storage::fake(CandidateFiles::DISK);
    $transcript = CandidateTranscript::factory()->create();
    $path = 'candidate-transcripts/historical.png';
    Storage::disk(CandidateFiles::DISK)->put($path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $image = $transcript->evidenceImages()->create(['path' => $path, 'original_name' => 'historical.png', 'mime_type' => 'image/png', 'size' => 100, 'sort_order' => 0]);
    $url = route('candidate.admission-information.evidence', ['type' => 'transcript-images', 'record' => $image->id]);
    $this->actingAs($transcript->candidateProfile->user)->get($url)->assertOk();
    $image->update(['path' => 'candidate-transcripts/99999/foreign.png']);
    Storage::disk(CandidateFiles::DISK)->put($image->path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $this->get($url)->assertNotFound();
    Livewire::test(Scores::class)->assertSee('Minh chứng bị thiếu hoặc không đọc được')->assertDontSee($url, false);
});
