<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.pwnedpasswords.com/range/6052A' => Http::response('', 200)]);
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    Http::assertSentCount(1);
});

test('registration ignores injected role and status values', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.pwnedpasswords.com/range/6052A' => Http::response('', 200)]);
    $this->post(route('register.store'), [
        'name' => 'Candidate',
        'email' => 'candidate@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
        'role' => UserRole::Admin->value,
        'status' => UserStatus::Locked->value,
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'candidate@example.com')->sole();
    expect($user->role)->toBe(UserRole::Candidate);
    expect($user->status)->toBe(UserStatus::Active);
    $this->assertAuthenticatedAs($user);
    Http::assertSentCount(1);
});

test('registration rejects a compromised password without creating a user', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.pwnedpasswords.com/range/6052A' => Http::response('CF657148EC39725C596E25BD0612FD301A6:1', 200)]);

    $this->post(route('register.store'), [
        'name' => 'Candidate', 'email' => 'candidate@example.com',
        'password' => 'StrongPass123!', 'password_confirmation' => 'StrongPass123!',
    ])->assertSessionHasErrors(['password' => __('validation.password.uncompromised')]);

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'candidate@example.com']);
    Http::assertSentCount(1);
});

test('registration retains password complexity and confirmation requirements', function (string $password, string $confirmation, string $message) {
    Http::preventStrayRequests();
    Http::fake(['https://api.pwnedpasswords.com/range/6052A' => Http::response('', 200)]);

    $this->post(route('register.store'), [
        'name' => 'Candidate', 'email' => 'candidate@example.com',
        'password' => $password, 'password_confirmation' => $confirmation,
    ])->assertSessionHasErrors(['password' => __($message, ['attribute' => 'password', 'min' => 12])]);

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'candidate@example.com']);
    if ($message === 'validation.confirmed') {
        Http::assertSentCount(1);
    } else {
        Http::assertNothingSent();
    }
})->with([
    'minimum length' => ['Short1!', 'Short1!', 'validation.min.string'],
    'lowercase' => ['STRONGPASS123!', 'STRONGPASS123!', 'validation.password.mixed'],
    'uppercase' => ['strongpass123!', 'strongpass123!', 'validation.password.mixed'],
    'letters' => ['123456789012!', '123456789012!', 'validation.password.letters'],
    'numbers' => ['StrongPassword!', 'StrongPassword!', 'validation.password.numbers'],
    'symbols' => ['StrongPass1234', 'StrongPass1234', 'validation.password.symbols'],
    'confirmation' => ['StrongPass123!', 'DifferentPass123!', 'validation.confirmed'],
]);
