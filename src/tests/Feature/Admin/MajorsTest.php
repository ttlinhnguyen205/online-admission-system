<?php

use App\Enums\UserRole;
use App\Livewire\Admin\Majors;
use App\Models\AdmissionProgram;
use App\Models\Major;
use App\Models\User;
use Livewire\Livewire;

test('admin can create view update and delete majors', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $page = Livewire::test(Majors::class)->call('create')->set('form', ['code' => 'MAJOR-NEW', 'name' => 'New major', 'description' => null, 'default_tuition_fee' => null, 'is_active' => true])->call('save')->assertHasNoErrors();
    $record = Major::query()->sole();
    $this->assertDatabaseHas('majors', ['id' => $record->id, 'code' => $record->code]);
    $page->call('details', $record->id)->assertSet('readOnly', true)->assertSee($record->name)
        ->call('edit', $record->id)->set('form.name', 'Updated configuration')->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('majors', ['id' => $record->id, 'name' => 'Updated configuration']);
    $page->call('confirmDeletion', $record->id)->call('delete')->assertHasNoErrors();
    $this->assertModelMissing($record);
});

test('majors reject invalid form data without persisting', function (string $field, mixed $value) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Livewire::test(Majors::class)->call('create')->set('form', ['code' => 'MAJOR-NEW', 'name' => 'New major', 'description' => null, 'default_tuition_fee' => null, 'is_active' => true])
        ->set('form.'.$field, $value)->call('save')->assertHasErrors(in_array($field, ['id', 'score_config']) ? 'form' : 'form.'.$field);
    $this->assertDatabaseCount('majors', 0);
})->with(['missing code' => ['code', ''], 'long code' => ['code', str_repeat('x', 31)], 'missing name' => ['name', ''], 'long name' => ['name', str_repeat('x', 256)],
    'long description' => ['description', str_repeat('x', 10001)], 'negative tuition' => ['default_tuition_fee', -1],
    'oversized tuition' => ['default_tuition_fee', '10000000000000'], 'excess precision' => ['default_tuition_fee', '1.001'],
    'invalid tuition' => ['default_tuition_fee', 'formula()'], 'invalid active' => ['is_active', 'yes'], 'extra attribute' => ['id', 777]]);

test('majors reject duplicate codes on create and update but allow their own code', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = Major::factory()->create(['code' => 'EXISTING']);
    $other = Major::factory()->create(['code' => 'OTHER']);
    $page = Livewire::test(Majors::class)->call('create')->set('form', ['code' => 'MAJOR-NEW', 'name' => 'New major', 'description' => null, 'default_tuition_fee' => null, 'is_active' => true])
        ->set('form.code', 'EXISTING')->call('save')->assertHasErrors('form.code');
    $page->call('edit', $other->id)->set('form.code', 'EXISTING')->call('save')->assertHasErrors('form.code');
    $this->assertDatabaseHas('majors', ['id' => $other->id, 'code' => 'OTHER']);
    $page->call('edit', $record->id)->call('save')->assertHasNoErrors();
    $this->assertDatabaseCount('majors', 2);
});

test('majors preserve records when dependencies exist including after confirmation', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = Major::factory()->create();
    $page = Livewire::test(Majors::class)->call('confirmDeletion', $record->id)->assertHasNoErrors();
    AdmissionProgram::factory()->for($record, 'major')->create();
    $page->call('delete')->assertHasErrors('deletion')->assertSee('This record is in use by admission records and cannot be deleted.');
    $this->assertModelExists($record);
    $this->assertDatabaseCount('admission_programs', 1);
});

test('majors search pagination and reset expose only matching records', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Major::factory()->count(16)->create(['name' => 'Ordinary record']);
    $target = Major::factory()->create(['name' => 'Unique needle', 'code' => 'SEARCH-TARGET']);
    Livewire::test(Majors::class)->assertViewHas('records', fn ($records) => $records->count() === 15 && $records->total() === 17)
        ->call('setPage', 2)->set('search', 'SEARCH-TARGET')->assertSet('paginators.page', 1)
        ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $target->id)
        ->set('search', 'Unique needle')->assertViewHas('records', fn ($records) => $records->total() === 1)
        ->set('search', 'nothing-matches')->assertSee('No majors found')
        ->call('clearFilters')->assertViewHas('records', fn ($records) => $records->total() === 17);
});

test('majors filter active and inactive records', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Major::factory()->create(['is_active' => true]);
    $inactive = Major::factory()->create(['is_active' => false]);
    Livewire::test(Majors::class)->set('statusFilter', 'inactive')
        ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $inactive->id)
        ->set('statusFilter', 'active')->assertViewHas('records', fn ($records) => $records->total() === 1);
});

test('major tuition accepts zero and the column maximum', function (string $fee) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = Major::factory()->create();
    Livewire::test(Majors::class)->call('edit', $record->id)->set('form.default_tuition_fee', $fee)->set('form.is_active', false)->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('majors', ['id' => $record->id, 'default_tuition_fee' => $fee]);
    $this->assertDatabaseHas('majors', ['id' => $record->id, 'is_active' => false]);
})->with(['0.00', '9999999999999.99']);
