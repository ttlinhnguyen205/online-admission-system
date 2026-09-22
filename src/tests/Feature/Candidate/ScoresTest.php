<?php

use App\Actions\CandidateFiles;
use App\Enums\ApplicationStatus;
use App\Livewire\Candidate\Scores;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\CandidateScore;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('candidates create edit and delete their own unverified decimal scores', function () {
    Storage::fake(CandidateFiles::DISK);
    $this->travelTo(now()->setDate(2026, 9, 14));
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $page = Livewire::test(Scores::class)->call('create')->set('form', ['score_type' => ' SAT ', 'subject_code' => ' ', 'subject_name' => null, 'score' => '1500.125', 'exam_year' => 2026])
        ->set('evidence', UploadedFile::fake()->createWithContent('sat.png', file_get_contents(base_path('tests/Fixtures/small.png'))))
        ->call('save')->assertHasNoErrors();
    $score = $profile->scores()->sole();
    expect($score->score)->toBe('1500.125');
    expect($score->score_type)->toBe('sat');
    expect($score->verified)->toBeFalse();
    expect($score->verified_by)->toBeNull();
    expect($score->subject_code)->toBeNull();
    expect($score->evidence_path)->toStartWith('candidate-scores/');
    Storage::disk(CandidateFiles::DISK)->assertExists($score->evidence_path);
    $page->call('edit', $score->id)->set('form.score', '1499.001')->call('save')->assertHasNoErrors();
    expect($score->fresh()->score)->toBe('1499.001');
    $page->call('confirmDeletion', $score->id)->call('delete')->assertHasNoErrors();
    $this->assertModelMissing($score);
    Storage::disk(CandidateFiles::DISK)->assertMissing($score->evidence_path);
});

test('scores accept exact database bounds and values above ten', function (string $value, string $expected) {
    $score = CandidateScore::factory()->create();
    $this->actingAs($score->candidateProfile->user);
    Livewire::test(Scores::class)->call('edit', $score->id)->set('form.score', $value)->call('save')->assertHasNoErrors();
    expect($score->fresh()->score)->toBe($expected);
})->with([['0', '0.000'], ['0.001', '0.001'], ['9.100', '9.100'], ['1200', '1200.000'], ['99999.999', '99999.999']]);

test('scores reject invalid or privileged fields without changes', function (string $field, mixed $value, string $error) {
    $this->travelTo(now()->setDate(2026, 9, 14));
    $score = CandidateScore::factory()->create();
    $this->actingAs($score->candidateProfile->user);
    Livewire::test(Scores::class)->call('edit', $score->id)->set('form.'.$field, $value)->call('save')->assertHasErrors($error);
    expect($score->fresh()->score)->toBe('8.250');
    expect($score->fresh()->verified)->toBeFalse();
})->with([
    ['score', '-1', 'form.score'], ['score', '100000', 'form.score'], ['score', '1.0001', 'form.score'], ['score', '1e3', 'form.score'], ['score', '8,25', 'form.score'], ['score', '', 'form.score'],
    ['score_type', '', 'form.score_type'], ['score_type', str_repeat('x', 31), 'form.score_type'],
    ['subject_code', str_repeat('x', 31), 'form.subject_code'], ['subject_name', str_repeat('x', 101), 'form.subject_name'],
    ['exam_year', 2027, 'form.exam_year'], ['exam_year', 1899, 'form.exam_year'], ['exam_year', '2026.5', 'form.exam_year'],
    ['verified', true, 'form'], ['verified_by', 1, 'form'], ['candidate_profile_id', 1, 'form'], ['application_id', 1, 'form'], ['admission_method_id', 1, 'form'], ['id', 1, 'form'], ['unknown', 'x', 'form'],
]);

test('verified scores cannot be edited or deleted even after the editor opens', function (string $action) {
    $score = CandidateScore::factory()->create();
    $this->actingAs($score->candidateProfile->user);
    $page = Livewire::test(Scores::class)->call($action === 'save' ? 'edit' : 'confirmDeletion', $score->id);
    $score->update(['verified' => true]);
    $page->call($action)->assertForbidden();
    expect($score->fresh()->score)->toBe('8.250');
    Livewire::test(Scores::class)->call('edit', $score->id)->assertForbidden();
    Livewire::test(Scores::class)->call('confirmDeletion', $score->id)->assertForbidden();
    $this->assertModelExists($score);
})->with(['save', 'delete']);

