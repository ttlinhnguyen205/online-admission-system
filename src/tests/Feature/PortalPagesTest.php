<?php

use App\Enums\UserRole;
use App\Models\User;

test('staff and admin navigation omit registration guidance', function (UserRole $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));

    $response = $this->get(route('news'));

    $response->assertDontSee('href="'.route('registration-guidance').'"', false);
})->with([UserRole::Admin, UserRole::Staff]);

test('candidate navigation retains registration guidance', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('news'));

    $response->assertSee('href="'.route('registration-guidance').'"', false);
});

test('news page contains school and admission news without registration guidance', function () {
    $response = $this->get(route('news'));

    $response->assertSeeText('Tin nhà trường')
        ->assertSeeText('Tin tuyển sinh')
        ->assertDontSeeText('Hoàn thiện hồ sơ cá nhân')
        ->assertSee('href="'.route('registration-guidance').'"', false);
});

test('registration guidance page contains the registration steps without news', function () {
    $response = $this->get(route('registration-guidance'));

    $response->assertSeeText('Hoàn thiện hồ sơ cá nhân')
        ->assertSeeText('Khai báo thông tin và minh chứng')
        ->assertSeeText('Sắp xếp nguyện vọng và nộp hồ sơ')
        ->assertDontSeeText('TIN NHÀ TRƯỜNG')
        ->assertDontSeeText('TIN TUYỂN SINH')
        ->assertSee('href="'.route('news').'"', false);
});
