<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">
        {{ __('Cài đặt ngôn ngữ') }}
    </flux:heading>

    <x-settings.layout
        :heading="__('Ngôn ngữ')"
        :subheading="__('Hệ thống sử dụng tiếng Việt cho giao diện và thông báo.')">
        <div class="my-6 w-full space-y-6">

            <flux:select
                wire:model="locale"
                :label="__('Ngôn ngữ')">
                <flux:select.option value="vi">
                    Tiếng Việt
                </flux:select.option>

            </flux:select>

            <div class="flex items-center gap-4">
                <flux:button
                    variant="primary"
                    type="button"
                    wire:click="updateLanguage">
                    {{ __('Lưu ngôn ngữ') }}
                </flux:button>
            </div>

        </div>
    </x-settings.layout>
</section>
