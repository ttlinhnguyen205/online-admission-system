<x-layouts::auth.admission :title="__('Đăng ký')">
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form method="POST" action="{{ route('register.store') }}" class="auth-account-form">
        @csrf

        <flux:input
            name="name"
            :label="__('Họ tên')"
            :value="old('name')"
            type="text"
            required
            autofocus
            autocomplete="name"
            :placeholder="__('Nhập tên của bạn ở đây')"
        />

        <flux:input
            name="email"
            label="Email"
            :value="old('email')"
            type="email"
            required
            autocomplete="email"
            :placeholder="__('Nhập email...')"
        />

        <flux:input
            name="password"
            :label="__('Mật khẩu')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('Nhập mật khẩu...')"
            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
        />

        <flux:input
            name="password_confirmation"
            :label="__('Xác nhận mật khẩu')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('Nhập lại mật khẩu...')"
            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
        />

        <div class="auth-submit-row">
            <flux:button type="submit" variant="primary" class="auth-submit" data-test="register-user-button">
                {{ __('Tạo tài khoản') }}
            </flux:button>
        </div>
    </form>

    <p class="auth-switch-account">
        {{ __('Đã có tài khoản?') }} <a href="{{ route('login') }}" wire:navigate>{{ __('Đăng nhập') }}</a>
    </p>
</x-layouts::auth.admission>
