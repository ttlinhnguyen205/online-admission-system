<?php

use App\Enums\UserRole;
use App\Livewire\Settings\Language;
use App\Models\AdmissionRound;
use App\Models\User;
use Livewire\Livewire;

test('login and authentication errors use Vietnamese even with an old English session', function () {
    $this->withSession(['locale' => 'en'])->get(route('login'))
        ->assertOk()->assertSee('lang="vi"', false)->assertSeeText('Đăng nhập');

    $this->post(route('login.store'), ['email' => 'unknown@example.com', 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => 'Email hoặc mật khẩu không chính xác.']);
});

test('language settings keep Vietnamese and reject an unsupported selection', function () {
    $this->actingAs(User::factory()->create());
    $this->withSession(['locale' => 'en'])->get(route('language.edit'))
        ->assertOk()->assertSeeText('Tiếng Việt')->assertDontSeeText('English');

    Livewire::test(Language::class)->assertSet('locale', 'vi')
        ->set('locale', 'en')->call('updateLanguage')->assertHasErrors(['locale' => 'in']);
});

test('round dates remain visible without showing the internal timezone name', function () {
    $round = AdmissionRound::factory()->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $this->get(route('admin.admission-rounds.index'))->assertOk()
        ->assertSee($round->end_date->format('d/m/Y H:i:s'))
        ->assertDontSee('Asia/Ho_Chi_Minh');

    expect(config('app.timezone'))->toBe('Asia/Ho_Chi_Minh');
});
