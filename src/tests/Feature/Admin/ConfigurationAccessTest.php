<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Admin\AdmissionMethods;
use App\Livewire\Admin\AdmissionPrograms;
use App\Livewire\Admin\AdmissionRounds;
use App\Livewire\Admin\Majors;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Major;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

dataset('configuration pages', [
    ['admission-rounds', AdmissionRounds::class, AdmissionRound::class],
    ['majors', Majors::class, Major::class],
    ['admission-methods', AdmissionMethods::class, AdmissionMethod::class],
    ['admission-programs', AdmissionPrograms::class, AdmissionProgram::class],
]);

test('configuration routes require authentication verification and policy access', function (string $path) {
    $route = 'admin.'.$path.'.index';
    $this->get(route($route))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create(['role' => UserRole::Admin]))
        ->get(route($route))->assertRedirect(route('verification.notice'));
    $this->actingAs(User::factory()->create())->get(route($route))->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get(route($route))->assertOk();
})->with('configuration pages');

test('staff can read configuration but every mutation entry point remains forbidden', function (string $path, string $component, string $model) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $record = $model::factory()->create();
    $this->get(route('admin.'.$path.'.index'))->assertOk()->assertSee('Read-only access')->assertDontSee('Save configuration');
    Livewire::test($component)->call('details', $record->id)->assertSet('readOnly', true);
    Livewire::test($component)->call('create')->assertForbidden();
    Livewire::test($component)->call('edit', $record->id)->assertForbidden();
    Livewire::test($component)->call('save')->assertForbidden();
    Livewire::test($component)->call('confirmDeletion', $record->id)->assertForbidden();
    Livewire::test($component)->call('details', $record->id)->call('save')->assertForbidden();
    $this->assertModelExists($record);
})->with('configuration pages');

test('candidates cannot mount configuration Livewire components', function (string $path, string $component) {
    $this->actingAs(User::factory()->create());
    Livewire::test($component)->assertForbidden();
})->with('configuration pages');

test('inactive and locked admins cannot reach or mount configuration', function (string $path, string $component, string $model, UserStatus $status) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin, 'status' => $status]));
    $this->get(route('admin.'.$path.'.index'))->assertForbidden();
    Livewire::test($component)->assertForbidden();
})->with('configuration pages')->with([UserStatus::Inactive, UserStatus::Locked]);

test('record identifiers cannot be replaced in hydrated configuration state', function (string $path, string $component, string $model, string $property) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = $model::factory()->create();
    $other = $model::factory()->create();
    $page = Livewire::test($component)->call($property === 'recordId' ? 'edit' : 'confirmDeletion', $record->id);
    expect(fn () => $page->set($property, $other->id))->toThrow(CannotUpdateLockedPropertyException::class);
    $this->assertModelExists($record);
    $this->assertModelExists($other);
})->with('configuration pages')->with(['recordId', 'deleteId']);

test('missing configuration records return not found', function (string $path, string $component) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $page = $this->get(route('admin.'.$path.'.index'))->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    foreach (['edit', 'details', 'confirmDeletion'] as $action) {
        $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
            'updates' => [],
            'calls' => [['path' => '', 'method' => $action, 'params' => [999999]]],
        ]]], ['X-Livewire' => 'true'])->assertNotFound();
    }
})->with('configuration pages');

test('configuration mutations recheck admin privileges after the initial request', function (string $path, string $component, string $model, string $action, UserRole $role) {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin);
    $record = $model::factory()->create();
    $page = Livewire::test($component)->call($action === 'delete' ? 'confirmDeletion' : 'edit', $record->id);
    User::query()->whereKey($admin->id)->update(['role' => $role]);
    $page->call($action)->assertForbidden();
    $this->assertModelExists($record);
})->with('configuration pages')->with(['save', 'delete'])->with([UserRole::Staff, UserRole::Candidate]);

test('real Livewire requests reject an account restricted after the page loaded', function (string $path, string $component, string $model, UserStatus $status) {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password']);
    $page = $this->get(route('admin.'.$path.'.index'))->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    $payload = ['components' => [[
        'snapshot' => $snapshot, 'updates' => [],
        'calls' => [['path' => '', 'method' => 'create', 'params' => []]],
    ]]];
    User::query()->whereKey($admin->id)->update(['status' => $status]);
    Auth::forgetGuards();
    Livewire::flushState();
    $this->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])->assertForbidden();
})->with('configuration pages')->with([UserStatus::Inactive, UserStatus::Locked]);

test('configuration landing and navigation follow existing policies', function (UserRole $role, UserStatus $status, bool $allowed) {
    $this->actingAs(User::factory()->create(['role' => $role, 'status' => $status]));
    $response = $this->get(route('profile.edit'));
    if ($allowed) {
        $response->assertSee(route('admin.home'))->assertSee(route('admin.admission-programs.index'));
        $this->get(route('admin.home'))->assertOk()->assertSee('Admission configuration');
    } else {
        $response->assertDontSee(route('admin.home'));
        $this->get(route('admin.home'))->assertForbidden();
    }
})->with([
    [UserRole::Admin, UserStatus::Active, true],
    [UserRole::Staff, UserStatus::Active, true],
    [UserRole::Candidate, UserStatus::Active, false],
    [UserRole::Admin, UserStatus::Inactive, false],
    [UserRole::Admin, UserStatus::Locked, false],
]);

test('configuration content is escaped and SQL-like search remains a bound value', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Major::factory()->create(['name' => '<script>alert(1)</script>']);
    Livewire::test(Majors::class)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->set('search', "' OR 1=1 --")->assertViewHas('records', fn ($records) => $records->total() === 0);
});

test('foreign keys protect a dependency introduced after the deletion policy check', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = Major::factory()->create();
    Event::listen('eloquent.deleting: '.Major::class, function (Major $major): void {
        AdmissionProgram::factory()->for($major, 'major')->create();
    });
    try {
        Livewire::test(Majors::class)->call('confirmDeletion', $record->id)->call('delete')->assertHasErrors('deletion');
        $this->assertModelExists($record);
    } finally {
        Event::forget('eloquent.deleting: '.Major::class);
    }
});

test('unrelated database failures are not presented as dependency errors', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = Major::factory()->create();
    Event::listen('eloquent.deleting: '.Major::class, function (): void {
        DB::table('missing_configuration_table')->delete();
    });
    try {
        $page = Livewire::test(Majors::class)->call('confirmDeletion', $record->id);
        expect(fn () => $page->call('delete'))->toThrow(QueryException::class);
        $this->assertModelExists($record);
    } finally {
        Event::forget('eloquent.deleting: '.Major::class);
    }
});

test('a code collision after validation becomes a validation message', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Event::listen('eloquent.creating: '.Major::class, function (Major $major): void {
        DB::table('majors')->insert(['code' => $major->code, 'name' => 'Concurrent major']);
    });
    try {
        Livewire::test(Majors::class)->call('create')->set('form.code', 'RACE')->set('form.name', 'New major')
            ->call('save')->assertHasErrors('form.code')->assertSee('This code is already in use.');
    } finally {
        Event::forget('eloquent.creating: '.Major::class);
    }
});

test('a cancelled deletion does not report success', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $record = Major::factory()->create();
    Event::listen('eloquent.deleting: '.Major::class, fn () => false);
    try {
        Livewire::test(Majors::class)->call('confirmDeletion', $record->id)->call('delete')->assertHasErrors('deletion')->assertSee('This record could not be deleted.');
        $this->assertModelExists($record);
    } finally {
        Event::forget('eloquent.deleting: '.Major::class);
    }
});
