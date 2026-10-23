<?php

test('opening the system redirects guests to the login screen', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('login'));
    $this->followingRedirects()->get(route('home'))->assertOk()->assertSee('Đăng nhập');
});