test('score listing and action ids are scoped to the candidate', function (string $action) {
    $own = CandidateScore::factory()->create();
    $foreign = CandidateScore::factory()->create(['subject_name' => 'Foreign secret']);
    $this->actingAs($own->candidateProfile->user);
    $page = Livewire::test(Scores::class)->assertDontSee('Foreign secret')->assertViewHas('records', fn ($records) => $records->total() === 1);
    expect(fn () => $page->call($action, $foreign->id))->toThrow(ModelNotFoundException::class);
    $this->assertModelExists($foreign);
})->with(['edit', 'confirmDeletion']);

test('multiple score attempts are allowed without overwriting or application restrictions', function () {
    Storage::fake(CandidateFiles::DISK);
    $score = CandidateScore::factory()->create();
    Application::factory()->for($score->candidateProfile)->create(['status' => ApplicationStatus::Completed]);
    $this->actingAs($score->candidateProfile->user);
    $form = $score->only(['score_type', 'subject_code', 'subject_name', 'score', 'exam_year']);
    Livewire::test(Scores::class)->call('create')->set('form', $form)
        ->set('evidence', UploadedFile::fake()->createWithContent('thpt.png', file_get_contents(base_path('tests/Fixtures/small.png'))))
        ->call('save')->assertHasNoErrors();
    expect($score->candidateProfile->scores()->count())->toBe(2);
    expect($score->fresh()->score)->toBe('8.250');
});

test('score filters pagination and missing profile states work', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Livewire::test(Scores::class)->assertSee('Hãy tạo hồ sơ cá nhân');
    $profile = CandidateProfile::factory()->for($user)->create();
    CandidateScore::factory()->for($profile)->count(16)->create();
    CandidateScore::factory()->for($profile)->create(['score_type' => 'sat', 'exam_year' => 2025]);
    Livewire::test(Scores::class)->assertViewHas('records', fn ($records) => $records->total() === 17 && $records->count() === 15)
        ->call('setPage', 2)->set('typeFilter', 'sat')->assertViewHas('records', fn ($records) => $records->total() === 1)
        ->set('yearFilter', '2026')->assertViewHas('records', fn ($records) => $records->total() === 0);
});

test('score type filter offers only canonical choices and filters each type', function (string $type) {
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    foreach (array_keys(Scores::SCORE_TYPES) as $scoreType) {
        CandidateScore::factory()->for($profile)->create(['score_type' => $scoreType]);
    }

    Livewire::test(Scores::class)
        ->assertSee('wire:model.live="typeFilter"', false)
        ->assertSee('Tất cả loại điểm')
        ->assertSee('value="'.$type.'"', false)
        ->set('typeFilter', $type)
        ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->score_type === $type);
})->with(array_keys(Scores::SCORE_TYPES));

test('year choices are unique newest first and limited to the current candidate', function () {
    $profile = CandidateProfile::factory()->create();
    CandidateScore::factory()->for($profile)->count(2)->create(['exam_year' => 2024]);
    CandidateScore::factory()->for($profile)->create(['exam_year' => 2026]);
    CandidateScore::factory()->for($profile)->create(['exam_year' => 2025]);
    CandidateScore::factory()->create(['exam_year' => 2030, 'subject_name' => 'Foreign secret']);
    $this->actingAs($profile->user);

    Livewire::test(Scores::class)
        ->assertSee('wire:model.live="yearFilter"', false)
        ->assertSee('Tất cả năm')
        ->assertViewHas('availableYears', fn ($years) => $years->all() === [2026, 2025, 2024])
        ->assertDontSee('2030')
        ->assertDontSee('Foreign secret')
        ->set('yearFilter', '2024')
        ->assertViewHas('records', fn ($records) => $records->total() === 2)
        ->set('yearFilter', '2030')
        ->assertViewHas('records', fn ($records) => $records->total() === 0);
});

test('year filter handles a candidate without scores', function () {
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);

    Livewire::test(Scores::class)
        ->assertViewHas('availableYears', fn ($years) => $years->isEmpty())
        ->assertViewHas('records', fn ($records) => $records->total() === 0)
        ->set('yearFilter', '2026')
        ->assertViewHas('records', fn ($records) => $records->total() === 0);
});

