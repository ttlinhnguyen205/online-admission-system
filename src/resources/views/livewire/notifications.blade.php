<section class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Thông báo') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Thông báo dành cho tài khoản của bạn.') }}</flux:text>
        </div>
        <flux:badge>{{ __('Chưa đọc') }}: {{ $unreadCount }}</flux:badge>
    </div>

    @forelse ($notifications as $notification)
        @php($detail = $details->get($notification->id))
        <article wire:key="notification-{{ $notification->id }}" class="admission-panel flex flex-col gap-3 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:badge :color="$notification->read_at ? 'zinc' : 'rose'">{{ $notification->read_at ? __('Đã đọc') : __('Chưa đọc') }}</flux:badge>
                <time datetime="{{ $notification->created_at->toIso8601String() }}" class="text-xs text-zinc-500 dark:text-zinc-400">{{ $notification->created_at->format('d/m/Y H:i') }}</time>
            </div>
            <flux:heading level="2">{{ $detail['title'] }}</flux:heading>
            @if ($detail['message'])
                <flux:text class="whitespace-pre-line">{{ $detail['message'] }}</flux:text>
            @endif
            @unless ($notification->read_at)
                <div>
                    <flux:button size="sm" wire:click="markRead('{{ $notification->id }}')" wire:loading.attr="disabled" wire:target="markRead('{{ $notification->id }}')">{{ __('Đánh dấu đã đọc') }}</flux:button>
                </div>
            @endunless
        </article>
    @empty
        <div class="admission-panel flex flex-col items-center gap-3 p-8 text-center">
            <flux:icon.bell class="size-10 text-admission-blue dark:text-rose-200" />
            <flux:heading level="2">{{ __('Chưa có thông báo nào.') }}</flux:heading>
            <flux:text>{{ __('Các thông báo mới dành cho bạn sẽ xuất hiện tại đây.') }}</flux:text>
        </div>
    @endforelse

    {{ $notifications->links() }}
    <div role="status" wire:loading.delay>{{ __('Đang cập nhật thông báo…') }}</div>
</section>
