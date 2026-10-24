<?php

use App\Livewire\Admin\Results as AdminResults;
use App\Livewire\Candidate\Results as CandidateResults;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
        throw new RuntimeException('Only isolated SQLite memory is allowed.');
    }
    $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
    $this->travelTo(now()->setDate(2026, 10, 24)->setTime(10, 0));
});

function presentationApplication(AdmissionRound $round, int $number, ?int $admittedPriority = null, bool $confirmed = false, ?int $profileId = null): Application
{
    $application = Application::factory()->create(['admission_round_id' => $round->id, 'application_code' => sprintf('RC26-%03d', $number), 'status' => 'completed',
        ...($profileId ? ['candidate_profile_id' => $profileId] : [])]);
    foreach ([1, 2, 3] as $priority) {
        $admitted = $priority === $admittedPriority;
        $wish = AdmissionWish::factory()->for($application)->create(['priority' => $priority, 'status' => $admitted ? 'admitted' : 'rejected']);
        AdmissionResult::factory()->for($wish)->create(['decision' => $admitted ? 'admitted' : 'not_admitted', 'rank' => $admitted ? 1 : null,
            'published_at' => $round->status->value === 'published' ? now()->subDay() : null, 'confirmed_at' => $admitted && $confirmed ? now()->subHour() : null]);
    }

    return $application;
}

test('publication dashboard filters paginates and preserves the 195 result fixture including confirmed NV2', function () {
    $round = AdmissionRound::factory()->create(['status' => 'published']);
    for ($i = 1; $i <= 65; $i++) {
        $application = presentationApplication($round, $i, $i <= 39 ? 1 : ($i === 41 ? 2 : null), $i === 41);
        if ($i === 41) {
            $target = $application;
        }
    }
    $before = AdmissionResult::orderBy('id')->get()->toJson();
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    Model::preventLazyLoading();
    try {
        $page = Livewire::test(AdminResults::class)->set('roundSelection', (string) $round->id)
            ->assertViewHas('counts', ['Total results' => 195, 'Admitted' => 40, 'Not admitted' => 155, 'Published' => 195, 'Unpublished' => 0, 'Confirmed admitted' => 1])
            ->assertDontSee('wire:click="publish"', false)
            ->call('setPage', 2)->assertSee('data-label="STT">26</td>', false)
            ->set('search', 'RC26-041')->assertViewHas('results', fn ($items) => $items->total() === 3 && $items->currentPage() === 1)
            ->assertSee($target->candidateProfile->user->name)->assertSee('Đã xác nhận nhập học')->assertSee('NV2')
            ->set('search', $target->candidateProfile->user->name)->assertViewHas('results', fn ($items) => $items->total() === 3)
            ->set('confirmationFilter', 'confirmed')->assertViewHas('results', fn ($items) => $items->total() === 1)
            ->assertSee('data-label="STT">1</td>', false)
            ->call('clearFilters')->set('decisionFilter', 'not_admitted')->assertViewHas('results', fn ($items) => $items->total() === 155)
            ->set('publicationFilter', 'unpublished')->assertSee('Không tìm thấy kết quả phù hợp.')
            ->call('clearFilters')->set('confirmationFilter', 'unconfirmed')->assertViewHas('results', fn ($items) => $items->total() === 39);
    } finally {
        Model::preventLazyLoading(false);
    }
    expect(AdmissionResult::orderBy('id')->get()->toJson())->toBe($before);
    $this->assertDatabaseCount('activity_logs', 0);
});

test('candidate NV2 confirmation remains visible and never offers confirmation again', function () {
    $round = AdmissionRound::factory()->create(['status' => 'published', 'name' => 'Đợt kiểm thử NV2']);
    $application = presentationApplication($round, 41, 2, true);
    $other = presentationApplication($round, 42, 1);
    $before = AdmissionResult::orderBy('id')->get()->toJson();
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(CandidateResults::class)->assertSeeInOrder(['NV1', 'NV2', 'NV3'])
        ->assertSee('Đã xác nhận nhập học')->assertDontSee('wire:click="confirm(', false)
        ->assertDontSee($other->application_code)->assertViewHas('resultRounds', fn ($items) => $items->total() === 1)
        ->assertViewHas('results', fn ($items) => $items->count() === 3);
    expect(AdmissionResult::orderBy('id')->get()->toJson())->toBe($before);
});

test('candidate rounds paginate as complete groups and unpublished results stay private', function () {
    $profileId = null;
    for ($i = 1; $i <= 6; $i++) {
        $round = AdmissionRound::factory()->create(['status' => 'published']);
        $application = presentationApplication($round, $i, 2, false, $profileId);
        $profileId = $application->candidate_profile_id;
    }
    $hidden = AdmissionRound::factory()->create(['status' => 'processing', 'name' => 'HIDDEN-ROUND']);
    presentationApplication($hidden, 7, 1, false, $profileId);
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(CandidateResults::class)->assertViewHas('resultRounds', fn ($items) => $items->count() === 5)
        ->assertViewHas('results', fn ($items) => $items->count() === 15)->assertSee('wire:click="confirm(', false)
        ->assertDontSee('HIDDEN-ROUND')->call('setPage', 2)
        ->assertViewHas('results', fn ($items) => $items->count() === 3);
});

test('before publication dashboard offers confirmation only for processing and rejects other roles', function () {
    $round = AdmissionRound::factory()->create(['status' => 'processing']);
    presentationApplication($round, 1, 1);
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    Livewire::test(AdminResults::class)->set('roundSelection', (string) $round->id)
        ->assertSee('wire:click="publish"', false)->assertSee('wire:confirm=', false)
        ->set('publicationFilter', 'unpublished')->assertViewHas('results', fn ($items) => $items->total() === 3);
    foreach (['staff', 'candidate'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        Livewire::test(AdminResults::class)->assertForbidden();
    }
    $this->assertDatabaseCount('activity_logs', 0);
});
