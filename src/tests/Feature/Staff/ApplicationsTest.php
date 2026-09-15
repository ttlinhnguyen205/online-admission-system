<?php

use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\Applications;
use App\Models\Application;
use App\Models\User;
use Livewire\Livewire;

test('the default queue contains only submitted and under review applications', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    foreach (ApplicationStatus::cases() as $status) {
        Application::factory()->create(['status' => $status, 'submitted_at' => now()]);
    }
    Livewire::test(Applications::class)->assertViewHas('records', fn ($records) => $records->total() === 2)
        ->set('statusFilter', 'all')->assertViewHas('records', fn ($records) => $records->total() === 6);
});

test('queue search matches only the intended application by every supported identity field', function (string $field) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $match = Application::factory()->create(['status' => 'submitted']);
    Application::factory()->create(['status' => 'submitted']);
    match ($field) {
        'application_code' => $match->update([$field => 'FIND-REVIEW']),
        'candidate_code' => $match->candidateProfile->update([$field => 'FIND-REVIEW']),
        'name' => $match->candidateProfile->user->update([$field => 'FIND-REVIEW']),
        'email' => $match->candidateProfile->user->update([$field => 'FIND-REVIEW@example.test']),
    };
    Livewire::test(Applications::class)->set('search', 'FIND-REVIEW')
        ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $match->id)
        ->set('search', "' OR 1=1 --")->assertViewHas('records', fn ($records) => $records->total() === 0);
})->with(['application_code', 'candidate_code', 'name', 'email']);

test('round status pagination and reset combine without leaking other records', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $first = Application::factory()->create(['status' => 'submitted']);
    Application::factory()->for($first->admissionRound)->count(16)->create(['status' => 'submitted']);
    $other = Application::factory()->create(['status' => 'verified']);
    Livewire::test(Applications::class)->assertViewHas('records', fn ($r) => $r->total() === 17 && $r->count() === 15)
        ->call('setPage', 2)->assertViewHas('records', fn ($r) => $r->count() === 2)
        ->set('statusFilter', 'verified')->assertViewHas('records', fn ($r) => $r->total() === 1 && $r->first()->id === $other->id)
        ->set('roundFilter', (string) $first->admission_round_id)->assertViewHas('records', fn ($r) => $r->total() === 0)
        ->call('clearFilters')->assertViewHas('records', fn ($r) => $r->count() === 15);
});

test('review queue filters validate unsupported values', function (string $field, mixed $value) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    Livewire::test(Applications::class)->set($field, $value)->assertHasErrors($field);
})->with([['search', str_repeat('x', 101)], ['statusFilter', 'draft'], ['statusFilter', 'bogus'], ['roundFilter', '-1'], ['roundFilter', '999999']]);

test('the review queue sorts oldest submissions first with an id tie breaker', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $newer = Application::factory()->create(['status' => 'submitted', 'submitted_at' => '2026-09-14 12:00:00']);
    $older = Application::factory()->create(['status' => 'under_review', 'submitted_at' => '2026-09-13 12:00:00']);
    Livewire::test(Applications::class)->assertViewHas('records', fn ($r) => $r->modelKeys() === [$older->id, $newer->id]);
});
