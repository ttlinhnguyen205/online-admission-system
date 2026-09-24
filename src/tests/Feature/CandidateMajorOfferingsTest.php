<?php

use App\Actions\ConfirmAdmissionResult;
use App\Actions\PublishAdmissionResults;
use App\Enums\AdmissionRoundStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Admin\CandidateMajorOfferings as OfferingEditor;
use App\Livewire\Candidate\ApplicationDetails;
use App\Livewire\Candidate\Results;
use App\Models\AdmissionProgram;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\CandidateMajorOffering;
use App\Models\Major;
use App\Models\User;
use Database\Seeders\AdmissionDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

require_once __DIR__.'/AdmissionEngineFixtures.php';

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
});

function majorOfferingApplication(): Application
{
    $round = AdmissionRound::factory()->create([
        'status' => AdmissionRoundStatus::Open,
        'start_date' => now()->subHour(), 'end_date' => now()->addHour(),
    ]);

    return Application::factory()->for($round)->create();
}

test('candidate chooses one major across two internal programs and pins the explicit program', function () {
    $application = majorOfferingApplication();
    $first = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $mapped = AdmissionProgram::factory()->for($application->admissionRound)->for($first->major)->create();
    $offering = CandidateMajorOffering::factory()->for($mapped)->create();
    $this->actingAs($application->candidateProfile->user);

    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->assertSee($mapped->major->name)
        ->assertDontSee($first->admissionMethod->name)->assertDontSee($mapped->admissionMethod->name)
        ->assertDontSee($mapped->admissionMethod->code)->assertDontSee('form.admission_program_id')
        ->assertDontSee('Điểm tối thiểu')->assertDontSee('Chỉ tiêu')
        ->assertViewHas('offerings', fn ($choices) => $choices->modelKeys() === [$offering->id])
        ->set('form.candidate_major_offering_id', $offering->id)->call('addWish')->assertHasNoErrors()
        ->assertSee('Nguyện vọng 1')->assertSee($mapped->major->name)->assertDontSee($mapped->admissionMethod->name);

    $wish = $application->wishes()->sole();
    expect($wish->candidate_major_offering_id)->toBe($offering->id);
    expect($wish->admission_program_id)->toBe($mapped->id);
    expect($wish->priority)->toBe(1);
    $page->set('form.candidate_major_offering_id', $offering->id)->call('addWish')
        ->assertHasErrors('form.candidate_major_offering_id');
    expect($application->wishes()->count())->toBe(1);
});

test('unavailable offerings are absent and forged selections fail closed', function (string $condition) {
    $application = majorOfferingApplication();
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $offering = CandidateMajorOffering::factory()->for($program)->create();
    match ($condition) {
        'major' => $program->major->update(['is_active' => false]),
        'program' => $program->update(['status' => 'inactive']),
        'method' => $program->admissionMethod->update(['is_active' => false]),
        'quota' => $program->update(['quota' => 0]),
        'disabled' => $offering->update(['is_selectable' => false]),
        'mapping' => $offering->update(['admission_program_id' => null]),
        'draft' => $application->admissionRound->update(['status' => AdmissionRoundStatus::Draft]),
        'before' => $application->admissionRound->update(['start_date' => now()->addMinute()]),
        'after' => $application->admissionRound->update(['end_date' => now()->subMinute()]),
    };
    $this->actingAs($application->candidateProfile->user);

    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->assertDontSee($program->major->name)->assertDontSee($program->admissionMethod->name)
        ->set('form.candidate_major_offering_id', $offering->id)->call('addWish')
        ->assertHasErrors(in_array($condition, ['draft', 'before', 'after'], true) ? 'round' : 'form.candidate_major_offering_id');
    $this->assertDatabaseCount('admission_wishes', 0);
})->with(['major', 'program', 'method', 'quota', 'disabled', 'mapping', 'draft', 'before', 'after']);

test('offering registration includes both exact window boundaries', function (string $boundary) {
    $application = majorOfferingApplication();
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $offering = CandidateMajorOffering::factory()->for($program)->create();
    $this->travelTo($application->admissionRound->{$boundary});
    $this->actingAs($application->candidateProfile->user);

    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->assertSee($program->major->name)
        ->set('form.candidate_major_offering_id', $offering->id)->call('addWish')->assertHasNoErrors();
    $this->assertDatabaseHas('admission_wishes', ['candidate_major_offering_id' => $offering->id, 'admission_program_id' => $program->id]);
})->with(['start_date', 'end_date']);

