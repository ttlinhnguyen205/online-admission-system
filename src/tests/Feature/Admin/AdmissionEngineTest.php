<?php

use App\Actions\ProcessAdmissionRound;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Admin\AdmissionEngine;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../AdmissionEngineFixtures.php';

test('guests are redirected from the engine and denied direct actions', function () {
    $program = engineProgram();

    $this->get(route('admin.admission-engine'))->assertRedirect(route('login'));
    expect(fn () => app(ProcessAdmissionRound::class)->preview($program->admission_round_id))->toThrow(HttpException::class);
    expect(fn () => app(ProcessAdmissionRound::class)->process($program->admission_round_id, ''))->toThrow(HttpException::class);
    Livewire::test(AdmissionEngine::class)->assertForbidden();
});

test('candidate and staff cannot access engine route component or direct actions', function (UserRole $role) {
    $program = engineProgram();
    $this->actingAs(User::factory()->create(['role' => $role]));

    $this->get(route('admin.admission-engine'))->assertForbidden();
    Livewire::test(AdmissionEngine::class)->assertForbidden();
    expect(fn () => app(ProcessAdmissionRound::class)->preview($program->admission_round_id))->toThrow(HttpException::class);
    expect(fn () => app(ProcessAdmissionRound::class)->process($program->admission_round_id, ''))->toThrow(HttpException::class);
    $this->assertDatabaseCount('admission_results', 0);
})->with([UserRole::Candidate, UserRole::Staff]);

test('inactive and locked admins are denied route component and actions', function (UserStatus $status) {
    $program = engineProgram();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin, 'status' => $status]));

    $this->get(route('admin.admission-engine'))->assertForbidden();
    Livewire::test(AdmissionEngine::class)->assertForbidden();
    expect(fn () => app(ProcessAdmissionRound::class)->process($program->admission_round_id, ''))->toThrow(HttpException::class);
})->with([UserStatus::Inactive, UserStatus::Locked]);

test('unverified admin must verify before entering the engine', function () {
    $this->actingAs(User::factory()->unverified()->create(['role' => UserRole::Admin]));

    $this->get(route('admin.admission-engine'))->assertRedirect(route('verification.notice'));
    Livewire::test(AdmissionEngine::class)->assertForbidden();
});

test('the processing policy enforces the complete active verified admin matrix', function (UserRole $role, UserStatus $status, bool $verified) {
    $user = User::factory()->create(['role' => $role, 'status' => $status, 'email_verified_at' => $verified ? now() : null]);

    expect(Gate::forUser($user)->allows('process', AdmissionRound::class))
        ->toBe($role === UserRole::Admin && $status === UserStatus::Active && $verified);
})->with(UserRole::cases())->with(UserStatus::cases())->with([true, false]);

test('admin selects previews confirms and processes with a safe unpublished summary', function () {
    $program = engineProgram();
    engineApplication($program);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $this->get(route('admin.admission-engine'))->assertOk()->assertSee('Preview readiness');
    $component = Livewire::test(AdmissionEngine::class)
        ->set('roundSelection', (string) $program->admission_round_id)
        ->call('preview')->assertHasNoErrors()
        ->assertSet('previewData.verified', 1)->assertSet('previewData.wishes', 1)
        ->assertSee('Ready for confirmation')->assertSee('DEMO-DGNL')->assertSee('Scalar')
        ->call('confirm')->assertSet('showConfirmation', true)
        ->call('process')->assertHasNoErrors()
        ->assertSet('previewData.completed', true)->assertSet('showConfirmation', false)
        ->assertSee('Saved admission summary')->assertSee('Results remain unpublished and unconfirmed');

    expect($component->get('summary')['admitted'])->toBe(1);
    $this->assertDatabaseCount('admission_results', 1);
    $this->assertDatabaseCount('activity_logs', 2);
});

