<?php

use App\Enums\ApplicationStatus;
use App\Livewire\Candidate\Scores;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\CandidateScore;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('candidates create edit and delete their own unverified decimal scores', function () {
    $this->travelTo(now()->setDate(2026, 9, 14));
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $page = Livewire::test(Scores::class)->call('create')->set('form', ['score_type' => ' SAT ', 'subject_code' => ' ', 'subject_name' => null, 'score' => '1500.125', 'exam_year' => 2026])->call('save')->assertHasNoErrors();
    $score = $profile->scores()->sole();
    expect($score->score)->toBe('1500.125');
    expect($score->score_type)->toBe('sat');
    expect($score->verified)->toBeFalse();
    expect($score->verified_by)->toBeNull();
    expect($score->subject_code)->toBeNull();
    $page->call('edit', $score->id)->set('form.score', '1499.001')->call('save')->assertHasNoErrors();
    expect($score->fresh()->score)->toBe('1499.001');
    $page->call('confirmDeletion', $score->id)->call('delete')->assertHasNoErrors();
    $this->assertModelMissing($score);
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
    $score = CandidateScore::factory()->create();
    Application::factory()->for($score->candidateProfile)->create(['status' => ApplicationStatus::Completed]);
    $this->actingAs($score->candidateProfile->user);
    $form = $score->only(['score_type', 'subject_code', 'subject_name', 'score', 'exam_year']);
    Livewire::test(Scores::class)->call('create')->set('form', $form)->call('save')->assertHasNoErrors();
    expect($score->candidateProfile->scores()->count())->toBe(2);
    expect($score->fresh()->score)->toBe('8.250');
});

test('score filters pagination and missing profile states work', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Livewire::test(Scores::class)->assertSee('Hãy tạo hồ sơ thí sinh');
    $profile = CandidateProfile::factory()->for($user)->create();
    CandidateScore::factory()->for($profile)->count(16)->create();
    CandidateScore::factory()->for($profile)->create(['score_type' => 'sat', 'exam_year' => 2025]);
    Livewire::test(Scores::class)->assertViewHas('records', fn ($records) => $records->total() === 17 && $records->count() === 15)
        ->call('setPage', 2)->set('typeFilter', 'sat')->assertViewHas('records', fn ($records) => $records->total() === 1)
        ->set('yearFilter', '2026')->assertViewHas('records', fn ($records) => $records->total() === 0);
});