test('candidate cannot inject program ids or select an offering from another round', function () {
    $application = majorOfferingApplication();
    $foreign = CandidateMajorOffering::factory()->create();
    $this->actingAs($application->candidateProfile->user);

    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->set('form', ['candidate_major_offering_id' => $foreign->id, 'admission_program_id' => $foreign->admission_program_id])
        ->call('addWish')->assertHasErrors('form')
        ->set('form', ['candidate_major_offering_id' => $foreign->id])
        ->call('addWish')->assertHasErrors('form.candidate_major_offering_id');
    $this->assertDatabaseCount('admission_wishes', 0);
});

test('catalog changes after rendering are rechecked when adding', function () {
    $application = majorOfferingApplication();
    $offering = CandidateMajorOffering::factory()->for(AdmissionProgram::factory()->for($application->admissionRound)->create())->create();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->set('form.candidate_major_offering_id', $offering->id);
    $offering->update(['is_selectable' => false]);

    $page->call('addWish')->assertHasErrors('form.candidate_major_offering_id');
    $this->assertDatabaseCount('admission_wishes', 0);
});

test('legacy wishes block a duplicate major through another program without being rewritten', function () {
    $application = majorOfferingApplication();
    $wish = AdmissionWish::factory()->for($application)->create();
    $original = $wish->fresh()->getAttributes();
    $other = AdmissionProgram::factory()->for($application->admissionRound)->for($wish->admissionProgram->major)->create();
    $offering = CandidateMajorOffering::factory()->for($other)->create();
    $this->actingAs($application->candidateProfile->user);

    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->assertViewHas('offerings', fn ($choices) => $choices->isEmpty())
        ->set('form.candidate_major_offering_id', $offering->id)->call('addWish')->assertHasErrors('form.candidate_major_offering_id');
    expect($wish->fresh()->getAttributes())->toBe($original);
});

test('draft round owned applications stay readable without exposing an unselected catalog', function () {
    $application = majorOfferingApplication();
    $wish = AdmissionWish::factory()->for($application)->create();
    $offering = CandidateMajorOffering::factory()->for(AdmissionProgram::factory()->for($application->admissionRound)->create())->create();
    $application->admissionRound->update(['status' => AdmissionRoundStatus::Draft]);
    $this->actingAs($application->candidateProfile->user);

    $this->get(route('candidate.applications.show', $application->id))
        ->assertOk()->assertSee($wish->admissionProgram->major->name)
        ->assertDontSee($offering->major->name)->assertDontSee('form.candidate_major_offering_id');
    $this->assertModelExists($wish);
});

test('admin configures only an explicitly matching program and preserves unused catalogs', function () {
    $program = AdmissionProgram::factory()->create();
    $unrelated = AdmissionProgram::factory()->create();
    $before = $unrelated->fresh()->getAttributes();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test(OfferingEditor::class)->call('create')
        ->set('form.admission_round_id', $program->admission_round_id)
        ->set('form.major_id', $program->major_id)
        ->assertViewHas('programs', fn ($programs) => $programs->modelKeys() === [$program->id])
        ->set('form.admission_program_id', $program->id)->set('form.is_selectable', true)
        ->call('save')->assertHasNoErrors();
    $offering = CandidateMajorOffering::query()->sole();
    expect($offering->admission_program_id)->toBe($program->id);
    expect($unrelated->fresh()->getAttributes())->toBe($before);

    Livewire::test(OfferingEditor::class)->call('create')->set('form', $offering->only([
        'admission_round_id', 'major_id', 'admission_program_id', 'is_selectable',
    ]))->call('save')->assertHasErrors('form.major_id');
    Livewire::test(OfferingEditor::class)->call('confirmDeletion', $offering->id)->call('delete')->assertHasNoErrors();
    $this->assertModelMissing($offering);
    $this->assertModelExists($program);
});

test('admin rejects a missing cross round or cross major compatibility program', function (string $condition) {
    $program = AdmissionProgram::factory()->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $form = [
        'admission_round_id' => $condition === 'round' ? AdmissionRound::factory()->create()->id : $program->admission_round_id,
        'major_id' => $condition === 'major' ? Major::factory()->create()->id : $program->major_id,
        'admission_program_id' => $condition === 'missing' ? null : $program->id,
        'is_selectable' => true,
    ];

    Livewire::test(OfferingEditor::class)->call('create')->set('form', $form)->call('save')->assertHasErrors('form.admission_program_id');
    $this->assertDatabaseCount('candidate_major_offerings', 0);
})->with(['round', 'major', 'missing']);

test('database rejects cross round and cross major mappings even outside the admin interface', function (string $field) {
    $offering = CandidateMajorOffering::factory()->create();
    $other = $field === 'major_id' ? Major::factory()->create() : AdmissionRound::factory()->create();

    expect(fn () => $offering->update([$field => $other->id]))->toThrow(QueryException::class);
})->with(['admission_round_id', 'major_id']);