test('score type and year filters combine and reset to all scores', function () {
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    CandidateScore::factory()->for($profile)->create(['score_type' => 'thpt', 'exam_year' => 2026]);
    CandidateScore::factory()->for($profile)->create(['score_type' => 'thpt', 'exam_year' => 2025]);
    CandidateScore::factory()->for($profile)->create(['score_type' => 'sat', 'exam_year' => 2026]);

    Livewire::test(Scores::class)
        ->set('typeFilter', 'thpt')->assertViewHas('records', fn ($records) => $records->total() === 2)
        ->set('yearFilter', '2026')->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->score_type === 'thpt')
        ->set('typeFilter', '')->assertViewHas('records', fn ($records) => $records->total() === 2)
        ->set('typeFilter', 'thpt')->set('yearFilter', '')->assertViewHas('records', fn ($records) => $records->total() === 2)
        ->call('resetFilters')->assertSet('typeFilter', '')->assertSet('yearFilter', '')
        ->assertViewHas('records', fn ($records) => $records->total() === 3);
});

test('invalid filter values never break rendering or expose other candidates scores', function (string $property, string $value) {
    $profile = CandidateProfile::factory()->create();
    CandidateScore::factory()->for($profile)->create();
    CandidateScore::factory()->create(['subject_name' => 'Foreign secret']);
    $this->actingAs($profile->user);

    Livewire::test(Scores::class)
        ->set($property, $value)
        ->assertViewHas('records', fn ($records) => $records->total() === 0)
        ->assertDontSee('Foreign secret');
})->with([
    ['typeFilter', 'unsupported'],
    ['yearFilter', 'not-a-year'],
    ['yearFilter', '2026.5'],
    ['yearFilter', '999999999999999999999'],
]);

test('score type selector shows the five supported Vietnamese labels', function () {
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);

    Livewire::test(Scores::class)->call('create')
        ->assertSee('wire:model.live="form.score_type"', false)
        ->assertSee('value="thpt"', false)->assertSee('value="hoc_ba"', false)
        ->assertSee('value="dgnl"', false)->assertSee('value="ielts"', false)->assertSee('value="sat"', false)
        ->assertSee('THPT')->assertSee('Học bạ')->assertSee('ĐGNL')->assertSee('IELTS')->assertSee('SAT');
});

test('new scores require an image and reject unsupported types or missing weighted subjects', function (array $changes, string $error) {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $form = ['score_type' => 'thpt', 'subject_code' => 'MATH', 'subject_name' => null, 'score' => '8.250', 'exam_year' => now()->year];

    $page = Livewire::test(Scores::class)->call('create')->set('form', array_replace($form, $changes));
    if ($error !== 'evidence') {
        $page->set('evidence', UploadedFile::fake()->createWithContent('proof.png', file_get_contents(base_path('tests/Fixtures/small.png'))));
    }
    $page->call('save')->assertHasErrors($error);
    $this->assertDatabaseCount('candidate_scores', 0);
})->with([
    'missing evidence' => [[], 'evidence'],
    'unsupported type' => [['score_type' => 'arbitrary'], 'form.score_type'],
    'THPT subject' => [['subject_code' => null], 'form.subject_code'],
    'Học bạ subject' => [['score_type' => 'hoc_ba', 'subject_code' => null], 'form.subject_code'],
]);

test('new score evidence accepts JPEG and PNG and persists only a generated private path', function (string $fixture, string $extension, string $type) {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    Livewire::test(Scores::class)->call('create')
        ->set('form', ['score_type' => $type, 'subject_code' => null, 'subject_name' => null, 'score' => '7.250', 'exam_year' => now()->year])
        ->set('evidence', UploadedFile::fake()->createWithContent('client-name.'.$extension, file_get_contents(base_path('tests/Fixtures/'.$fixture))))
        ->call('save')->assertHasNoErrors();

    $score = $profile->scores()->sole();
    expect($score->evidence_path)->toStartWith('candidate-scores/'.$profile->id.'/')->not->toContain('client-name');
    Storage::disk(CandidateFiles::DISK)->assertExists($score->evidence_path);
})->with([
    'JPEG' => ['replacement.jpg', 'jpg', 'ielts'],
    'PNG' => ['small.png', 'png', 'dgnl'],
]);

