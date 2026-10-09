@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes->class('text-white! [&_[data-flux-heading]]:text-white!') }}>
        <x-slot name="logo" class="flex aspect-square size-10 items-center justify-center rounded-lg border border-white/30 text-white">
            <flux:icon.academic-cap class="size-7" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-10 items-center justify-center rounded-lg bg-admission-blue text-white">
            <flux:icon.academic-cap class="size-7" />
        </x-slot>
    </flux:brand>
@endif
