<x-layouts::auth.admission :title="__('Đặt lại mật khẩu')" :recovery="true">
    <p class="mb-6 text-center text-sm leading-6 text-zinc-600">
        {{ __('Tạo mật khẩu mới để tiếp tục truy cập tài khoản tuyển sinh của bạn.') }}
    </p>

    <x-auth-session-status class="mb-6 text-center break-words" role="status" :status="session('status')" />

    <form method="POST" action="{{ route('password.update') }}" class="auth-account-form"
        x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault() } else { submitting = true }"
        x-on:pageshow.window="submitting = false" x-bind:aria-busy="submitting">
        @csrf
        <input type="hidden" name="token" value="{{ request()->route('token') }}">

        <flux:input
            name="email"
            :value="old('email', request('email'))"
            label="Email"
            type="email"
            required
            autocomplete="email"
            placeholder="email@example.com"
        />

        @if ($errors->has('token') || $errors->first('email') === __('passwords.token'))
            <div role="alert" class="rounded-xl bg-rose-50 p-4 text-sm leading-6 text-rose-700">
                @error('token') <p>{{ $message }}</p> @enderror
                <a class="auth-link underline" href="{{ route('password.request') }}" wire:navigate>{{ __('Yêu cầu liên kết đặt lại mật khẩu mới') }}</a>
            </div>
        @endif

        <flux:input
            name="password"
            :label="__('Mật khẩu mới')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('Nhập mật khẩu mới...')"
            aria-describedby="password-requirements"
            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
            viewable
        />
        <p id="password-requirements" class="text-sm leading-6 text-zinc-600">
            {{ __('Mật khẩu cần ít nhất 12 ký tự, gồm chữ hoa, chữ thường, số và ký tự đặc biệt; không sử dụng mật khẩu đã bị lộ.') }}
        </p>

        <flux:input
            name="password_confirmation"
            :label="__('Xác nhận mật khẩu mới')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('Nhập lại mật khẩu mới...')"
            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
            viewable
        />

        <div class="auth-submit-row">
            <flux:button type="submit" variant="primary" class="auth-submit w-full" x-bind:disabled="submitting" data-test="reset-password-button">
                <span x-text="submitting ? 'Đang đặt lại mật khẩu…' : 'Đặt lại mật khẩu'">{{ __('Đặt lại mật khẩu') }}</span>
            </flux:button>
        </div>
    </form>

    <p class="auth-switch-account">
        <a href="{{ route('login') }}" wire:navigate>{{ __('Quay lại đăng nhập') }}</a>
    </p>
</x-layouts::auth.admission>
