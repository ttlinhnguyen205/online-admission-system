<?php

use App\Enums\AdmissionRoundStatus;
use App\Enums\WishStatus;
use App\Livewire\Candidate\ApplicationDetails;
use App\Models\AdmissionResult;
use App\Models\AdmissionWish;
use App\Models\Application;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
});

/** @return array{Application, Collection<int, AdmissionWish>} */
function rankedWishes(array $priorities = [1, 2, 3]): array
{
    $application = Application::factory()->create();
    $application->admissionRound->update(['status' => AdmissionRoundStatus::Open]);
    foreach ($priorities as $priority) {
        AdmissionWish::factory()->for($application)->create(['priority' => $priority]);
    }

    return [$application, $application->wishes()->orderBy('priority')->get()];
}

test('a two way swap uses safe priorities and preserves all wish system fields', function () {
    [$application, $wishes] = rankedWishes([1, 2]);
    $wishes[0]->update(['status' => WishStatus::Eligible, 'calculated_score' => '25.125']);
    $this->actingAs($application->candidateProfile->user);
    $intermediate = [];
    Event::listen('eloquent.saved: '.AdmissionWish::class, function (AdmissionWish $wish) use (&$intermediate): void {
        $intermediate[] = $wish->priority;
    });
    try {
        Livewire::test(ApplicationDetails::class, ['application' => $application->id])
            ->call('reorderWishes', [$wishes[1]->id, $wishes[0]->id])->assertHasNoErrors();
        expect($application->wishes()->orderBy('priority')->pluck('id')->all())->toBe([$wishes[1]->id, $wishes[0]->id]);
        expect($application->wishes()->orderBy('priority')->pluck('priority')->all())->toBe([1, 2]);
        expect($intermediate)->toBe([0, 1, 2]);
        expect($wishes[0]->fresh()->status)->toBe(WishStatus::Eligible);
        expect($wishes[0]->fresh()->calculated_score)->toBe('25.125');
        expect($wishes[0]->fresh()->admission_program_id)->toBe($wishes[0]->admission_program_id);
        $this->assertDatabaseCount('admission_wishes', 2);
    } finally {
        Event::forget('eloquent.saved: '.AdmissionWish::class);
    }
});

test('larger permutations and move controls produce contiguous ranked wishes', function () {
    [$application, $wishes] = rankedWishes([1, 2, 3, 4]);
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->call('reorderWishes', [$wishes[2]->id, $wishes[3]->id, $wishes[0]->id, $wishes[1]->id])->assertHasNoErrors();
    expect($application->wishes()->orderBy('priority')->pluck('id')->all())->toBe([$wishes[2]->id, $wishes[3]->id, $wishes[0]->id, $wishes[1]->id]);
    $page->call('moveWish', $wishes[0]->id, 'up')->assertHasNoErrors();
    expect($application->wishes()->orderBy('priority')->pluck('id')->all())->toBe([$wishes[2]->id, $wishes[0]->id, $wishes[3]->id, $wishes[1]->id]);
    $page->call('moveWish', $wishes[0]->id, 'down')->assertHasNoErrors();
    expect($application->wishes()->orderBy('priority')->pluck('priority')->all())->toBe([1, 2, 3, 4]);
});

test('reordering handles empty single sparse and unsigned boundary priorities', function (array $priorities) {
    [$application, $wishes] = rankedWishes($priorities);
    $this->actingAs($application->candidateProfile->user);
    $order = $wishes->reverse()->values()->modelKeys();
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('reorderWishes', $order)->assertHasNoErrors();
    expect($application->wishes()->orderBy('priority')->pluck('id')->all())->toBe($order);
    expect($application->wishes()->orderBy('priority')->pluck('priority')->all())->toBe($priorities === [] ? [] : range(1, count($priorities)));
})->with([[[]], [[1]], [[10, 30, 65535]], [[0, 1, 65535]]]);

test('deleting a middle wish compacts remaining priorities without replacing rows', function () {
    [$application, $wishes] = rankedWishes();
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmDeletion', $wishes[1]->id)->call('deleteWish')->assertHasNoErrors();
    expect($application->wishes()->orderBy('priority')->pluck('id')->all())->toBe([$wishes[0]->id, $wishes[2]->id]);
    expect($application->wishes()->orderBy('priority')->pluck('priority')->all())->toBe([1, 2]);
    $this->assertModelMissing($wishes[1]);
});

test('reordering rejects malformed incomplete duplicate and foreign wish orders atomically', function (string $kind) {
    [$application, $wishes] = rankedWishes();
    $foreign = AdmissionWish::factory()->create();
    $ids = $wishes->modelKeys();
    $order = match ($kind) {
        'duplicate' => [$ids[0], $ids[0], $ids[2]],
        'normalized-duplicate' => [$ids[0], (string) $ids[0], $ids[2]],
        'missing' => [$ids[0], $ids[1]],
        'empty' => [],
        'foreign' => [$ids[0], $ids[1], $foreign->id],
        'extra' => [...$ids, $foreign->id],
        'non-list' => ['first' => $ids[0], 'second' => $ids[1], 'third' => $ids[2]],
        'invalid-id' => [$ids[0], 'invalid', $ids[2]],
        'zero' => [$ids[0], 0, $ids[2]],
    };
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('reorderWishes', $order)->assertHasErrors();
    expect($application->wishes()->orderBy('priority')->pluck('id')->all())->toBe($ids);
    expect($application->wishes()->orderBy('priority')->pluck('priority')->all())->toBe([1, 2, 3]);
    expect($foreign->fresh()->priority)->toBe(1);
})->with(['duplicate', 'normalized-duplicate', 'missing', 'empty', 'foreign', 'extra', 'non-list', 'invalid-id', 'zero']);

