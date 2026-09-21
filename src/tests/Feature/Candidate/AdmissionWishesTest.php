<?php

use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Enums\WishStatus;
use App\Livewire\Candidate\ApplicationDetails;
use App\Models\AdmissionProgram;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
});

function editableWishApplication(): Application
{
    $application = Application::factory()->create();
    $application->admissionRound->update(['status' => AdmissionRoundStatus::Open]);

    return $application;
}

test('candidates add and remove wishes with server assigned system fields', function (ApplicationStatus $status) {
    $application = editableWishApplication();
    $application->update(['status' => $status]);
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->set('form.admission_program_id', $program->id)->call('addWish')->assertHasNoErrors();
    $wish = $application->wishes()->sole();
    expect($wish->priority)->toBe(1);
    expect($wish->status)->toBe(WishStatus::Pending);
    expect($wish->calculated_score)->toBeNull();
    $page->call('confirmDeletion', $wish->id)->call('deleteWish')->assertHasNoErrors();
    $this->assertModelMissing($wish);
    $this->assertModelExists($application);
})->with([ApplicationStatus::Draft, ApplicationStatus::NeedsRevision]);

test('wish selection rejects unavailable and foreign round programs server side', function (string $condition) {
    $application = editableWishApplication();
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    match ($condition) {
        'cross-round' => $program->update(['admission_round_id' => AdmissionRound::factory()->create()->id]),
        'inactive-program' => $program->update(['status' => 'inactive']),
        'unknown-status' => $program->update(['status' => 'unexpected']),
        'inactive-major' => $program->major->update(['is_active' => false]),
        'inactive-method' => $program->admissionMethod->update(['is_active' => false]),
        'zero-quota' => $program->update(['quota' => 0]),
        'missing' => $program->delete(),
    };
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->set('form.admission_program_id', $program->id)->call('addWish')->assertHasErrors('form.admission_program_id');
    $this->assertDatabaseCount('admission_wishes', 0);
})->with(['cross-round', 'inactive-program', 'unknown-status', 'inactive-major', 'inactive-method', 'zero-quota', 'missing']);

test('programs becoming unavailable after rendering are rejected without removing existing wishes', function () {
    $application = editableWishApplication();
    $wish = AdmissionWish::factory()->for($application)->create();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id]);
    $wish->admissionProgram->update(['status' => 'inactive']);
    $page->call('$refresh')->assertSee('Chương trình không hoạt động')->assertSee($wish->admissionProgram->major->name);
    $page->set('form.admission_program_id', $wish->admission_program_id)->call('addWish')->assertHasErrors('form.admission_program_id');
    $this->assertModelExists($wish);
});

test('duplicate programs cannot overwrite existing wishes', function () {
    $application = editableWishApplication();
    $wish = AdmissionWish::factory()->for($application)->create();
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->set('form.admission_program_id', $wish->admission_program_id)->call('addWish')->assertHasErrors('form.admission_program_id');
    expect($application->wishes()->count())->toBe(1);
    expect($wish->fresh()->priority)->toBe(1);
});

test('wish forms reject system fields and unexpected keys', function (string $field) {
    $application = editableWishApplication();
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->set('form', ['admission_program_id' => $program->id, $field => 'tampered'])->call('addWish')->assertHasErrors('form');
    $this->assertDatabaseCount('admission_wishes', 0);
})->with(['application_id', 'candidate_profile_id', 'admission_round_id', 'priority', 'calculated_score', 'status', 'application_code', 'submitted_at', 'reviewed_by', 'reviewed_at', 'revision_reason', 'id', 'unexpected']);

test('wish program identifiers must be valid positive integers', function (mixed $id) {
    $application = editableWishApplication();
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->set('form.admission_program_id', $id)->call('addWish')->assertHasErrors('form.admission_program_id');
    $this->assertDatabaseCount('admission_wishes', 0);
})->with([null, '', -1, 0, 1.5, [1]]);

test('all noneditable lifecycles deny wish actions while retaining readable data', function (ApplicationStatus $status, string $action) {
    $application = editableWishApplication();
    $wish = AdmissionWish::factory()->for($application)->create();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id]);
    if ($action === 'deleteWish') {
        $page->call('confirmDeletion', $wish->id);
    }
    $application->update(['status' => $status]);
    $arguments = match ($action) {
        'confirmDeletion' => [$wish->id], 'reorderWishes' => [[$wish->id]], default => [],
    };
    $page->set('form.admission_program_id', $wish->admission_program_id)->call($action, ...$arguments)->assertForbidden();
    $this->get(route('candidate.applications.show', $application->id))->assertOk()->assertSee('Chỉ có thể sửa nguyện vọng');
    $this->assertModelExists($wish);
})->with([ApplicationStatus::Submitted, ApplicationStatus::UnderReview, ApplicationStatus::Verified, ApplicationStatus::Processing, ApplicationStatus::Completed])
    ->with(['addWish', 'confirmDeletion', 'deleteWish', 'reorderWishes']);

test('wish mutations require an open round even after an editor opens', function (AdmissionRoundStatus $status, string $action) {
    $application = editableWishApplication();
    $wish = AdmissionWish::factory()->for($application)->create();
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmDeletion', $wish->id);
    $application->admissionRound->update(['status' => $status]);
    $page->set('form.admission_program_id', $program->id)->call($action, ...($action === 'reorderWishes' ? [[$wish->id]] : []))->assertHasErrors('round');
    $this->assertModelExists($wish);
    expect($application->wishes()->count())->toBe(1);
})->with([AdmissionRoundStatus::Draft, AdmissionRoundStatus::Closed, AdmissionRoundStatus::Processing, AdmissionRoundStatus::Published])->with(['addWish', 'deleteWish', 'reorderWishes']);

