<?php

use App\Actions\CandidateFiles;
use App\Livewire\Candidate\NativeExamScores;
use App\Models\CandidateExamResult;
use App\Models\CandidateExamSubjectScore;
use App\Models\CandidateProfile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(CandidateFiles::DISK);
    $this->profile = CandidateProfile::factory()->create();
    $this->actingAs($this->profile->user);
});

function thptEvidence(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('thpt.png', file_get_contents(base_path('tests/Fixtures/small.png')));
}

test('candidate selects subjects and saves any combination once per exam year without inventing unselected scores', function () {
    Livewire::test(NativeExamScores::class)->assertSee('Điểm thi tốt nghiệp THPT')->assertDontSee('THPT A00')
        ->call('toggleSubject', 'MATH')->set('scores.MATH', '0')
        ->call('toggleSubject', 'LITERATURE')->set('scores.LITERATURE', '8.25')
        ->call('toggleSubject', 'ENG')->set('scores.ENG', '9.5')
        ->set('year', '2026')->set('evidence', thptEvidence())->call('save')->assertHasNoErrors();

    $exam = $this->profile->examResults()->sole();
    expect($exam->exam_type->value)->toBe('thpt');
    expect($exam->subjectScores()->pluck('subject_code')->sort()->values()->all())->toBe(['ENG', 'LITERATURE', 'MATH']);
    expect($exam->subjectScores()->where('subject_code', 'MATH')->sole()->score)->toBe('0.000');
    expect($exam->getRawOriginal('status'))->toBe('pending');
    Storage::disk(CandidateFiles::DISK)->assertExists($exam->evidence_path);
});

test('candidate can deselect a subject and an empty selection cannot be saved', function () {
    Livewire::test(NativeExamScores::class)->call('toggleSubject', 'MATH')->set('scores.MATH', 8)
        ->call('toggleSubject', 'MATH')->assertSet('scores', [])
        ->set('year', '2026')->set('evidence', thptEvidence())->call('save')->assertHasErrors('scores');
    $this->assertDatabaseCount('candidate_exam_results', 0);
});

test('selected subjects require valid points and unknown subjects are rejected', function (array $scores, string $field) {
    Livewire::test(NativeExamScores::class)->set('year', '2026')->set('scores', $scores)
        ->set('evidence', thptEvidence())->call('save')->assertHasErrors($field);
    $this->assertDatabaseCount('candidate_exam_results', 0);
})->with([
    'blank' => [['MATH' => ''], 'scores.MATH'],
    'negative' => [['MATH' => -1], 'scores.MATH'],
    'over ten' => [['MATH' => 11], 'scores.MATH'],
    'not a number' => [['MATH' => 'invalid'], 'scores.MATH'],
    'too many decimals' => [['MATH' => '8.1234'], 'scores.MATH'],
    'unknown' => [['UNKNOWN' => 8], 'scores'],
]);

test('candidate edits an unverified record and can retain its evidence', function () {
    $page = Livewire::test(NativeExamScores::class)->set('year', '2026')->set('scores', ['MATH' => '8'])
        ->set('evidence', thptEvidence())->call('save')->assertHasNoErrors();
    $exam = $this->profile->examResults()->sole();
    $path = $exam->evidence_path;
    $exam->update(['status' => 'rejected', 'rejection_reason' => 'Ảnh mờ']);

    $page->call('edit', $exam->id)->assertSet('recordId', $exam->id)->assertSet('scores.MATH', '8.000')
        ->set('scores', ['MATH' => '9', 'ENG' => '7.5'])->call('save')->assertHasNoErrors()->assertSet('recordId', null);

    expect($exam->refresh()->evidence_path)->toBe($path);
    expect($exam->getRawOriginal('status'))->toBe('pending');
    expect($exam->rejection_reason)->toBeNull();
    expect($exam->subjectScores()->pluck('subject_code')->sort()->values()->all())->toBe(['ENG', 'MATH']);
    Storage::disk(CandidateFiles::DISK)->assertExists($path);
    $this->assertDatabaseCount('candidate_exam_results', 1);
});

