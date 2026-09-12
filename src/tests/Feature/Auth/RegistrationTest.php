<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('registration ignores injected role and status values', function () {
    $this->post(route('register.store'), [
        'name' => 'Candidate',
        'email' => 'candidate@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => UserRole::Admin->value,
        'status' => UserStatus::Locked->value,
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'candidate@example.com')->sole();
    expect($user->role)->toBe(UserRole::Candidate);
    expect($user->status)->toBe(UserStatus::Active);
    $this->assertAuthenticatedAs($user);
});
