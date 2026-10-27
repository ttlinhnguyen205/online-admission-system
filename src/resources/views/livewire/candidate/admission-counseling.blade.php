<div>
    @if ($floating)
        <div
            wire:key="floating-admission-counseling"
            x-data="{
                chatOpen: false,
                init() {
                    try { this.chatOpen = sessionStorage.getItem('admission-counseling-open') === '1' } catch (error) {}
                    this.$watch('chatOpen', value => {
                        try { sessionStorage.setItem('admission-counseling-open', value ? '1' : '0') } catch (error) {}
                    })
                }
            }"
            x-on:keydown.escape.window="if (chatOpen) { chatOpen = false; $refs.launcher.focus() }"
            x-effect="if (chatOpen) $nextTick(() => { const messages = document.getElementById('counseling-messages'); if (messages) messages.scrollTop = messages.scrollHeight })"
            class="fixed bottom-4 right-4 z-50 sm:bottom-6 sm:right-6"
            data-counseling-widget
        >
            <section wire:ignore.self x-cloak x-show="chatOpen" x-transition.opacity id="floating-counseling-panel" role="region" aria-label="Tư vấn tuyển sinh"
                class="fixed bottom-22 left-3 right-3 flex h-[min(680px,calc(100dvh-7rem))] flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xl sm:bottom-24 sm:left-auto sm:right-6 sm:w-[420px] dark:border-zinc-700 dark:bg-zinc-900">
                <header class="flex shrink-0 items-center justify-between gap-3 border-b border-zinc-200 bg-admission-soft px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="min-w-0">
                        <h2 class="font-semibold text-admission-blue dark:text-rose-200">Tư vấn tuyển sinh</h2>
                        <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">Đang hỗ trợ: {{ $screenLabel }}</p>
                    </div>
                    <div class="flex items-center gap-1">
                        <flux:button size="sm" variant="ghost" wire:click="clear" wire:loading.attr="disabled" wire:target="send,clear" aria-label="Xóa cuộc trò chuyện">Xóa</flux:button>
                        <button type="button" x-on:click="chatOpen = false; $refs.launcher.focus()" aria-label="Đóng tư vấn tuyển sinh" class="rounded-lg p-2 text-zinc-600 hover:bg-white/70 focus-visible:outline-2 focus-visible:outline-admission-blue dark:text-zinc-200 dark:hover:bg-white/10">
                            <flux:icon.x-mark class="size-5" />
                        </button>
                    </div>
                </header>
                <div class="min-h-0 flex-1 overflow-y-auto p-3">
                    <x-admission.counseling-conversation :turns="$turns" :ready="$ready" :enabled="$enabled" :failure="$failure" :suggestions="$suggestions" :floating="true" />
                </div>
            </section>
            <button type="button" x-ref="launcher" x-on:click="chatOpen = !chatOpen; if (chatOpen) $nextTick(() => document.getElementById('counseling-question')?.focus())"
                x-bind:aria-expanded="chatOpen" aria-controls="floating-counseling-panel" aria-label="Mở tư vấn tuyển sinh"
                class="flex h-14 items-center justify-center gap-2 rounded-full bg-admission-blue px-4 text-white shadow-lg transition hover:bg-rose-800 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-admission-blue">
                <flux:icon.chat-bubble-left-right variant="solid" class="size-7 text-white!" />
                <span class="text-sm font-semibold">Tư vấn</span>
            </button>
        </div>
    @else
        <section class="mx-auto flex w-full max-w-6xl flex-col gap-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <flux:heading size="xl" level="1">{{ __('Tư vấn tuyển sinh') }}</flux:heading>
                    <flux:text class="mt-2">Tra cứu tuyển sinh và hướng dẫn sử dụng các chức năng cho thí sinh.</flux:text>
                </div>
                <flux:button wire:click="clear" wire:loading.attr="disabled" wire:target="send,clear">{{ __('Xóa cuộc trò chuyện') }}</flux:button>
            </div>
            <x-admission.counseling-conversation :turns="$turns" :ready="$ready" :enabled="$enabled" :failure="$failure" :suggestions="$suggestions" />
        </section>
    @endif
</div>