test('an offering mapping cannot change after a wish arrives while the admin editor is open', function () {
    $application = majorOfferingApplication();
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $replacement = AdmissionProgram::factory()->for($application->admissionRound)->for($program->major)->create();
    $offering = CandidateMajorOffering::factory()->for($program)->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin);
    $page = Livewire::test(OfferingEditor::class)->call('edit', $offering->id);
    $wish = AdmissionWish::factory()->for($application)->for($program)->create(['candidate_major_offering_id' => $offering->id]);
    $original = $wish->fresh()->getAttributes();

    $page->set('form.admission_program_id', $replacement->id)->call('save')->assertHasErrors('form.admission_program_id');
    expect($offering->fresh()->admission_program_id)->toBe($program->id);
    expect($wish->fresh()->getAttributes())->toBe($original);
    Livewire::test(OfferingEditor::class)->call('edit', $offering->id)->set('form.is_selectable', false)->call('save')->assertHasNoErrors();
    expect($wish->fresh()->getAttributes())->toBe($original);
    expect(fn () => $offering->fresh()->update(['admission_program_id' => $replacement->id]))->toThrow(QueryException::class);
    Livewire::test(OfferingEditor::class)->call('confirmDeletion', $offering->id)->call('delete')->assertHasErrors('deletion');
    $this->assertModelExists($offering);
});

test('offering management requires an active verified admin', function (UserRole $role, UserStatus $status, bool $verified) {
    $user = User::factory()->create(['role' => $role, 'status' => $status, 'email_verified_at' => $verified ? now() : null]);
    $offering = CandidateMajorOffering::factory()->create();
    $allowed = $role === UserRole::Admin && $status === UserStatus::Active && $verified;

    foreach (['viewAny', 'create'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, CandidateMajorOffering::class))->toBe($allowed);
    }
    foreach (['view', 'update', 'delete'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, $offering))->toBe($allowed);
    }
})->with(UserRole::cases())->with(UserStatus::cases())->with([true, false]);

test('staff and candidates cannot reach the offering management route or livewire actions', function (UserRole $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));

    $this->get(route('admin.candidate-major-offerings.index'))->assertForbidden();
    Livewire::test(OfferingEditor::class)->assertForbidden();
})->with([UserRole::Staff, UserRole::Candidate]);

test('legacy and offering backed wishes have identical phase seven outcomes and phase eight lifecycle', function () {
    $program = engineProgram(quota: 2);
    $legacy = engineApplication($program, '8.125');
    $modern = engineApplication($program, '8.125');
    $offering = CandidateMajorOffering::factory()->for($program)->create();
    $modernWish = $modern->wishes()->sole();
    $modernWish->update(['candidate_major_offering_id' => $offering->id]);
    $offering->update(['is_selectable' => false]);
    $program->update(['status' => 'inactive']);
    $program->major->update(['is_active' => false]);
    $program->admissionMethod->update(['is_active' => false]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin);

    engineRun($program);
    $oldResult = $legacy->wishes()->sole()->result;
    $newResult = $modernWish->fresh()->result;
    expect($oldResult->only(['final_score', 'rank', 'decision']))->toBe($newResult->only(['final_score', 'rank', 'decision']));
    expect($newResult->final_score)->toBe('8.125');
    expect($newResult->rank)->toBe(1);
    expect($newResult->decision->value)->toBe('admitted');
    app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    $this->actingAs($modern->candidateProfile->user);
    Livewire::test(Results::class)->assertSee($program->major->name)->assertDontSee($program->admissionMethod->name)
        ->call('confirm', $newResult->id)->assertHasNoErrors();
    expect($newResult->fresh()->confirmed_at)->not->toBeNull();
    expect(app(ConfirmAdmissionResult::class)->confirm($newResult->id)->id)->toBe($newResult->id);
    $this->actingAs($admin);
    app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    $this->assertDatabaseCount('notifications', 2);
    expect($modernWish->fresh()->admission_program_id)->toBe($program->id);
    expect($legacy->wishes()->sole()->candidate_major_offering_id)->toBeNull();
});

