@props(['turns', 'ready', 'enabled', 'failure', 'suggestions', 'floating' => false])

<div class="flex h-full min-h-0 min-w-0 flex-col gap-3">
    @if (! $ready)
        <p role="status" class="rounded-lg bg-zinc-50 p-3 text-xs leading-5 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-300">{{ $enabled ? 'Tra cứu AI chưa sẵn sàng. Bạn vẫn có thể hỏi cách sử dụng các chức năng.' : 'Trợ lý đang tạm tắt. Bạn vẫn có thể sử dụng các chức năng của hệ thống.' }}</p>
    @endif
    <div x-data="{ nearBottom: true, unread: false }" class="min-h-0 {{ $floating ? 'flex-1' : '' }}"
        x-on:counseling-answered.window="$nextTick(() => { if (nearBottom) { $refs.messages.scrollTop = $refs.messages.scrollHeight } else { unread = true } })">
        <div id="counseling-messages" x-ref="messages" x-init="$nextTick(() => { $refs.messages.scrollTop = $refs.messages.scrollHeight })"
            x-on:scroll="nearBottom = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 80; if (nearBottom) unread = false"
            role="log" aria-live="polite" aria-relevant="additions" aria-label="{{ __('Cuộc trò chuyện tư vấn') }}"
            tabindex="0" class="flex flex-col gap-4 overflow-y-auto rounded-xl border border-zinc-200 bg-white p-3 text-sm focus-visible:outline-2 focus-visible:outline-rose-600 dark:border-zinc-700 dark:bg-zinc-800 {{ $floating ? 'h-full min-h-24' : 'max-h-[55dvh] min-h-64 sm:p-5' }}">
            @forelse ($turns as $turn)
                <article wire:key="turn-{{ $turn['id'] }}" class="flex min-w-0 flex-col gap-3">
                    <div class="ms-auto max-w-full rounded-xl bg-rose-50 px-3 py-2 text-zinc-900 dark:bg-rose-950 dark:text-zinc-100">
                        <p class="mb-1 text-xs font-semibold">{{ __('Bạn') }}</p>
                        <p class="whitespace-pre-wrap break-words">{{ $turn['question'] }}</p>
                    </div>
                    <div class="rounded-xl bg-zinc-50 px-3 py-3 dark:bg-zinc-900">
                        <p class="mb-2 text-xs font-semibold">{{ __('Tư vấn tuyển sinh') }}</p>
                        <p class="whitespace-pre-wrap break-words text-sm leading-6">{{ $turn['answer']['body'] }}</p>
                        <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ $turn['answer']['source'] ?? __('Nguồn: danh mục tuyển sinh được phép công bố. Tra cứu lúc') }} {{ $turn['answer']['checked_at'] }}</p>
                        @if ($turn['answer']['route'])
                            <flux:button class="mt-3" size="sm" :href="route($turn['answer']['route'])" wire:navigate>
                                {{ $turn['answer']['route_label'] ?? ($turn['answer']['route'] === 'candidate.results.index' ? __('Mở kết quả xét tuyển') : __('Mở đăng ký nguyện vọng')) }}
                            </flux:button>
                        @endif
                        @foreach ($turn['answer']['choices'] as $choice)
                            <button type="button" wire:key="choice-{{ $turn['id'] }}-{{ $loop->index }}"
                                x-on:click="$wire.question = @js($choice); $nextTick(() => document.getElementById('counseling-question')?.focus())"
                                class="mt-3 block rounded-lg border border-zinc-300 p-2 text-start text-sm hover:bg-zinc-100 dark:border-zinc-600 dark:hover:bg-zinc-800">{{ $choice }}</button>
                        @endforeach
                    </div>
                </article>
            @empty
                <div class="m-auto flex max-w-md flex-col gap-2 py-4 text-center">
                    <flux:heading level="2">Bạn cần hỗ trợ chức năng nào?</flux:heading>
                    <flux:text>Hỏi cách sử dụng trang đang xem hoặc tra cứu thông tin tuyển sinh đã công bố.</flux:text>
                </div>
            @endforelse
        </div>
        <button type="button" x-cloak x-show="unread" x-on:click="$refs.messages.scrollTop = $refs.messages.scrollHeight; unread = false"
            class="mt-2 rounded-lg border p-2 text-xs">{{ __('Có câu trả lời mới — xem bên dưới') }}</button>
    </div>
    <div role="status" wire:loading.delay wire:target="send" class="text-xs text-zinc-600 dark:text-zinc-300">{{ __('Đang kiểm tra thông tin tuyển sinh…') }}</div>
    @if ($failure)
        <p class="rounded-lg bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100" role="alert">{{ $failure }} {{ __('Bạn có thể sửa câu hỏi rồi bấm Gửi lại.') }}</p>
    @endif
    <form wire:submit="send" class="flex shrink-0 flex-col gap-2 rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:textarea id="counseling-question" wire:model="question" :label="__('Câu hỏi của bạn')" :rows="$floating ? 2 : 3" maxlength="2000"
            placeholder="Ví dụ: Trang này dùng để làm gì?" />
        <div class="text-end text-xs text-zinc-500" x-text="($wire.question || '').length + ' / 2000'"></div>
        <flux:error name="question" />
        <flux:select :label="__('Câu hỏi gợi ý')" wire:loading.attr="disabled" wire:target="send,clear"
            x-on:keydown.escape.stop
            x-on:change="if ($event.target.value) { $wire.question = $event.target.value; $event.target.value = ''; $nextTick(() => document.getElementById('counseling-question')?.focus()) }">
            <option value="">{{ __('Chọn câu hỏi có sẵn') }}</option>
            @foreach ($suggestions as $suggestedQuestion)
                <option value="{{ $suggestedQuestion }}" wire:key="counseling-suggestion-{{ $loop->index }}">{{ $suggestedQuestion }}</option>
            @endforeach
        </flux:select>
        <div class="flex justify-end">
            <flux:button type="submit" variant="primary" :disabled="! $enabled" wire:loading.attr="disabled" wire:target="send,clear">{{ $failure ? __('Gửi lại') : __('Gửi câu hỏi') }}</flux:button>
        </div>
    </form>
</div>