test('stale wish sets or same set ordering cannot overwrite newer changes', function (string $change) {
    [$application, $wishes] = rankedWishes();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id]);
    if ($change === 'add') {
        AdmissionWish::factory()->for($application)->create(['priority' => 4]);
    } elseif ($change === 'delete') {
        $wishes[2]->delete();
    } else {
        Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('reorderWishes', [$wishes[1]->id, $wishes[0]->id, $wishes[2]->id])->assertHasNoErrors();
    }
    $current = $application->wishes()->orderBy('priority')->pluck('id')->all();
    $page->call('reorderWishes', array_reverse($current))->assertHasErrors('order')->assertSee('Danh sách nguyện vọng đã thay đổi');
    expect($application->wishes()->orderBy('priority')->pluck('id')->all())->toBe($current);
    $page->call('reloadWishes')->call('reorderWishes', array_reverse($current))->assertHasNoErrors();
    expect($application->wishes()->orderBy('priority')->pluck('id')->all())->toBe(array_reverse($current));
})->with(['add', 'delete', 'reorder']);

test('stale deletion confirmation does not remove a wish after the list changes', function () {
    [$application, $wishes] = rankedWishes();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmDeletion', $wishes[0]->id);
    AdmissionWish::factory()->for($application)->create(['priority' => 4]);
    $page->call('deleteWish')->assertHasErrors('order');
    $this->assertModelExists($wishes[0]);
    expect($application->wishes()->count())->toBe(4);
});

test('a failure after temporary priority movement rolls back the entire reorder', function () {
    [$application, $wishes] = rankedWishes();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id]);
    $saves = 0;
    Event::listen('eloquent.saving: '.AdmissionWish::class, function () use (&$saves): bool {
        return ++$saves !== 2;
    });
    try {
        $page->call('reorderWishes', [$wishes[1]->id, $wishes[0]->id, $wishes[2]->id])->assertHasErrors('order');
        expect($saves)->toBe(2);
        expect($application->wishes()->orderBy('priority')->pluck('id')->all())->toBe($wishes->modelKeys());
        expect($application->wishes()->orderBy('priority')->pluck('priority')->all())->toBe([1, 2, 3]);
    } finally {
        Event::forget('eloquent.saving: '.AdmissionWish::class);
    }
});

test('failed compaction rolls back deletion and all remaining priorities', function () {
    [$application, $wishes] = rankedWishes();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmDeletion', $wishes[1]->id);
    Event::listen('eloquent.saving: '.AdmissionWish::class, fn () => false);
    try {
        $page->call('deleteWish')->assertHasErrors('order');
        $this->assertModelExists($wishes[1]);
        expect($application->wishes()->orderBy('priority')->pluck('priority')->all())->toBe([1, 2, 3]);
    } finally {
        Event::forget('eloquent.saving: '.AdmissionWish::class);
    }
});

test('result backed wishes cannot move directly or through deletion compaction', function (string $operation) {
    [$application, $wishes] = rankedWishes();
    $result = AdmissionResult::factory()->for($wishes[2])->create();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id]);
    if ($operation === 'delete') {
        $page->call('confirmDeletion', $wishes[0]->id)->call('deleteWish')->assertHasErrors('order');
    } else {
        $page->call('reorderWishes', [$wishes[2]->id, $wishes[1]->id, $wishes[0]->id])->assertHasErrors('order');
    }
    expect($application->wishes()->orderBy('priority')->pluck('id')->all())->toBe($wishes->modelKeys());
    $this->assertModelExists($result);
    $this->assertModelExists($wishes[0]);
})->with(['delete', 'reorder']);

test('unaffected result backed wish remains untouched when other wishes are reordered', function () {
    [$application, $wishes] = rankedWishes();
    $result = AdmissionResult::factory()->for($wishes[2])->create();
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->call('reorderWishes', [$wishes[1]->id, $wishes[0]->id, $wishes[2]->id])->assertHasNoErrors();
    expect($wishes[2]->fresh()->priority)->toBe(3);
    $this->assertModelExists($result);
});

test('an unrelated database failure is not disguised as a deletion dependency', function () {
    [$application, $wishes] = rankedWishes();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmDeletion', $wishes[0]->id);
    Event::listen('eloquent.deleting: '.AdmissionWish::class, fn () => DB::table('missing_phase_five_table')->delete());
    try {
        expect(fn () => $page->call('deleteWish'))->toThrow(QueryException::class);
        $this->assertModelExists($wishes[0]);
    } finally {
        Event::forget('eloquent.deleting: '.AdmissionWish::class);
    }
});
