<?php

use App\Enums\UserRole;
use App\Livewire\Admin\AdmissionMethods;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\User;
use Livewire\Livewire;

test('admin can create view update and delete admission_methods', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $page = Livewire::test(AdmissionMethods::class)->call('create')->set('form', ['code' => 'METHOD-NEW', 'name' => 'New method', 'description' => null, 'weights' => [], 'is_active' => true])->call('save')->assertHasNoErrors();
    $record = AdmissionMethod::query()->sole();
    $this->assertDatabaseHas('admission_methods', ['id' => $record->id, 'code' => $record->code]);
    $page->call('details', $record->id)->assertSet('readOnly', true)->assertSee($record->name)
        ->call('edit', $record->id)->set('form.name', 'Updated configuration')->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('admission_methods', ['id' => $record->id, 'name' => 'Updated configuration']);
    $page->call('confirmDeletion', $record->id)->call('delete')->assertHasNoErrors();
    $this->assertModelMissing($record);
});

test('admission_methods reject invalid form data without persisting', function (string $field, mixed $value) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Livewire::test(AdmissionMethods::class)->call('create')->set('form', ['code' => 'METHOD-NEW', 'name' => 'New method', 'description' => null, 'weights' => [], 'is_active' => true])
        ->set('form.'.$field, $value)->call('save')->assertHasErrors(in_array($field, ['id', 'score_config']) ? 'form' : 'form.'.$field);
    $this->assertDatabaseCount('admission_methods', 0);
})->with(['missing code' => ['code', ''], 'long code' => ['code', str_repeat('x', 31)], 'missing name' => ['name', ''], 'long name' => ['name', str_repeat('x', 256)],
    'long description' => ['description', str_repeat('x', 10001)], 'invalid active' => ['is_active', 'yes'],
    'raw configuration' => ['score_config', ['formula' => 'execute()']], 'extra attribute' => ['id', 777]]);

test('admission_methods reject duplicate codes on create and update but allow their own code', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionMethod::factory()->create(['code' => 'EXISTING']);
    $other = AdmissionMethod::factory()->create(['code' => 'OTHER']);
    $page = Livewire::test(AdmissionMethods::class)->call('create')->set('form', ['code' => 'METHOD-NEW', 'name' => 'New method', 'description' => null, 'weights' => [], 'is_active' => true])
        ->set('form.code', 'EXISTING')->call('save')->assertHasErrors('form.code');
    $page->call('edit', $other->id)->set('form.code', 'EXISTING')->call('save')->assertHasErrors('form.code');
    $this->assertDatabaseHas('admission_methods', ['id' => $other->id, 'code' => 'OTHER']);
    $page->call('edit', $record->id)->call('save')->assertHasNoErrors();
    $this->assertDatabaseCount('admission_methods', 2);
});

test('admission_methods preserve records when dependencies exist including after confirmation', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionMethod::factory()->create();
    $page = Livewire::test(AdmissionMethods::class)->call('confirmDeletion', $record->id)->assertHasNoErrors();
    AdmissionProgram::factory()->for($record, 'admissionMethod')->create();
    $page->call('delete')->assertHasErrors('deletion')->assertSee('This record is in use by admission records and cannot be deleted.');
    $this->assertModelExists($record);
    $this->assertDatabaseCount('admission_programs', 1);
});

test('admission_methods search pagination and reset expose only matching records', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    AdmissionMethod::factory()->count(16)->create(['name' => 'Ordinary record']);
    $target = AdmissionMethod::factory()->create(['name' => 'Unique needle', 'code' => 'SEARCH-TARGET']);
    Livewire::test(AdmissionMethods::class)->assertViewHas('records', fn ($records) => $records->count() === 15 && $records->total() === 17)
        ->call('setPage', 2)->set('search', 'SEARCH-TARGET')->assertSet('paginators.page', 1)
        ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $target->id)
        ->set('search', 'Unique needle')->assertViewHas('records', fn ($records) => $records->total() === 1)
        ->set('search', 'nothing-matches')->assertSee('No admission methods found')
        ->call('clearFilters')->assertViewHas('records', fn ($records) => $records->total() === 17);
});

test('admission_methods filter active and inactive records', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    AdmissionMethod::factory()->create(['is_active' => true]);
    $inactive = AdmissionMethod::factory()->create(['is_active' => false]);
    Livewire::test(AdmissionMethods::class)->set('statusFilter', 'inactive')
        ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $inactive->id)
        ->set('statusFilter', 'active')->assertViewHas('records', fn ($records) => $records->total() === 1);
});

test('Phase 3 V1 normalizes subject weights without calculating scores', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionMethod::factory()->create();
    Livewire::test(AdmissionMethods::class)->call('edit', $record->id)
        ->set('form.weights', [['subject' => ' math ', 'weight' => '2'], ['subject' => 'eng-1', 'weight' => '0.125']])
        ->call('save')->assertHasNoErrors();
    expect($record->fresh()->score_config)->toBe(['weights' => ['MATH' => 2, 'ENG-1' => 0.125]]);
    $this->assertDatabaseCount('candidate_scores', 0);
    Livewire::test(AdmissionMethods::class)->call('edit', $record->id)->set('form.weights', [])->call('save')->assertHasNoErrors();
    expect($record->fresh()->score_config)->toBeNull();
});