test('replacing THPT evidence removes only the old evidence after a successful save', function () {
    $page = Livewire::test(NativeExamScores::class)->set('year', '2026')->set('scores', ['MATH' => 8])
        ->set('evidence', thptEvidence())->call('save')->assertHasNoErrors();
    $exam = $this->profile->examResults()->sole();
    $oldPath = $exam->evidence_path;

    $page->call('edit', $exam->id)->set('evidence', thptEvidence())->call('save')->assertHasNoErrors();

    Storage::disk(CandidateFiles::DISK)->assertMissing($oldPath);
    Storage::disk(CandidateFiles::DISK)->assertExists($exam->refresh()->evidence_path);
});

test('creating or changing a year cannot duplicate an existing THPT year', function () {
    $page = Livewire::test(NativeExamScores::class)->set('year', '2026')->set('scores', ['ENG' => 8])
        ->set('evidence', thptEvidence())->call('save')->assertHasNoErrors();
    $first = $this->profile->examResults()->sole();
    $page->set('year', '2026')->set('scores', ['MATH' => 9])->set('evidence', thptEvidence())
        ->call('save')->assertHasErrors('year');
    $second = CandidateExamResult::factory()->for($this->profile)->create(['exam_type' => 'thpt', 'exam_year' => 2025]);
    $page->call('edit', $first->id)->set('year', '2025')->call('save')->assertHasErrors('year');
    expect($first->refresh()->exam_year)->toBe(2026);
    expect($second->refresh()->exam_year)->toBe(2025);
});

test('candidate cannot edit another candidates record or a different exam type', function (bool $foreign) {
    $exam = CandidateExamResult::factory()->create([
        'candidate_profile_id' => $foreign ? CandidateProfile::factory()->create()->id : $this->profile->id,
        'exam_type' => $foreign ? 'thpt' : 'dgnl',
    ]);

    Livewire::test(NativeExamScores::class)->call('edit', $exam->id);
})->with([true, false])->throws(ModelNotFoundException::class);

test('verified THPT points cannot be edited even if verification happens while the editor is open', function (bool $afterOpening) {
    $exam = CandidateExamResult::factory()->for($this->profile)->create(['exam_type' => 'thpt', 'status' => 'pending']);
    $exam->subjectScores()->create(['subject_code' => 'MATH', 'subject_name' => 'Toán', 'score' => 8]);
    $page = Livewire::test(NativeExamScores::class);
    if ($afterOpening) {
        $page->call('edit', $exam->id)->set('scores.MATH', 9);
    }
    $exam->update(['status' => 'verified']);

    if ($afterOpening) {
        $page->call('save')->assertForbidden();
    } else {
        $page->call('edit', $exam->id)->assertForbidden();
    }
    expect($exam->subjectScores()->sole()->score)->toBe('8.000');
})->with([true, false]);

test('new THPT records require evidence and cancelling editing resets the form', function () {
    Livewire::test(NativeExamScores::class)->set('year', '2026')->set('scores', ['MATH' => 8])
        ->call('save')->assertHasErrors('evidence')->call('resetEditor')
        ->assertSet('year', '')->assertSet('scores', [])->assertHasNoErrors();
});

test('cancelled exam or subject save rolls back upload and does not report success', function (string $model, string $field) {
    $event = 'eloquent.saving: '.$model;
    Event::listen($event, fn () => false);
    try {
        Livewire::test(NativeExamScores::class)->set('year', '2026')->set('scores', ['MATH' => 8])
            ->set('evidence', thptEvidence())->call('save')->assertHasErrors($field)->assertSet('year', '2026')
            ->assertNotDispatched('toast-show');
        $this->assertDatabaseCount('candidate_exam_results', 0);
        $this->assertDatabaseCount('candidate_exam_subject_scores', 0);
        expect(Storage::disk(CandidateFiles::DISK)->allFiles('candidate-exam-results'))->toBe([]);
    } finally {
        Event::forget($event);
    }
})->with([
    [CandidateExamResult::class, 'evidence'],
    [CandidateExamSubjectScore::class, 'scores'],
]);
