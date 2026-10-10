<x-layouts::auth.admission :title="__('Đăng nhập')">
    <div class="flex flex-col gap-4">
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4">
            @csrf

            <flux:input
                name="email"
                :aria-label="__('Email address')"
                :value="old('email')"
                type="email"
                icon="user"
                icon:variant="outline"
                required
                autofocus
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
                autocomplete="current-password"
                :placeholder="__('Mật khẩu')"
                viewable
            />
            <flux:error name="password" />

            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                @if (Route::has('password.request'))
                    <a class="auth-link" href="{{ route('password.request') }}" wire:navigate>{{ __('Quên mật khẩu') }}</a>
                @endif

                <details class="group relative ms-auto">
                    <summary class="auth-link flex cursor-pointer list-none items-center gap-1 [&::-webkit-details-marker]:hidden">
                        <flux:icon.question-mark-circle class="size-4" />
                        {{ __('Trợ giúp!') }}
                    </summary>
                    <p class="mt-3 max-w-xs rounded-lg border border-blue-200 bg-white/70 p-3 text-sm leading-6 text-[#153c85]">{{ __('Dùng email và mật khẩu đã đăng ký. Nếu bạn chưa có tài khoản, chọn Đăng ký tài khoản bên dưới.') }}</p>
                </details>
            </div>

            <flux:checkbox name="remember" :label="__('Ghi nhớ đăng nhập')" :checked="old('remember')" />

            <flux:button variant="primary" type="submit" class="auth-primary w-full" data-test="login-button">
                {{ __('Đăng nhập') }}
            </flux:button>
        </form>

        @if (Route::has('register'))
            <div class="flex items-center gap-3 py-1 text-sm text-[#66738b]">
                <span class="h-px flex-1 bg-[#c4cee0]" aria-hidden="true"></span>
                <span>{{ __('Chưa có tài khoản?') }}</span>
                <span class="h-px flex-1 bg-[#c4cee0]" aria-hidden="true"></span>
            </div>

            <flux:button class="auth-secondary w-full" :href="route('register')" wire:navigate>
                {{ __('Đăng ký tài khoản') }}
            </flux:button>
        @endif
    </div>
</x-layouts::auth.admission>
