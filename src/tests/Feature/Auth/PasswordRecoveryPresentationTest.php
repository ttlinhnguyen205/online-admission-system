<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

test('recovery pages share the admission layout and correct form destinations', function () {
    $this->get(route('password.request'))->assertOk()
        ->assertSee('admission-auth-page')->assertSee('auth-form-panel')
        ->assertSee('Nhập địa chỉ email đã đăng ký để nhận liên kết đặt lại mật khẩu.')
        ->assertSee('Gửi liên kết đặt lại mật khẩu')
        ->assertSee('Đang gửi yêu cầu…')->assertSee('Quay lại đăng nhập')
        ->assertSee('action="'.route('password.email').'"', false)
        ->assertSee('href="'.route('login').'"', false)
        ->assertDontSee('Quên mật khẩu tài khoản của bạn');

    $email = str_repeat('candidate', 6).'@example.com';
    $this->get(route('password.reset', ['token' => 'presentation-only', 'email' => $email]))
        ->assertOk()->assertSee('admission-auth-page')->assertSee('auth-form-panel')
        ->assertSee('Mật khẩu mới')->assertSee('Xác nhận mật khẩu mới')
        ->assertSee('Mật khẩu cần ít nhất 12 ký tự')
        ->assertSee('value="'.$email.'"', false)
        ->assertSee('action="'.route('password.update').'"', false)
        ->assertSee('value="presentation-only"', false)
        ->assertSee('Quay lại đăng nhập');

    $this->get(route('login'))->assertOk()->assertSee('Đăng nhập tài khoản của bạn')
        ->assertSee('auth-hero-message')->assertSee('data-test="login-button"', false);
});

test('known unknown and throttled emails receive the same neutral browser feedback', function () {
    Notification::fake();
    $user = User::factory()->create();
    $message = 'Nếu email đã được đăng ký, bạn sẽ nhận được liên kết đặt lại mật khẩu. Vui lòng kiểm tra hộp thư và thư rác; nếu chưa nhận được, hãy chờ vài phút trước khi thử lại.';

    foreach ([$user->email, 'unknown@example.com', $user->email] as $email) {
        $this->from(route('password.request'))->post(route('password.email'), ['email' => $email])
            ->assertRedirect(route('password.request'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', $message);
        $this->get(route('password.request'))->assertSee($message)
            ->assertDontSee(__('passwords.user'))->assertSee('role="status"', false);
    }

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    Notification::assertCount(1);
});

test('json reset link feedback also does not disclose account existence', function () {
    Notification::fake();
    $user = User::factory()->create();
    $known = $this->postJson(route('password.email'), ['email' => $user->email])->assertOk()->json();
    $unknown = $this->postJson(route('password.email'), ['email' => 'unknown@example.com'])->assertOk()->json();
    $throttled = $this->postJson(route('password.email'), ['email' => $user->email])->assertOk()->json();

    expect($unknown)->toBe($known);
    expect($throttled)->toBe($known);
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    Notification::assertCount(1);
});

test('invalid email displays its validation message without sending mail', function () {
    Notification::fake();
    $this->from(route('password.request'))->post(route('password.email'), ['email' => 'invalid-email'])
        ->assertSessionHasErrors('email');
    $message = session('errors')->first('email');

    $this->withSession(['errors' => ['default' => ['format' => ':message', 'messages' => ['email' => [$message]]]], '_old_input' => ['email' => 'invalid-email']])->get(route('password.request'))->assertSee($message)->assertSee('value="invalid-email"', false);
    Notification::assertNothingSent();
});

test('invalid or expired reset links show the explanation and a new link action', function (bool $expired) {
    Notification::fake();
    Http::preventStrayRequests();
    Http::fake(['https://api.pwnedpasswords.com/range/6052A' => Http::response('', 200)]);
    $user = User::factory()->create();
    $original = $user->password;
    $this->post(route('password.email'), ['email' => $user->email]);
    $notification = Notification::sent($user, ResetPassword::class)->sole();
    $token = $expired ? $notification->token : 'invalid-token';
    if ($expired) {
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
    }
    $url = route('password.reset', ['token' => $token, 'email' => $user->email]);

    $this->from($url)->post(route('password.update'), [
        'token' => $token, 'email' => $user->email,
        'password' => 'StrongPass123!', 'password_confirmation' => 'StrongPass123!',
    ])->assertRedirect($url)->assertSessionHasErrors(['email' => __('passwords.token')]);
    $this->withSession(['errors' => ['default' => ['format' => ':message', 'messages' => ['email' => [__('passwords.token')]]]]])->get($url)->assertSee(__('passwords.token'))
        ->assertSee('Yêu cầu liên kết đặt lại mật khẩu mới')
        ->assertSee('href="'.route('password.request').'"', false)
        ->assertDontSee('value="StrongPass123!"', false);

    expect($user->fresh()->password)->toBe($original);
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
})->with(['invalid' => false, 'expired' => true]);

test('reset validation retains email and renders long error messages safely', function () {
    $email = str_repeat('candidate', 6).'@example.com';
    $message = str_repeat('Mật khẩu không hợp lệ. ', 15).'<script>alert(1)</script>';
    $this->withSession(['_old_input' => ['email' => $email]])
        ->withSession(['errors' => ['default' => ['format' => ':message', 'messages' => ['password' => [$message]]]]])
        ->get(route('password.reset', ['token' => 'presentation-only']))
        ->assertOk()->assertSee($message)->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('value="'.$email.'"', false);
});
