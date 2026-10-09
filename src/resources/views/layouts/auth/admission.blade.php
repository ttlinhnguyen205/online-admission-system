<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="admission-auth min-h-screen bg-[#1d3675] antialiased">
        <div class="pointer-events-none fixed inset-0 bg-cover bg-center" style="background-image: url('{{ asset('admission-graduation.png') }}')" aria-hidden="true"></div>

        <main class="relative flex min-h-svh items-center justify-center overflow-hidden px-5 py-12 sm:px-8">
            <div class="relative w-full max-w-[460px]">
                <svg class="pointer-events-none absolute -bottom-6 -left-6 h-32 w-52 text-[#5c7fea] max-sm:hidden" viewBox="0 0 256 160" fill="none" aria-hidden="true">
                    <path d="M34 28C-16 82 12 134 80 137C148 140 198 99 246 77" stroke="currentColor" stroke-width="2" stroke-dasharray="7 5" />
                </svg>

                <section class="relative rounded-[28px] bg-linear-to-b from-[#b5c5f1] to-[#f0f3ff] px-6 pt-16 pb-8 shadow-2xl shadow-blue-950/10 sm:px-10 sm:pb-10" aria-labelledby="auth-title">
                    <svg class="pointer-events-none absolute -top-6 left-1/2 h-24 w-64 -translate-x-[5%] text-[#5678db] max-sm:left-1/3 max-sm:w-52" viewBox="0 0 320 112" fill="none" aria-hidden="true">
                        <path d="M56 79C122 29 204 3 258 15C310 26 316 67 293 109" stroke="currentColor" stroke-width="2" stroke-dasharray="7 5" />
                        <path d="M9 105L54 65L83 62L69 67L104 65L9 105Z" fill="#9db8fb" stroke="currentColor" stroke-width="2" stroke-linejoin="round" />
                        <path d="M9 105L69 67L76 84L9 105ZM9 105L83 62L54 65" stroke="currentColor" stroke-width="2" stroke-linejoin="round" />
                    </svg>

                    <h1 id="auth-title" class="text-center text-2xl font-bold uppercase leading-tight text-[#153c85] sm:text-[28px]">{{ $title }}</h1>

                    <div class="mt-8 sm:mt-10">
                        {{ $slot }}
                    </div>
                </section>

                <a href="{{ route('home') }}" wire:navigate class="absolute top-full left-1/2 mt-5 block w-max max-w-full -translate-x-1/2 rounded px-2 py-1 text-sm text-blue-100 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">&larr; {{ __('Trang chủ tuyển sinh') }}</a>
            </div>
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
