<x-layouts::auth.admission :title="__('Đăng ký')">
    <div class="flex flex-col gap-4">
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-4">
            @csrf

            <flux:input
                name="name"
                :aria-label="__('Name')"
                :value="old('name')"
                type="text"
                icon="user"
                icon:variant="outline"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Họ và tên')"
            />
            <flux:error name="name" />

            <flux:input
                name="email"
                :aria-label="__('Email address')"
                :value="old('email')"
                type="email"
                icon="envelope"
                icon:variant="outline"
                required
                autocomplete="email"
                :placeholder="__('Địa chỉ email')"
            />
            <flux:error name="email" />

            <flux:input
                name="password"
                :aria-label="__('Password')"
                type="password"
                icon="key"
                icon:variant="outline"
                required
                autocomplete="new-password"
                :placeholder="__('Mật khẩu')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />
            <flux:error name="password" />

            <flux:input
                name="password_confirmation"
                :aria-label="__('Confirm password')"
                type="password"
                icon="key"
                icon:variant="outline"
                required
                autocomplete="new-password"
                :placeholder="__('Xác nhận mật khẩu')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />
            <flux:error name="password_confirmation" />

            <flux:button type="submit" variant="primary" class="auth-primary w-full" data-test="register-user-button">
                {{ __('Đăng ký tài khoản') }}
            </flux:button>
        </form>

        <div class="flex items-center gap-3 py-1 text-sm text-[#66738b]">
            <span class="h-px flex-1 bg-[#c4cee0]" aria-hidden="true"></span>
            <span>{{ __('Đã có tài khoản?') }}</span>
            <span class="h-px flex-1 bg-[#c4cee0]" aria-hidden="true"></span>
        </div>

        <flux:button class="auth-secondary w-full" :href="route('login')" wire:navigate>
            {{ __('Đăng nhập') }}
        </flux:button>
    </div>
</x-layouts::auth.admission>
