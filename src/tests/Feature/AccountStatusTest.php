<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\Security;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

test('protected routes reject inactive and locked accounts for every role', function (UserRole $role, UserStatus $status, string $route) {
    $user = User::factory()->create(['role' => $role, 'status' => $status]);

    $this->actingAs($user)->get(route($route))->assertForbidden();
    $this->getJson(route($route))->assertForbidden()->assertJsonPath('message', 'Your account is not active.');
    $this->assertAuthenticatedAs($user);
})->with(UserRole::cases())->with([UserStatus::Inactive, UserStatus::Locked])->with(['dashboard', 'appearance.edit']);

test('active roles retain protected access', function (UserRole $role, string $route) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)->get(route($route))->assertOk();
})->with(UserRole::cases())->with(['dashboard', 'appearance.edit']);

test('protected routes retain guest and verification restrictions', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route($route))->assertRedirect(route('verification.notice'));
})->with(['dashboard', 'appearance.edit']);

test('restricted accounts can log in and log out without a redirect loop', function (UserStatus $status) {
    $user = User::factory()->create(['status' => $status]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertForbidden();
    $this->post(route('logout'))->assertRedirect(route('home'));
    $this->assertGuest();
})->with([UserStatus::Inactive, UserStatus::Locked]);

test('restricted accounts retain profile and security recovery paths', function (UserStatus $status) {
    $user = User::factory()->create(['status' => $status]);

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    $this->get(route('password.confirm'))->assertOk();
    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect();
    $this->get(route('security.edit'))->assertOk();

    Livewire::test(Profile::class)->set('name', 'Updated Name')->call('updateProfileInformation')->assertHasNoErrors();
    Livewire::test(Security::class)
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword')->assertHasNoErrors();

    expect($user->fresh()->status)->toBe($status);
    expect($user->fresh()->role)->toBe(UserRole::Candidate);
    expect($user->fresh()->name)->toBe('Updated Name');
    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
})->with([UserStatus::Inactive, UserStatus::Locked]);

test('restricted unverified accounts can verify their email', function (UserStatus $status) {
    Notification::fake();
    $user = User::factory()->unverified()->create(['status' => $status]);
    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    $this->get(route('verification.notice'))->assertOk();
    $this->post(route('verification.send'))->assertRedirect();
    Notification::assertSentTo($user, VerifyEmail::class);
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
        'id' => $user->id, 'hash' => sha1($user->email),
    ]);

    $this->get($url)->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    expect($user->fresh()->status)->toBe($status);
    $this->get(route('dashboard'))->assertForbidden();
})->with([UserStatus::Inactive, UserStatus::Locked]);

test('restricted accounts can reset passwords while logged out', function (UserStatus $status) {
    Notification::fake();
    $user = User::factory()->create(['status' => $status]);
    $this->get(route('password.request'))->assertOk();
    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();

    $notification = Notification::sent($user, ResetPassword::class)->sole();
    $this->get(route('password.reset', $notification->token))->assertOk();
    $this->post(route('password.update'), [
        'email' => $user->email, 'token' => $notification->token,
        'password' => 'new-password', 'password_confirmation' => 'new-password',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login', absolute: false));

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->status)->toBe($status);
})->with([UserStatus::Inactive, UserStatus::Locked]);

test('restricted accounts can complete a two factor challenge and manage two factor security', function (UserStatus $status) {
    $user = User::factory()->withTwoFactor()->create(['status' => $status]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
    $this->get(route('two-factor.login'))->assertOk();
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertForbidden();
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('two-factor.disable'))->assertOk();

    expect($user->fresh()->two_factor_secret)->toBeNull();
    expect($user->fresh()->status)->toBe($status);
})->with([UserStatus::Inactive, UserStatus::Locked]);

test('persistent middleware rejects real Livewire updates after account restriction', function (UserStatus $status) {
    $user = User::factory()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $page = $this->get(route('appearance.edit'))->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    $payload = ['components' => [[
        'snapshot' => $snapshot, 'updates' => [],
        'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
    ]]];

    $this->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])->assertOk();
    User::query()->whereKey($user->id)->update(['status' => $status]);
    Auth::forgetGuards();
    Livewire::flushState();

    $this->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])
        ->assertForbidden()->assertJsonPath('message', 'Your account is not active.');
})->with([UserStatus::Inactive, UserStatus::Locked]);

test('recovery Livewire requests remain accessible to restricted accounts', function () {
    $user = User::factory()->create(['status' => UserStatus::Locked]);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $page = $this->get(route('profile.edit'))->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);

    $this->postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => $snapshot, 'updates' => ['name' => 'Recovery Name'],
        'calls' => [['path' => '', 'method' => 'updateProfileInformation', 'params' => []]],
    ]]], ['X-Livewire' => 'true'])->assertOk();

    expect($user->fresh()->name)->toBe('Recovery Name');
    expect($user->fresh()->status)->toBe(UserStatus::Locked);
});
