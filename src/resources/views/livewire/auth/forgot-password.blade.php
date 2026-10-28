<x-layouts::auth.admission :title="__('Quên mật khẩu')" :recovery="true">
    <p class="mb-6 text-center text-sm leading-6 text-zinc-600">
        {{ __('Nhập địa chỉ email đã đăng ký để nhận liên kết đặt lại mật khẩu.') }}
    </p>

    <x-auth-session-status class="mb-6 rounded-xl bg-rose-50 p-4 text-center break-words" role="status" aria-live="polite" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="auth-account-form"
        x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault() } else { submitting = true }"
        x-on:pageshow.window="submitting = false" x-bind:aria-busy="submitting">
        @csrf

        <flux:input
            name="email"
            label="Email"
            :value="old('email')"
            type="email"
            required
            autofocus
            autocomplete="email"
            placeholder="email@example.com"
        />

        <div class="auth-submit-row">
            <flux:button variant="primary" type="submit" class="auth-submit w-full whitespace-normal! h-auto! min-h-9 py-2!" x-bind:disabled="submitting" data-test="email-password-reset-link-button">
                <span x-text="submitting ? 'Đang gửi yêu cầu…' : 'Gửi liên kết đặt lại mật khẩu'">{{ __('Gửi liên kết đặt lại mật khẩu') }}</span>
            </flux:button>
        </div>
    </form>

    <p class="auth-switch-account">
        <a href="{{ route('login') }}" wire:navigate>{{ __('Quay lại đăng nhập') }}</a>
    </p>
</x-layouts::auth.admission>
