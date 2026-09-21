<section class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <flux:heading size="xl" level="1">{{ __('Thông báo') }}</flux:heading>
    @forelse ($notifications as $notification)
        @php($detail = $details->get($notification->id))
        <article wire:key="notification-{{ $notification->id }}" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 {{ $notification->read_at ? '' : 'border-blue-500' }}">
            <flux:badge>{{ $notification->read_at ? __('Đã đọc') : __('Chưa đọc') }}</flux:badge>
            <flux:heading>{{ $detail['title'] }}</flux:heading>
            @if ($detail['message'])<flux:text>{{ $detail['message'] }}</flux:text>@endif
            <div class="flex flex-wrap gap-3">
                @if ($detail['url'])<flux:button :href="$detail['url']" wire:navigate>{{ $detail['action'] }}</flux:button>@endif
                @unless ($notification->read_at)
                    <flux:button wire:click="markRead('{{ $notification->id }}')" wire:loading.attr="disabled">{{ __('Đánh dấu đã đọc') }}</flux:button>
                @endunless
            </div>
        </article>
    @empty
        <flux:text>{{ __('Chưa có thông báo nào.') }}</flux:text>
    @endforelse
    {{ $notifications->links() }}
</section>
