<section class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <flux:heading size="xl" level="1">{{ __('Thông báo') }}</flux:heading>
    @forelse ($notifications as $notification)
        <article wire:key="notification-{{ $notification->id }}" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:badge>{{ $notification->read_at ? __('Đọc') : __('Unread') }}</flux:badge>
            <flux:heading>{{ $notification->data['round_name'] }}</flux:heading>
            <flux:text>{{ __('Admission results are available.') }}</flux:text>
            <div class="flex flex-wrap gap-3">
                <flux:button :href="route('candidate.results.index')" wire:navigate>{{ __('View my results') }}</flux:button>
                @unless ($notification->read_at)
                    <flux:button wire:click="markRead('{{ $notification->id }}')" wire:loading.attr="disabled">{{ __('Mark as read') }}</flux:button>
                @endunless
            </div>
        </article>
    @empty
        <flux:text>{{ __('No notifications yet.') }}</flux:text>
    @endforelse
    {{ $notifications->links() }}
</section>