<div class="admission-panel flex items-start gap-6 p-5 sm:p-6 max-md:flex-col">
    <div class="w-full shrink-0 pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Cài đặt') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Hồ sơ') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Bảo mật') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Giao diện') }}</flux:navlist.item>
            <flux:navlist.item :href="route('language.edit')" wire:navigate>{{ __('Ngôn ngữ') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="min-w-0 flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
