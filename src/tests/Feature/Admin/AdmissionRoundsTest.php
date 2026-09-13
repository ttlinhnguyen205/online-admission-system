<?php

use App\Enums\AdmissionRoundStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\AdmissionRounds;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\User;
use Livewire\Livewire;

test('admin can create view update and delete admission_rounds', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $page = Livewire::test(AdmissionRounds::class)->call('create')->set('form', ['code' => 'ROUND-NEW', 'name' => 'New round', 'year' => 2026, 'start_date' => '2026-01-01T09:00:12', 'end_date' => '2026-12-31T17:00:34', 'result_date' => null, 'status' => 'draft'])->call('save')->assertHasNoErrors();
    $record = AdmissionRound::query()->sole();
    $this->assertDatabaseHas('admission_rounds', ['id' => $record->id, 'code' => $record->code]);
    $page->call('details', $record->id)->assertSet('readOnly', true)->assertSee($record->name)
        ->call('edit', $record->id)->set('form.name', 'Updated configuration')->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('admission_rounds', ['id' => $record->id, 'name' => 'Updated configuration']);
    $page->call('confirmDeletion', $record->id)->call('delete')->assertHasNoErrors();
    $this->assertModelMissing($record);
});

test('admission_rounds reject invalid form data without persisting', function (string $field, mixed $value) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Livewire::test(AdmissionRounds::class)->call('create')->set('form', ['code' => 'ROUND-NEW', 'name' => 'New round', 'year' => 2026, 'start_date' => '2026-01-01T09:00:12', 'end_date' => '2026-12-31T17:00:34', 'result_date' => null, 'status' => 'draft'])
        ->set('form.'.$field, $value)->call('save')->assertHasErrors(in_array($field, ['id', 'score_config']) ? 'form' : 'form.'.$field);
    $this->assertDatabaseCount('admission_rounds', 0);
})->with(['missing code' => ['code', ''], 'long code' => ['code', str_repeat('x', 31)], 'missing name' => ['name', ''], 'long name' => ['name', str_repeat('x', 256)],
    'early year' => ['year', 1999], 'late year' => ['year', 2101], 'fractional year' => ['year', 2026.5],
    'invalid start' => ['start_date', '2026-02-30T10:00:00'], 'end before start' => ['end_date', '2025-01-01T00:00:00'],
    'end equals start' => ['end_date', '2026-01-01T09:00:12'], 'result before end' => ['result_date', '2026-01-01T00:00:00'],
    'invalid status' => ['status', 'archived'], 'extra attribute' => ['id', 777]]);

test('admission_rounds reject duplicate codes on create and update but allow their own code', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionRound::factory()->create(['code' => 'EXISTING']);
    $other = AdmissionRound::factory()->create(['code' => 'OTHER']);
    $page = Livewire::test(AdmissionRounds::class)->call('create')->set('form', ['code' => 'ROUND-NEW', 'name' => 'New round', 'year' => 2026, 'start_date' => '2026-01-01T09:00:12', 'end_date' => '2026-12-31T17:00:34', 'result_date' => null, 'status' => 'draft'])
        ->set('form.code', 'EXISTING')->call('save')->assertHasErrors('form.code');
    $page->call('edit', $other->id)->set('form.code', 'EXISTING')->call('save')->assertHasErrors('form.code');
    $this->assertDatabaseHas('admission_rounds', ['id' => $other->id, 'code' => 'OTHER']);
    $page->call('edit', $record->id)->call('save')->assertHasNoErrors();
    $this->assertDatabaseCount('admission_rounds', 2);
});

test('admission_rounds preserve records when dependencies exist including after confirmation', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionRound::factory()->create();
    $page = Livewire::test(AdmissionRounds::class)->call('confirmDeletion', $record->id)->assertHasNoErrors();
    AdmissionProgram::factory()->for($record, 'admissionRound')->create();
    $page->call('delete')->assertHasErrors('deletion')->assertSee('This record is in use by admission records and cannot be deleted.');
    $this->assertModelExists($record);
    $this->assertDatabaseCount('admission_programs', 1);
});

test('admission_rounds search pagination and reset expose only matching records', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    AdmissionRound::factory()->count(16)->create(['name' => 'Ordinary record']);
    $target = AdmissionRound::factory()->create(['name' => 'Unique needle', 'code' => 'SEARCH-TARGET']);
    Livewire::test(AdmissionRounds::class)->assertViewHas('records', fn ($records) => $records->count() === 15 && $records->total() === 17)
        ->call('setPage', 2)->set('search', 'SEARCH-TARGET')->assertSet('paginators.page', 1)
        ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $target->id)
        ->set('search', 'Unique needle')->assertViewHas('records', fn ($records) => $records->total() === 1)
        ->set('search', 'nothing-matches')->assertSee('No admission rounds found')
        ->call('clearFilters')->assertViewHas('records', fn ($records) => $records->total() === 17);
});

test('round dates preserve seconds in the configured timezone and allow historical cross-year intakes', function (string $timezone) {
    config(['app.timezone' => $timezone]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionRound::factory()->create(['year' => 2000, 'start_date' => '2000-12-31 23:59:12', 'end_date' => '2001-01-01 00:00:34', 'result_date' => '2001-01-01 00:00:34']);
    Livewire::test(AdmissionRounds::class)->call('edit', $record->id)
        ->assertSet('form.start_date', '2000-12-31T23:59:12')->assertSee($timezone)
        ->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('admission_rounds', ['id' => $record->id, 'start_date' => '2000-12-31 23:59:12', 'end_date' => '2001-01-01 00:00:34', 'result_date' => '2001-01-01 00:00:34']);
})->with(['UTC', 'Asia/Ho_Chi_Minh']);

test('rounds accept both year boundaries and all defined statuses', function (int $year, AdmissionRoundStatus $status) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = AdmissionRound::factory()->create();
    Livewire::test(AdmissionRounds::class)->call('edit', $record->id)->set('form.year', $year)->set('form.status', $status->value)->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('admission_rounds', ['id' => $record->id, 'year' => $year, 'status' => $status->value]);
})->with([2000, 2100])->with(AdmissionRoundStatus::cases());

test('rounds filter by year and status together', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $target = AdmissionRound::factory()->create(['year' => 2026, 'status' => 'open']);
    AdmissionRound::factory()->create(['year' => 2025, 'status' => 'open']);
    AdmissionRound::factory()->create(['year' => 2026, 'status' => 'draft']);
    Livewire::test(AdmissionRounds::class)->set('yearFilter', '2026')->set('statusFilter', 'open')
        ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $target->id)
        ->call('clearFilters')->assertViewHas('records', fn ($records) => $records->total() === 3);
});

test('applications prevent round deletion without programs', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $application = Application::factory()->create();
    Livewire::test(AdmissionRounds::class)->call('confirmDeletion', $application->admission_round_id)->call('delete')->assertHasErrors('deletion');
    $this->assertModelExists($application);
    $this->assertModelExists($application->admissionRound);
});

test('omitting the optional result date does not cause a server error', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Livewire::test(AdmissionRounds::class)->call('create')->set('form', ['code' => 'OPTIONAL', 'name' => 'Optional date', 'year' => 2026, 'start_date' => '2026-01-01T00:00', 'end_date' => '2026-02-01T00:00', 'status' => 'draft'])
        ->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('admission_rounds', ['code' => 'OPTIONAL', 'result_date' => null]);
});
