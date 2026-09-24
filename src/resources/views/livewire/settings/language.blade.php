<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">
        {{ __('Language settings') }}
    </flux:heading>

    <x-settings.layout
        :heading="__('Ngôn ngữ / Language')"
        :subheading="__('Chọn ngôn ngữ hiển thị cho hệ thống / Choose the display language')">
        <div class="my-6 w-full space-y-6">

            <flux:select
                wire:model="locale"
                :label="__('Ngôn ngữ / Language')">
                <flux:select.option value="vi">
                    Tiếng Việt
                </flux:select.option>

                <flux:select.option value="en">
                    English
                </flux:select.option>
            </flux:select>

            <div class="flex items-center gap-4">
                <flux:button
                    variant="primary"
                    type="button"
                    wire:click="updateLanguage">
                    {{ __('Lưu ngôn ngữ / Save language') }}
                </flux:button>
            </div>

        </div>
    </x-settings.layout>
</section>