test('preview displays unresolved counts and prevents confirmation', function () {
    $program = engineProgram();
    engineApplication($program);
    Application::factory()->create(['admission_round_id' => $program->admission_round_id, 'status' => 'submitted']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test(AdmissionEngine::class)->set('roundSelection', (string) $program->admission_round_id)
        ->call('preview')->assertSet('previewData.submitted', 1)
        ->assertSee('Unresolved submitted applications: 1.')->assertSee('Processing blocked')
        ->call('confirm')->assertHasErrors('engine');

    $this->assertDatabaseCount('admission_results', 0);
});

test('unsupported methods and historical states are visible in readiness', function () {
    $program = engineProgram(code: 'DEMO-THANG');
    engineApplication($program);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test(AdmissionEngine::class)->set('roundSelection', (string) $program->admission_round_id)
        ->call('preview')->assertSee('Unsupported')->assertSee('Unsupported calculation contract')
        ->assertSet('previewData.existing_results', 0);
});

test('processing requires preview and explicit confirmation', function () {
    $program = engineProgram();
    engineApplication($program);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test(AdmissionEngine::class)->call('process')->assertForbidden();
    Livewire::test(AdmissionEngine::class)->set('roundSelection', (string) $program->admission_round_id)
        ->call('preview')->call('process')->assertForbidden();

    $this->assertDatabaseCount('admission_results', 0);
});

test('role account status and email changes after preview deny processing at every entry point', function (array $changes) {
    $program = engineProgram();
    engineApplication($program);
    $this->actingAs($actor = User::factory()->create(['role' => UserRole::Admin]));
    $component = Livewire::test(AdmissionEngine::class)->set('roundSelection', (string) $program->admission_round_id)->call('preview')->call('confirm');
    $token = $component->get('previewData')['fingerprint'];
    User::query()->whereKey($actor->id)->update($changes);

    $component->call('process')->assertForbidden();
    expect(fn () => app(ProcessAdmissionRound::class)->process($program->admission_round_id, $token))->toThrow(HttpException::class);

    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('activity_logs', 0);
})->with([
    [['role' => 'staff']], [['role' => 'candidate']], [['status' => 'inactive']], [['status' => 'locked']], [['email_verified_at' => null]],
]);

test('stale Livewire preview cannot process changed input until previewed and confirmed again', function () {
    $program = engineProgram();
    engineApplication($program);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $component = Livewire::test(AdmissionEngine::class)->set('roundSelection', (string) $program->admission_round_id)->call('preview')->call('confirm');
    $program->update(['quota' => 0]);

    $component->call('process')->assertHasErrors('engine')->assertSee('inputs changed');

    $this->assertDatabaseCount('admission_results', 0);
    $component->call('preview')->call('confirm')->call('process')->assertHasNoErrors()->assertSet('summary.admitted', 0);
    $this->assertDatabaseHas('admission_results', ['decision' => 'not_admitted']);
});

test('injected decision fields and timestamps cannot control server processing', function (string $field, mixed $value) {
    $program = engineProgram();
    engineApplication($program);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test(AdmissionEngine::class)->set('roundSelection', (string) $program->admission_round_id)
        ->call('preview')->call('confirm')->set('form.'.$field, $value)
        ->call('process')->assertHasErrors('engine');

    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('activity_logs', 0);
})->with([
    ['calculated_score', '999.000'], ['final_score', '999.000'], ['rank', 1], ['decision', 'admitted'],
    ['published_at', '2026-01-01'], ['confirmed_at', '2026-01-01'], ['decided_at', '2000-01-01'],
    ['application_id', 999], ['admission_round_id', 999], ['status', 'completed'],
]);

test('replacing selected round after confirmation does not redirect the processing target', function () {
    $program = engineProgram();
    $foreign = engineProgram();
    engineApplication($program);
    engineApplication($foreign);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test(AdmissionEngine::class)->set('roundSelection', (string) $program->admission_round_id)
        ->call('preview')->call('confirm')->set('roundSelection', (string) $foreign->admission_round_id)
        ->call('process')->assertHasErrors('engine');

    $this->assertDatabaseCount('admission_results', 0);
});

test('authoritative preview and confirmation properties reject client replacement', function (string $property, mixed $value) {
    $program = engineProgram();
    engineApplication($program);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $component = Livewire::test(AdmissionEngine::class)->set('roundSelection', (string) $program->admission_round_id)->call('preview');

    expect(fn () => $component->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);

    $this->assertDatabaseCount('admission_results', 0);
})->with([
    ['roundId', 999], ['previewData.fingerprint', 'forged'], ['previewData.blockers', []], ['confirmed', true], ['summary', ['admitted' => 999]],
]);

test('round selection validates missing malformed and nonexistent IDs', function (string $selection) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test(AdmissionEngine::class)->set('roundSelection', $selection)->call('preview')->assertHasErrors('roundSelection');
})->with(['', 'abc', '0', '-1']);

test('nonexistent rounds do not reveal or process other records', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    expect(fn () => Livewire::test(AdmissionEngine::class)->set('roundSelection', '999')->call('preview'))
        ->toThrow(ModelNotFoundException::class);

    $this->assertDatabaseCount('admission_results', 0);
});

test('round names are escaped and private profile data is absent from engine state', function () {
    $program = engineProgram();
    $program->admissionRound->update(['name' => '<script>alert(1)</script>']);
    $application = engineApplication($program);
    $application->candidateProfile->update(['citizen_id' => '123456789123', 'phone' => '0901234567', 'address' => 'PRIVATE ADDRESS']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $component = Livewire::test(AdmissionEngine::class)->assertSee('<script>alert(1)</script>')->assertDontSeeHtml('<script>alert(1)</script>')
        ->set('roundSelection', (string) $program->admission_round_id)->call('preview');

    expect(json_encode($component->get('previewData')))->not->toContain('123456789123', '0901234567', 'PRIVATE ADDRESS', 'citizen_id');
});

test('a completed engine page reads existing results without a second completion event', function () {
    $program = engineProgram();
    engineApplication($program);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    engineRun($program);

    Livewire::test(AdmissionEngine::class)->set('roundSelection', (string) $program->admission_round_id)
        ->call('preview')->assertSet('previewData.completed', true)->assertSet('previewData.existing_results', 1)
        ->assertSee('completed immutable run')->assertSee('Saved admission summary')
        ->call('confirm')->assertForbidden();

    $this->assertDatabaseCount('admission_results', 1);
    $this->assertDatabaseCount('activity_logs', 2);
});
