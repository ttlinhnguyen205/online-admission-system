<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="admission-auth admission-auth-page min-h-screen antialiased">
        <header class="auth-site-header">
            <a href="{{ route('home') }}" wire:navigate class="auth-site-logo" aria-label="{{ __('Trang chủ UniCamp') }}">
                <img src="{{ asset('Logo.png') }}" alt="UNICAMP — Since 1990" width="448" height="137" />
            </a>
            <nav class="auth-site-navigation" aria-label="{{ __('Điều hướng chính') }}">
                <a href="{{ route('home') }}" wire:navigate>{{ __('Trang chủ') }}</a>
                <a href="{{ route('home') }}#portal-admissions" wire:navigate>{{ __('Tuyển sinh') }}</a>
                @if (auth()->guest() || auth()->user()->isCandidate())
                    <a href="{{ route('registration-guidance') }}" wire:navigate>{{ __('Hướng dẫn') }}</a>
                @endif
                <a href="{{ route('news') }}" wire:navigate>{{ __('Tin tức') }}</a>
                <a href="{{ route('login') }}" wire:navigate>{{ __('Đăng nhập') }}</a>
            </nav>
        </header>

        <main class="auth-hero">
            <img class="auth-hero-background" src="{{ asset('admission-auth-hero.jpg') }}" alt="" fetchpriority="high" />
            <div class="auth-hero-content">
                <div class="auth-hero-message">
                    <p>Khám phá<br /><span>Tiềm năng</span> và <span>Tài năng</span><br />tại UniCamp</p>
                </div>
                <section class="auth-form-panel" aria-labelledby="auth-title">
                    <h1 id="auth-title">{{ $title }} {{ __('tài khoản của bạn') }}</h1>
                    {{ $slot }}
                </section>
            </div>
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        <x-admission.counseling-widget />

        @fluxScripts
    </body>
</html>
