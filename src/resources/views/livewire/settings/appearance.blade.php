<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Cài đặt giao diện') }}</flux:heading>

    <x-settings.layout :heading="__('Giao diện')" :subheading=" __('Cập nhật cài đặt giao diện cho tài khoản')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">{{ __('Sáng') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('Tối') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('Theo hệ thống') }}</flux:radio>
        </flux:radio.group>
    </x-settings.layout>
</section>
