<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="admission-shell min-h-screen bg-admission-canvas text-slate-900 antialiased dark:bg-zinc-950 dark:text-slate-100">
        <header class="border-b border-sky-100 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-4 sm:px-6 lg:px-8">
                <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-3" wire:navigate>
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-admission-navy text-sm font-bold text-white">
                        TS
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-bold uppercase text-admission-navy dark:text-sky-50">{{ __('Tuyển sinh') }}</span>
                        <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ config('app.name') }}</span>
                    </span>
                </a>
                <a href="{{ route('home') }}" class="shrink-0 rounded-lg border border-sky-200 px-3 py-2 text-sm font-semibold text-admission-blue transition hover:bg-admission-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-admission-blue dark:border-zinc-700 dark:text-sky-200 dark:hover:bg-zinc-800" wire:navigate>
                    {{ __('Trang chủ') }}
                </a>
            </div>
        </header>

        <main class="mx-auto grid min-h-[calc(100svh-5rem)] max-w-7xl items-center gap-10 px-5 py-10 sm:px-6 lg:grid-cols-2 lg:gap-16 lg:px-8 lg:py-16">
            <section class="hidden flex-col gap-8 lg:flex">
                <div class="flex flex-col gap-5">
                    <span class="w-fit rounded-full border border-sky-200 bg-white px-3 py-1 text-xs font-bold uppercase tracking-wide text-admission-blue dark:border-zinc-700 dark:bg-zinc-900 dark:text-sky-200">{{ __('Tuyển sinh trực tuyến') }}</span>
                    <h1 class="max-w-xl text-5xl font-extrabold leading-tight text-admission-navy dark:text-sky-50">{{ __('Bắt đầu hành trình của bạn.') }}</h1>
                    <p class="max-w-lg text-base leading-7 text-slate-600 dark:text-slate-300">{{ __('Hoàn thiện hồ sơ, đăng ký nguyện vọng và theo dõi kết quả xét tuyển trên một cổng thông tin.') }}</p>
                </div>
                <div class="rounded-lg bg-admission-navy p-6 text-white shadow-xl shadow-slate-900/10">
                    <p class="text-sm font-bold">{{ __('Các bước đăng ký xét tuyển') }}</p>
                    <ol class="mt-5 flex flex-col gap-5">
                        @foreach (['Hoàn thiện hồ sơ cá nhân', 'Khai báo thông tin và minh chứng', 'Sắp xếp nguyện vọng và nộp hồ sơ'] as $step)
                            <li class="flex items-center gap-3">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ $loop->last ? 'bg-admission-yellow text-admission-navy' : 'bg-white/10 text-sky-100' }} text-xs font-bold">{{ $loop->iteration }}</span>
                                <span class="text-sm text-sky-50">{{ __($step) }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>

            <div class="mx-auto flex w-full max-w-md flex-col gap-5">
                <div class="admission-panel p-6 sm:p-8">
                    {{ $slot }}
                </div>
                <p class="text-center text-xs leading-5 text-slate-500 dark:text-slate-400">{{ __('Cổng thông tin xét tuyển trực tuyến') }} · {{ config('app.name') }}</p>
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
