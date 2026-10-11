<?php

test('news page contains school and admission news without registration guidance', function () {
    $response = $this->get(route('news'));

    $response->assertSeeText('TIN NHÀ TRƯỜNG')
        ->assertSeeText('TIN TUYỂN SINH')
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
