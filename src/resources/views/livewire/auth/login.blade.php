<x-layouts::auth.admission :title="__('Đăng nhập')">
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form method="POST" action="{{ route('login.store') }}" class="auth-account-form">
        @csrf

        <flux:input
            name="email"
            label="Email"
            :value="old('email')"
            type="email"
            required
            autofocus
            autocomplete="email"
            :placeholder="__('Nhập email...')"
        />

        <flux:input
            name="password"
            :label="__('Mật khẩu')"
            type="password"
            required
            autocomplete="current-password"
            :placeholder="__('Nhập mật khẩu...')"
        />

        <div class="auth-login-options">
            <flux:checkbox name="remember" :label="__('Ghi nhớ đăng nhập')" :checked="old('remember')" />
            @if (Route::has('password.request'))
                <a class="auth-link" href="{{ route('password.request') }}" wire:navigate>{{ __('Quên mật khẩu?') }}</a>
            @endif
        </div>

        <div class="auth-submit-row">
            <flux:button variant="primary" type="submit" class="auth-submit" data-test="login-button">
                {{ __('Đăng nhập') }}
            </flux:button>
        </div>
    </form>

    @if (Route::has('register'))
        <p class="auth-switch-account">
            {{ __('Chưa có tài khoản?') }} <a href="{{ route('register') }}" wire:navigate>{{ __('Đăng ký') }}</a>
        </p>
    @endif
</x-layouts::auth.admission>