test('wish mutation time boundaries are inclusive', function (string $time, bool $allowed, string $action) {
    $application = editableWishApplication();
    $application->admissionRound->update(['start_date' => '2026-09-14 11:00:00', 'end_date' => '2026-09-14 13:00:00']);
    $wish = AdmissionWish::factory()->for($application)->create();
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmDeletion', $wish->id);
    $this->travelTo(CarbonImmutable::parse($time, 'UTC'));
    $page->set('form.admission_program_id', $program->id)->call($action, ...($action === 'reorderWishes' ? [[$wish->id]] : []));
    $allowed ? $page->assertHasNoErrors() : $page->assertHasErrors('round');
    expect($application->wishes()->count())->toBe($allowed ? match ($action) {
        'addWish' => 2, 'deleteWish' => 0, default => 1
    } : 1);
})->with([['2026-09-14 10:59:59', false], ['2026-09-14 11:00:00', true], ['2026-09-14 13:00:00', true], ['2026-09-14 13:00:01', false]])
    ->with(['addWish', 'deleteWish', 'reorderWishes']);

test('foreign wishes including another owned application fail with 404 through real action requests', function (bool $sameOwner, string $action) {
    $application = editableWishApplication();
    $other = $sameOwner ? Application::factory()->for($application->candidateProfile)->create() : Application::factory()->create();
    $foreign = AdmissionWish::factory()->for($other)->create();
    $this->actingAs($application->candidateProfile->user);
    $response = $this->get(route('candidate.applications.show', $application->id));
    preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $params = $action === 'moveWish' ? [$foreign->id, 'up'] : [$foreign->id];
    $this->postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => html_entity_decode($matches[1], ENT_QUOTES), 'updates' => [],
        'calls' => [['path' => '', 'method' => $action, 'params' => $params]],
    ]]], ['X-Livewire' => 'true'])->assertNotFound();
    $this->assertModelExists($foreign);
})->with([true, false])->with(['confirmDeletion', 'moveWish']);

test('result backed wishes cannot be deleted even when a result appears after confirmation', function () {
    $application = editableWishApplication();
    $wish = AdmissionWish::factory()->for($application)->create();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmDeletion', $wish->id);
    $result = AdmissionResult::factory()->for($wish)->create();
    $page->call('deleteWish')->assertForbidden();
    $this->assertModelExists($wish);
    $this->assertModelExists($result);
});

test('foreign keys protect a result introduced after wish deletion authorization', function () {
    $application = editableWishApplication();
    $wish = AdmissionWish::factory()->for($application)->create();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmDeletion', $wish->id);
    Event::listen('eloquent.deleting: '.AdmissionWish::class, fn (AdmissionWish $record) => AdmissionResult::factory()->for($record)->create());
    try {
        $page->call('deleteWish')->assertHasErrors('deletion');
        $this->assertModelExists($wish);
    } finally {
        Event::forget('eloquent.deleting: '.AdmissionWish::class);
    }
});

test('failed wish saving and deletion roll back without success', function (string $operation) {
    $application = editableWishApplication();
    $wish = AdmissionWish::factory()->for($application)->create();
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmDeletion', $wish->id);
    $event = 'eloquent.'.($operation === 'addWish' ? 'saving' : 'deleting').': '.AdmissionWish::class;
    Event::listen($event, fn () => false);
    try {
        $page->set('form.admission_program_id', $program->id)->call($operation)->assertHasErrors($operation === 'addWish' ? 'wishes' : 'deletion');
        expect($application->wishes()->count())->toBe(1);
        $this->assertModelExists($wish);
    } finally {
        Event::forget($event);
    }
})->with(['addWish', 'deleteWish']);

test('application detail escapes catalog names and review reasons', function () {
    $application = editableWishApplication();
    $application->update(['revision_reason' => '<script>review()</script>']);
    $wish = AdmissionWish::factory()->for($application)->create();
    $wish->admissionProgram->major->update(['name' => '<script>catalog()</script>']);
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->assertSee('&lt;script&gt;review()&lt;/script&gt;', false)->assertDontSee('<script>review()</script>', false)
        ->assertSee('&lt;script&gt;catalog()&lt;/script&gt;', false)->assertDontSee('<script>catalog()</script>', false);
});

test('a duplicate wish priority race fails safely instead of overwriting a wish', function () {
    $application = editableWishApplication();
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $other = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $this->actingAs($application->candidateProfile->user);
    Event::listen('eloquent.creating: '.AdmissionWish::class, function (AdmissionWish $wish) use ($other): void {
        DB::table('admission_wishes')->insert([
            'application_id' => $wish->application_id, 'admission_program_id' => $other->id, 'priority' => $wish->priority,
        ]);
    });
    try {
        Livewire::test(ApplicationDetails::class, ['application' => $application->id])->set('form.admission_program_id', $program->id)->call('addWish')->assertHasErrors('wishes');
        $this->assertDatabaseCount('admission_wishes', 0);
    } finally {
        Event::forget('eloquent.creating: '.AdmissionWish::class);
    }
});

test('wish creation must authorize the existing create ability', function () {
    $application = editableWishApplication();
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $this->actingAs($application->candidateProfile->user);
    Gate::before(fn (User $user, string $ability) => $ability === 'create' ? false : null);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->set('form.admission_program_id', $program->id)->call('addWish')->assertForbidden();
    $this->assertDatabaseCount('admission_wishes', 0);
});
