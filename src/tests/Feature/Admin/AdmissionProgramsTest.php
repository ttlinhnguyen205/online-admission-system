<?php

use App\Enums\UserRole;
use App\Livewire\Admin\AdmissionPrograms;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Major;
use App\Models\User;
use Livewire\Livewire;

test('admin can create view update and delete programs with zero quota', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $round = AdmissionRound::factory()->create();
    $major = Major::factory()->create(['is_active' => false]);
    $method = AdmissionMethod::factory()->create(['is_active' => false]);
    $page = Livewire::test(AdmissionPrograms::class)->call('create')
        ->set('form.admission_round_id', $round->id)->set('form.major_id', $major->id)->set('form.admission_method_id', $method->id)
        ->call('save')->assertHasNoErrors();
    $record = AdmissionProgram::query()->sole();
    $this->assertDatabaseHas('admission_programs', ['id' => $record->id, 'quota' => 0, 'status' => 'active', 'major_id' => $major->id]);
    $page->call('details', $record->id)->assertSet('readOnly', true)->assertSee($major->name)
        ->call('edit', $record->id)->set('form.quota', 50)->set('form.status', 'inactive')->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('admission_programs', ['id' => $record->id, 'quota' => 50, 'status' => 'inactive']);
    $page->call('confirmDeletion', $record->id)->call('delete')->assertHasNoErrors();
    $this->assertModelMissing($record);
});

test('programs reject invalid values without changing storage', function (string $field, mixed $value) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionProgram::factory()->create();
    $original = $record->fresh()->getAttributes();
    Livewire::test(AdmissionPrograms::class)->call('edit', $record->id)->set('form.'.$field, $value)->call('save')
        ->assertHasErrors($field === 'id' ? 'form' : 'form.'.$field);
    expect($record->fresh()->getAttributes())->toBe($original);
})->with([
    'round missing' => ['admission_round_id', 999999], 'major missing' => ['major_id', 999999], 'method missing' => ['admission_method_id', 999999],
    'round required' => ['admission_round_id', null], 'major required' => ['major_id', null], 'method required' => ['admission_method_id', null],
    'array foreign key' => ['major_id', [1]], 'negative quota' => ['quota', -1], 'fractional quota' => ['quota', 0.5],
    'quota overflow' => ['quota', '4294967296'], 'quota required' => ['quota', null],
    'minimum negative' => ['minimum_score', -1], 'minimum overflow' => ['minimum_score', '100000'],
    'minimum precision' => ['minimum_score', '1.0001'], 'minimum expression' => ['minimum_score', '1+2'],
    'cutoff negative' => ['previous_cutoff_score', -1], 'cutoff overflow' => ['previous_cutoff_score', '100000'],
    'cutoff precision' => ['previous_cutoff_score', '1.0001'], 'cutoff expression' => ['previous_cutoff_score', '1+2'],
    'fee negative' => ['tuition_fee', -1], 'fee overflow' => ['tuition_fee', '10000000000000'], 'fee precision' => ['tuition_fee', '1.001'],
    'invalid status' => ['status', 'published'], 'missing status' => ['status', null], 'extra field' => ['id', 999999],
]);

test('programs accept numeric column boundaries and nullable scores and fees', function (array $values) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionProgram::factory()->create();
    $page = Livewire::test(AdmissionPrograms::class)->call('edit', $record->id);
    foreach ($values as $field => $value) {
        $page->set('form.'.$field, $value);
    }
    $page->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('admission_programs', ['id' => $record->id, ...$values]);
})->with([
    'upper bounds' => [['quota' => 4294967295, 'minimum_score' => '99999.999', 'previous_cutoff_score' => '99999.999', 'tuition_fee' => '9999999999999.99']],
    'zeroes' => [['quota' => 0, 'minimum_score' => '0.000', 'previous_cutoff_score' => '0.000', 'tuition_fee' => '0.00']],
    'unspecified' => [['minimum_score' => null, 'previous_cutoff_score' => null, 'tuition_fee' => null]],
]);