test('demo offerings are explicit open intake mappings and repeated seeding preserves existing settings', function () {
    $unrelated = CandidateMajorOffering::factory()->create();
    $original = $unrelated->fresh()->getAttributes();
    $this->seed(AdmissionDemoSeeder::class);
    $demo = CandidateMajorOffering::query()->whereKeyNot($unrelated->id)->orderBy('id')->get();
    expect($demo)->toHaveCount(6);
    $expected = [
        'DEMO-7480201' => 'DEMO-DGNL', 'DEMO-7480101' => 'DEMO-DGNL',
        'DEMO-7340101' => 'DEMO-THPT-A00', 'DEMO-7340201' => 'DEMO-HB-D01',
        'DEMO-7340301' => 'DEMO-THPT-A00', 'DEMO-7220201' => 'DEMO-HB-D01',
    ];
    foreach ($demo as $offering) {
        expect($offering->admissionRound->code)->toBe('DEMO-2026-D2');
        expect($offering->admissionProgram->admissionMethod->code)->toBe($expected[$offering->major->code]);
    }
    $demo->first()->update(['is_selectable' => false]);
    $before = CandidateMajorOffering::query()->orderBy('id')->get()->toArray();

    $this->seed(AdmissionDemoSeeder::class);

    expect(CandidateMajorOffering::query()->orderBy('id')->get()->toArray())->toBe($before);
    expect($unrelated->fresh()->getAttributes())->toBe($original);
});

test('new and legacy wishes reorder and delete together without changing pinned mappings', function () {
    $application = majorOfferingApplication();
    $legacy = AdmissionWish::factory()->for($application)->create();
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $offering = CandidateMajorOffering::factory()->for($program)->create();
    $modern = AdmissionWish::factory()->for($application)->for($program)->create([
        'candidate_major_offering_id' => $offering->id, 'priority' => 2,
    ]);
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id]);
    $stale = Livewire::test(ApplicationDetails::class, ['application' => $application->id]);

    $page->call('reorderWishes', [$modern->id, $legacy->id])->assertHasNoErrors();
    $stale->call('reorderWishes', [$legacy->id, $modern->id])->assertHasErrors('order');
    expect($modern->fresh()->only(['priority', 'candidate_major_offering_id', 'admission_program_id']))
        ->toBe(['priority' => 1, 'candidate_major_offering_id' => $offering->id, 'admission_program_id' => $program->id]);
    $page->call('confirmDeletion', $modern->id)->call('deleteWish')->assertHasNoErrors();
    $this->assertModelMissing($modern);
    expect($legacy->fresh()->priority)->toBe(1);
    expect($legacy->fresh()->candidate_major_offering_id)->toBeNull();
    $this->assertModelExists($offering);
});

test('result backed offering wishes retain delete and reorder protection', function () {
    $application = majorOfferingApplication();
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $offering = CandidateMajorOffering::factory()->for($program)->create();
    $wish = AdmissionWish::factory()->for($application)->for($program)->create(['candidate_major_offering_id' => $offering->id]);
    $other = AdmissionWish::factory()->for($application)->create(['priority' => 2]);
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmDeletion', $wish->id);
    $result = AdmissionResult::factory()->for($wish)->create();

    $page->call('deleteWish')->assertForbidden();
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->call('reorderWishes', [$other->id, $wish->id])->assertHasErrors('order');
    $this->assertModelExists($wish);
    $this->assertModelExists($result);
    expect($wish->fresh()->priority)->toBe(1);
    expect($wish->fresh()->admission_program_id)->toBe($program->id);
});

test('duplicate offering insertion race rolls back without overwriting a wish', function () {
    $application = majorOfferingApplication();
    $program = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $offering = CandidateMajorOffering::factory()->for($program)->create();
    $this->actingAs($application->candidateProfile->user);
    Event::listen('eloquent.creating: '.AdmissionWish::class, function (AdmissionWish $wish): void {
        DB::table('admission_wishes')->insert([
            'application_id' => $wish->application_id, 'admission_program_id' => $wish->admission_program_id,
            'candidate_major_offering_id' => $wish->candidate_major_offering_id, 'priority' => 2,
        ]);
    });

    try {
        Livewire::test(ApplicationDetails::class, ['application' => $application->id])
            ->set('form.candidate_major_offering_id', $offering->id)->call('addWish')->assertHasErrors('wishes');
        $this->assertDatabaseCount('admission_wishes', 0);
    } finally {
        Event::forget('eloquent.creating: '.AdmissionWish::class);
    }
});

test('database forbids duplicate round major offerings and mismatched wish pins', function () {
    $offering = CandidateMajorOffering::factory()->create();
    expect(fn () => CandidateMajorOffering::factory()->for($offering->admissionProgram)->create())->toThrow(QueryException::class);
    $wish = AdmissionWish::factory()->create();

    expect(fn () => $wish->update(['candidate_major_offering_id' => $offering->id]))->toThrow(QueryException::class);
    expect($wish->fresh()->candidate_major_offering_id)->toBeNull();
});
