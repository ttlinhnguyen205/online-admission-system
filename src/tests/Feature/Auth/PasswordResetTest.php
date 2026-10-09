<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.pwnedpasswords.com/range/6052A' => Http::response('', 200)]);
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        return true;
    });
    expect(Hash::check('StrongPass123!', $user->fresh()->password))->toBeTrue();
    Http::assertSentCount(1);
});

test('password reset rejects a compromised password without consuming the token', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.pwnedpasswords.com/range/6052A' => Http::response('CF657148EC39725C596E25BD0612FD301A6:1', 200)]);
    Notification::fake();
    $user = User::factory()->create();
    $originalPassword = $user->password;
    $this->post(route('password.request'), ['email' => $user->email]);
    $notification = Notification::sent($user, ResetPassword::class)->sole();

    $this->post(route('password.update'), [
        'token' => $notification->token, 'email' => $user->email,
        'password' => 'StrongPass123!', 'password_confirmation' => 'StrongPass123!',
    ])->assertSessionHasErrors(['password' => __('validation.password.uncompromised')]);

    expect($user->fresh()->password)->toBe($originalPassword);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    $this->assertGuest();
    Http::assertSentCount(1);
});