test('score evidence rejects invalid content and files larger than two MiB', function (string $case) {
    Storage::fake(CandidateFiles::DISK);
    $file = $case === 'invalid'
        ? UploadedFile::fake()->createWithContent('fake.png', 'not an image')
        : UploadedFile::fake()->createWithContent('large.png', file_get_contents(base_path('tests/Fixtures/small.png')).str_repeat(' ', 2 * 1024 * 1024));
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    Livewire::test(Scores::class)->call('create')
        ->set('form', ['score_type' => 'sat', 'subject_code' => null, 'subject_name' => null, 'score' => '1200', 'exam_year' => now()->year])
        ->set('evidence', $file)->call('save')->assertHasErrors('evidence');
    $this->assertDatabaseCount('candidate_scores', 0);
})->with([
    'invalid bytes' => ['invalid'],
    'oversized' => ['oversized'],
]);

test('unverified scores retain evidence on ordinary edits and replace it only after saving', function () {
    Storage::fake(CandidateFiles::DISK);
    $score = CandidateScore::factory()->create(['evidence_path' => 'candidate-scores/old.png']);
    Storage::disk(CandidateFiles::DISK)->put($score->evidence_path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $this->actingAs($score->candidateProfile->user);

    $page = Livewire::test(Scores::class)->call('edit', $score->id)->set('form.score', '9.500')->call('save')->assertHasNoErrors();
    expect($score->fresh()->evidence_path)->toBe('candidate-scores/old.png');
    Storage::disk(CandidateFiles::DISK)->assertExists('candidate-scores/old.png');

    $page->call('edit', $score->id)
        ->set('evidence', UploadedFile::fake()->createWithContent('new.jpg', file_get_contents(base_path('tests/Fixtures/replacement.jpg'))))
        ->call('save')->assertHasNoErrors();
    expect($score->fresh()->evidence_path)->not->toBe('candidate-scores/old.png');
    Storage::disk(CandidateFiles::DISK)->assertMissing('candidate-scores/old.png');
    Storage::disk(CandidateFiles::DISK)->assertExists($score->fresh()->evidence_path);
});

test('verified score evidence cannot be replaced or deleted after an editor opens', function (string $action) {
    Storage::fake(CandidateFiles::DISK);
    $score = CandidateScore::factory()->create(['evidence_path' => 'candidate-scores/verified.png']);
    Storage::disk(CandidateFiles::DISK)->put($score->evidence_path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $this->actingAs($score->candidateProfile->user);
    $page = Livewire::test(Scores::class)->call($action === 'save' ? 'edit' : 'confirmDeletion', $score->id);
    $score->update(['verified' => true]);
    if ($action === 'save') {
        $page->set('evidence', UploadedFile::fake()->createWithContent('new.png', file_get_contents(base_path('tests/Fixtures/small.png'))));
    }
    $page->call($action)->assertForbidden();
    expect($score->fresh()->evidence_path)->toBe('candidate-scores/verified.png');
    Storage::disk(CandidateFiles::DISK)->assertExists('candidate-scores/verified.png');
})->with(['save', 'delete']);

test('failed score update preserves old evidence and cleans the new upload', function () {
    Storage::fake(CandidateFiles::DISK);
    $score = CandidateScore::factory()->create(['evidence_path' => 'candidate-scores/old.png']);
    Storage::disk(CandidateFiles::DISK)->put($score->evidence_path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $this->actingAs($score->candidateProfile->user);
    Event::listen('eloquent.saving: '.CandidateScore::class, function (): void {
        throw new RuntimeException('Simulated score persistence failure');
    });
    try {
        expect(fn () => Livewire::test(Scores::class)->call('edit', $score->id)
            ->set('evidence', UploadedFile::fake()->createWithContent('new.png', file_get_contents(base_path('tests/Fixtures/small.png'))))
            ->call('save'))->toThrow(RuntimeException::class);
        expect($score->fresh()->evidence_path)->toBe('candidate-scores/old.png');
        expect(Storage::disk(CandidateFiles::DISK)->allFiles())->toBe(['candidate-scores/old.png']);
    } finally {
        Event::forget('eloquent.saving: '.CandidateScore::class);
    }
});
