<section class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Tư vấn tuyển sinh') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Tra cứu ngành và thời hạn đăng ký từ danh mục tuyển sinh đang được công bố.') }}</flux:text>
        </div>
        <flux:button wire:click="clear" wire:loading.attr="disabled" wire:target="send,clear">{{ __('Xóa cuộc trò chuyện') }}</flux:button>
    </div>

    @if (! $ready)
        <flux:callout role="status">{{ __('Tư vấn AI chưa được bật hoặc chưa được cấu hình. Các trang đăng ký và kết quả vẫn hoạt động bình thường.') }}</flux:callout>
    @endif

    <div class="grid min-w-0 gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
        <aside class="flex flex-col gap-3" aria-label="{{ __('Câu hỏi gợi ý') }}">
            <flux:heading level="2">{{ __('Bạn muốn tìm hiểu gì?') }}</flux:heading>
            @foreach ($suggestions as $suggestion)
                <button type="button" wire:key="suggestion-{{ $loop->index }}"
                    x-on:click="$wire.set('question', @js($suggestion)); $nextTick(() => document.getElementById('counseling-question').focus())"
                    class="rounded-xl border border-zinc-200 p-3 text-start text-sm text-zinc-700 hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-700">
                    {{ $suggestion }}
                </button>
            @endforeach
            <flux:text class="text-xs">{{ __('Không dự đoán trúng tuyển. Không thay đổi hồ sơ hoặc nguyện vọng của bạn.') }}</flux:text>
        </aside>

        <div class="flex min-w-0 flex-col gap-4">
            <div x-data="{ nearBottom: true, unread: false }"
                x-on:counseling-answered.window="$nextTick(() => { if (nearBottom) { $refs.messages.scrollTop = $refs.messages.scrollHeight } else { unread = true } })">
                <div x-ref="messages" x-on:scroll="nearBottom = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 80; if (nearBottom) unread = false"
                    role="log" aria-live="polite" aria-relevant="additions" aria-label="{{ __('Cuộc trò chuyện tư vấn') }}"
                    tabindex="0" class="flex max-h-[55dvh] min-h-64 flex-col gap-5 overflow-y-auto rounded-2xl border border-zinc-200 p-4 focus-visible:outline-2 focus-visible:outline-blue-600 dark:border-zinc-700 sm:p-6">
                    @forelse ($turns as $turn)
                        <article wire:key="turn-{{ $turn['id'] }}" class="flex min-w-0 flex-col gap-3">
                            <div class="ms-auto max-w-full rounded-2xl bg-blue-50 px-4 py-3 text-zinc-900 dark:bg-blue-950 dark:text-zinc-100">
                                <p class="mb-1 text-xs font-semibold">{{ __('Bạn') }}</p>
                                <p class="whitespace-pre-wrap break-words">{{ $turn['question'] }}</p>
                            </div>
                            <div class="rounded-2xl bg-zinc-50 px-4 py-3 dark:bg-zinc-900">
                                <p class="mb-2 text-xs font-semibold">{{ __('Tư vấn tuyển sinh') }}</p>
                                <p class="whitespace-pre-wrap break-words text-sm leading-7">{{ $turn['answer']['body'] }}</p>
                                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Nguồn: danh mục tuyển sinh được phép công bố. Tra cứu lúc') }} {{ $turn['answer']['checked_at'] }} · {{ config('app.timezone') }}</p>
                                @if ($turn['answer']['route'])
                                    <flux:button class="mt-3" size="sm" :href="route($turn['answer']['route'])" wire:navigate>
                                        {{ $turn['answer']['route'] === 'candidate.results.index' ? __('Mở kết quả xét tuyển') : __('Mở đăng ký nguyện vọng') }}
                                    </flux:button>
                                @endif
                                @foreach ($turn['answer']['choices'] as $choice)
                                    <button type="button" wire:key="choice-{{ $turn['id'] }}-{{ $loop->index }}"
                                        x-on:click="$wire.set('question', @js($choice)); $nextTick(() => document.getElementById('counseling-question').focus())"
                                        class="mt-3 block rounded-lg border border-zinc-300 p-2 text-start text-sm hover:bg-zinc-100 dark:border-zinc-600 dark:hover:bg-zinc-800">{{ $choice }}</button>
                                @endforeach
                            </div>
                        </article>
                    @empty
                        <div class="m-auto flex max-w-md flex-col gap-3 text-center">
                            <flux:heading level="2">{{ __('Xin chào! Bạn muốn tìm hiểu ngành nào?') }}</flux:heading>
                            <flux:text>{{ __('Chọn câu hỏi gợi ý hoặc nhập câu hỏi bên dưới. Khi dữ liệu chưa được công bố, trợ lý sẽ nói rõ thay vì suy đoán.') }}</flux:text>
                        </div>
                    @endforelse
                </div>
                <button type="button" x-cloak x-show="unread" x-on:click="$refs.messages.scrollTop = $refs.messages.scrollHeight; unread = false"
                    class="mt-2 rounded-lg border p-2 text-sm">{{ __('Có câu trả lời mới — xem bên dưới') }}</button>
            </div>

            <div role="status" wire:loading.delay wire:target="send">{{ __('Đang kiểm tra thông tin tuyển sinh…') }}</div>
            @if ($failure)
                <flux:callout variant="warning" role="alert">{{ $failure }} {{ __('Bạn có thể sửa câu hỏi rồi bấm Gửi lại.') }}</flux:callout>
            @endif

            <form wire:submit="send" class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:textarea id="counseling-question" wire:model="question" :label="__('Câu hỏi của bạn')" rows="3" maxlength="2000"
                    :placeholder="__('Ví dụ: Ngành Công nghệ thông tin có đang nhận đăng ký không?')" />
                <div class="text-end text-xs text-zinc-500" x-text="($wire.question || '').length + ' / 2000'"></div>
                <flux:error name="question" />
                <flux:text class="text-xs">{{ __('Câu hỏi và ngữ cảnh gần đây được gửi tới Google Gemini. Không nhập số căn cước, điện thoại, tài liệu hoặc thông tin cá nhân. Google xử lý dữ liệu theo điều khoản của dịch vụ; không cam kết lưu trữ bằng 0.') }}</flux:text>
                <flux:text class="text-xs">{{ __('Lịch sử được mã hóa, chỉ dùng trong phiên này: tối đa 10 lượt, hết hạn sau 120 phút không hoạt động hoặc 24 giờ từ khi bắt đầu. Xóa cuộc trò chuyện để bỏ lịch sử trên hệ thống này; thao tác này không xóa dữ liệu đã gửi tới nhà cung cấp.') }}</flux:text>
                <flux:checkbox wire:model="consent" :label="__('Tôi hiểu thông báo quyền riêng tư và đồng ý gửi câu hỏi tới dịch vụ AI.')" />
                <flux:error name="consent" />
                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" :disabled="! $ready" wire:loading.attr="disabled" wire:target="send,clear">{{ $failure ? __('Gửi lại') : __('Gửi câu hỏi') }}</flux:button>
                </div>
            </form>
        </div>
    </div>
</section>