test('duplicate program combinations fail cleanly on create and update', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionProgram::factory()->create();
    $other = AdmissionProgram::factory()->create();
    $page = Livewire::test(AdmissionPrograms::class)->call('create')
        ->set('form.admission_round_id', $record->admission_round_id)->set('form.major_id', $record->major_id)
        ->set('form.admission_method_id', $record->admission_method_id)->call('save')
        ->assertHasErrors('form.admission_method_id')->assertSee('A program already exists for this round, major and admission method.');
    $page->call('edit', $other->id)->set('form.admission_round_id', $record->admission_round_id)
        ->set('form.major_id', $record->major_id)->set('form.admission_method_id', $record->admission_method_id)
        ->call('save')->assertHasErrors('form.admission_method_id');
    $this->assertDatabaseHas('admission_programs', ['id' => $other->id, 'major_id' => $other->major_id]);
    $page->call('edit', $record->id)->call('save')->assertHasNoErrors();
    $this->assertDatabaseCount('admission_programs', 2);
});

test('program relationships cannot be reassigned after wishes exist even from a previously unlocked form', function (string $field, string $model) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionProgram::factory()->create();
    $page = Livewire::test(AdmissionPrograms::class)->call('edit', $record->id)->assertSet('relationshipsLocked', false);
    $wish = AdmissionWish::factory()->for($record, 'admissionProgram')->create();
    $replacement = $model::factory()->create();
    $page->set('form.'.$field, $replacement->id)->call('save')->assertHasErrors('form.'.$field)
        ->assertSee('The round, major and method cannot change once this program has wishes.');
    $this->assertDatabaseHas('admission_programs', ['id' => $record->id, $field => $record->getAttribute($field)]);
    $this->assertModelExists($wish);
    $page->call('edit', $record->id)->assertSet('relationshipsLocked', true)->set('form.quota', 0)->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('admission_programs', ['id' => $record->id, 'quota' => 0]);
})->with([
    ['admission_round_id', AdmissionRound::class], ['major_id', Major::class], ['admission_method_id', AdmissionMethod::class],
]);

test('unused programs may be reassigned to another valid combination', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionProgram::factory()->create();
    $major = Major::factory()->create();
    Livewire::test(AdmissionPrograms::class)->call('edit', $record->id)->set('form.major_id', $major->id)->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('admission_programs', ['id' => $record->id, 'major_id' => $major->id]);
});

test('program deletion preserves its wishes', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionProgram::factory()->create();
    $page = Livewire::test(AdmissionPrograms::class)->call('confirmDeletion', $record->id);
    $wish = AdmissionWish::factory()->for($record, 'admissionProgram')->create();
    $page->call('delete')->assertHasErrors('deletion');
    $this->assertModelExists($record);
    $this->assertModelExists($wish);
});

test('program search filters and pagination combine and reset correctly', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    AdmissionProgram::factory()->count(16)->create();
    $target = AdmissionProgram::factory()->create(['status' => 'inactive']);
    $target->major->update(['name' => 'Unique engineering']);
    $page = Livewire::test(AdmissionPrograms::class)->assertViewHas('records', fn ($records) => $records->count() === 15 && $records->total() === 17)
        ->call('setPage', 2)->set('search', 'Unique engineering')->assertSet('paginators.page', 1)
        ->set('roundFilter', (string) $target->admission_round_id)->set('majorFilter', (string) $target->major_id)
        ->set('methodFilter', (string) $target->admission_method_id)->set('statusFilter', 'inactive')
        ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $target->id);
    $page->set('statusFilter', 'active')->assertSee('No admission programs found')
        ->call('clearFilters')->assertViewHas('records', fn ($records) => $records->total() === 17);
});

test('each program filter independently selects matching offerings', function (string $filter, string $attribute) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    AdmissionProgram::factory()->create(['status' => 'active']);
    $target = AdmissionProgram::factory()->create(['status' => 'inactive']);
    Livewire::test(AdmissionPrograms::class)->set($filter, (string) $target->getAttribute($attribute))
        ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $target->id);
})->with([['roundFilter', 'admission_round_id'], ['majorFilter', 'major_id'], ['methodFilter', 'admission_method_id'], ['statusFilter', 'status']]);

test('program search matches each related catalog', function (string $relationship) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    AdmissionProgram::factory()->create();
    $target = AdmissionProgram::factory()->create();
    $target->$relationship->update(['code' => 'FIND-THIS-CATALOG']);
    Livewire::test(AdmissionPrograms::class)->set('search', 'FIND-THIS-CATALOG')
        ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $target->id);
})->with(['admissionRound', 'major', 'admissionMethod']);
