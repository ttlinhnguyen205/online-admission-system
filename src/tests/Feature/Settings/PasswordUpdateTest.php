<?php

use App\Enums\UserRole;
use App\Livewire\Settings\Security;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('each role changes only its password and logs in with the new password', function (UserRole $role) {
    Http::preventStrayRequests();
    Http::fake(['https://api.pwnedpasswords.com/range/6052A' => Http::response('', 200)]);
    $user = User::factory()->create(['role' => $role]);
    $other = User::factory()->create();
    $original = $user->getRawOriginal();
    $otherOriginal = $other->getRawOriginal();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))->assertOk()->assertSeeLivewire(Security::class);

    Livewire::test(Security::class)
        ->set('current_password', 'password')
        ->set('password', 'StrongPass123!')
        ->set('password_confirmation', 'StrongPass123!')
        ->call('updatePassword')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['dataset']['variant'] === 'success')
        ->assertSet('current_password', '')
        ->assertSet('password', '')
        ->assertSet('password_confirmation', '');

    expect(Hash::check('StrongPass123!', $user->fresh()->password))->toBeTrue();
    expect(Hash::check('password', $user->fresh()->password))->toBeFalse();
    expect(collect($user->fresh()->getRawOriginal())->except(['password', 'updated_at'])->all())
        ->toEqual(collect($original)->except(['password', 'updated_at'])->all());
    expect($other->fresh()->getRawOriginal())->toEqual($otherOriginal);
    expect($user->fresh()->toArray())->not->toHaveKey('password');

    $this->post(route('logout'));
    Auth::forgetGuards();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'StrongPass123!'])
        ->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    Http::assertSentCount(1);
})->with(UserRole::cases());

test('invalid password changes preserve the database without success', function (string $current, string $password, string $confirmation, string $field) {
    Http::preventStrayRequests();
    Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
    $user = User::factory()->create();
    $original = $user->getRawOriginal();

    Livewire::actingAs($user)->test(Security::class)
        ->set('current_password', $current)
        ->set('password', $password)
        ->set('password_confirmation', $confirmation)
        ->call('updatePassword')
        ->assertHasErrors([$field])
        ->assertNotDispatched('toast-show')
        ->assertSet('current_password', '')
        ->assertSet('password', '')
        ->assertSet('password_confirmation', '');

    expect($user->fresh()->getRawOriginal())->toEqual($original);
})->with([
    'wrong current password' => ['wrong', 'StrongPass123!', 'StrongPass123!', 'current_password'],
    'confirmation mismatch' => ['password', 'StrongPass123!', 'Different123!', 'password'],
    'too short' => ['password', 'Ab1!', 'Ab1!', 'password'],
    'no mixed case' => ['password', 'strongpass123!', 'strongpass123!', 'password'],
    'no numbers' => ['password', 'StrongPassword!', 'StrongPassword!', 'password'],
    'no symbols' => ['password', 'StrongPass123', 'StrongPass123', 'password'],
    'empty' => ['', '', '', 'current_password'],
]);

test('failed persistence shows a safe error without success', function (string $failure) {
    Http::preventStrayRequests();
    Http::fake(['https://api.pwnedpasswords.com/range/6052A' => Http::response('', 200)]);
    $user = User::factory()->create();
    $original = $user->getRawOriginal();
    $component = Livewire::actingAs($user)->test(Security::class);

    if ($failure === 'cancelled') {
        User::updating(fn (): bool => false);
    } else {
        DB::unprepared("CREATE TRIGGER reject_password_update BEFORE UPDATE OF password ON users BEGIN SELECT RAISE(ABORT, 'Simulated storage failure'); END");
    }

    try {
        $component->set('current_password', 'password')
            ->set('password', 'StrongPass123!')
            ->set('password_confirmation', 'StrongPass123!')
            ->call('updatePassword')
            ->assertHasErrors(['passwordUpdate'])
            ->assertSee('Không thể lưu mật khẩu mới. Vui lòng thử lại.')
            ->assertNotDispatched('toast-show')
            ->assertSet('current_password', '')
            ->assertSet('password', '')
            ->assertSet('password_confirmation', '')
            ->assertDontSee('Simulated storage failure');

        expect($user->fresh()->getRawOriginal())->toEqual($original);
    } finally {
        User::flushEventListeners();
    }
    Http::assertSentCount(1);
})->with(['cancelled', 'database exception']);

test('guests cannot open password settings', function () {
    $this->get(route('security.edit'))->assertRedirect(route('login'));
});