test('subject weights reject malformed or unsafe structures', function (mixed $weights, string $field) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionMethod::factory()->create();
    Livewire::test(AdmissionMethods::class)->call('edit', $record->id)->set('form.weights', $weights)->call('save')->assertHasErrors($field);
    expect($record->fresh()->score_config)->toBeNull();
})->with([
    'null rows' => [null, 'form.weights'],
    'raw JSON' => ['{"MATH":2}', 'form.weights'],
    'map instead of rows' => [['MATH' => 2], 'form.weights'],
    'incomplete row' => [[['subject' => 'MATH']], 'form.weights.0.weight'],
    'scalar row' => [['MATH'], 'form.weights.0'],
    'extra row field' => [[['subject' => 'MATH', 'weight' => 2, 'formula' => 'run()']], 'form.weights.0'],
    'duplicate after normalization' => [[['subject' => ' math ', 'weight' => 1], ['subject' => 'MATH', 'weight' => 2]], 'form.weights.1.subject'],
    'missing subject' => [[['subject' => '', 'weight' => 2]], 'form.weights.0.subject'],
    'non ASCII subject' => [[['subject' => "MATH\u{00e9}", 'weight' => 2]], 'form.weights.0.subject'],
    'long subject' => [[['subject' => str_repeat('X', 31), 'weight' => 2]], 'form.weights.0.subject'],
    'dot path' => [[['subject' => 'MATH.X', 'weight' => 2]], 'form.weights.0.subject'],
    'nested subject' => [[['subject' => ['MATH'], 'weight' => 2]], 'form.weights.0.subject'],
    'zero' => [[['subject' => 'MATH', 'weight' => 0]], 'form.weights.0.weight'],
    'negative' => [[['subject' => 'MATH', 'weight' => -1]], 'form.weights.0.weight'],
    'too large' => [[['subject' => 'MATH', 'weight' => 100.001]], 'form.weights.0.weight'],
    'precision' => [[['subject' => 'MATH', 'weight' => '1.0001']], 'form.weights.0.weight'],
    'expression' => [[['subject' => 'MATH', 'weight' => '1+2']], 'form.weights.0.weight'],
    'infinity' => [[['subject' => 'MATH', 'weight' => 'INF']], 'form.weights.0.weight'],
    'nested weight' => [[['subject' => 'MATH', 'weight' => ['value' => 2]]], 'form.weights.0.weight'],
    'too many rows' => [array_map(fn ($index) => ['subject' => 'SUB_'.$index, 'weight' => 1], range(1, 31)), 'form.weights'],
]);

test('subject editor adds removes and accepts thirty rows with boundary weights', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionMethod::factory()->create();
    $page = Livewire::test(AdmissionMethods::class)->call('edit', $record->id)->call('addWeight')
        ->assertSet('form.weights', [['subject' => '', 'weight' => '1']])->call('removeWeight', 0)->assertSet('form.weights', []);
    $rows = array_map(fn ($index) => ['subject' => 'SUB_'.$index, 'weight' => $index === 1 ? '0.001' : '100'], range(1, 30));
    $page->set('form.weights', $rows)->call('save')->assertHasNoErrors();
    expect($record->fresh()->score_config['weights'])->toHaveCount(30);
    $page->call('edit', $record->id)->call('addWeight')->assertHasErrors('form.weights');
});

test('unsupported preexisting configurations survive unrelated edits and reject replacement', function (mixed $configuration) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionMethod::factory()->create(['score_config' => $configuration]);
    $original = $record->fresh()->getRawOriginal('score_config');
    $page = Livewire::test(AdmissionMethods::class)->call('edit', $record->id)->assertSet('unsupportedConfiguration', true)
        ->set('form.name', 'Renamed method')->call('save')->assertHasNoErrors();
    expect($record->fresh()->getRawOriginal('score_config'))->toBe($original);
    $page->call('edit', $record->id)->set('form.weights', [['subject' => 'MATH', 'weight' => 2]])->call('save')->assertHasErrors('form.weights');
    expect($record->fresh()->getRawOriginal('score_config'))->toBe($original);
})->with([
    'future version' => [['version' => 2, 'formula' => 'MATH*2']],
    'unknown extra key' => [['weights' => ['MATH' => 2], 'scale' => 30]],
    'invalid existing weights' => [['weights' => ['MATH' => -1]]],
    'scalar configuration' => ['legacy'],
]);

test('a newly unsupported configuration is rechecked from storage before saving', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionMethod::factory()->create();
    $page = Livewire::test(AdmissionMethods::class)->call('edit', $record->id);
    $record->update(['score_config' => ['version' => 2]]);
    $page->set('form.name', 'Updated')->call('save')->assertHasNoErrors();
    expect($record->fresh()->score_config)->toBe(['version' => 2]);
});

test('numeric subject codes remain JSON object keys in the V1 contract', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionMethod::factory()->create();
    Livewire::test(AdmissionMethods::class)->call('edit', $record->id)->set('form.weights', [['subject' => '0', 'weight' => 2]])->call('save')->assertHasNoErrors();
    $configuration = json_decode($record->fresh()->getRawOriginal('score_config'));
    expect($configuration->weights)->toBeInstanceOf(stdClass::class);
    expect($configuration->weights->{'0'})->toBe(2);
});

test('staff cannot alter subject editor state through direct actions', function (string $action, array $arguments) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $record = AdmissionMethod::factory()->create(['score_config' => ['weights' => ['MATH' => 2]]]);
    Livewire::test(AdmissionMethods::class)->call('details', $record->id)->call($action, ...$arguments)->assertForbidden();
    expect($record->fresh()->score_config)->toBe(['weights' => ['MATH' => 2]]);
})->with([['addWeight', []], ['removeWeight', [0]]]);
