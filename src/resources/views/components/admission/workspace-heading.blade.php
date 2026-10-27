@props(['title', 'description' => null])

<header {{ $attributes->class('admission-panel flex min-w-0 flex-col gap-4 border-t-4 border-t-admission-blue p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6') }}>
    <div class="min-w-0">
        <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
        @if ($description)
            <p class="mt-2 text-base leading-7 text-zinc-600 dark:text-zinc-300">{{ $description }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex shrink-0 flex-wrap items-center gap-3">{{ $slot }}</div>
    @endif
</header>
