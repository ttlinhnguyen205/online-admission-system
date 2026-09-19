<section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <flux:heading size="xl" level="1">{{ __('Kết quả xét tuyển') }}</flux:heading>
    <flux:error name="engine" />
    @forelse ($results as $result)
        <article wire:key="result-{{ $result->id }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg">{{ $result->admissionWish->application->admissionRound->name }}</flux:heading>
            <flux:text>{{ __('Nguyện vọng ưu tiên') }}: {{ $result->admissionWish->priority }} · {{ $result->admissionWish->admissionProgram->major->name }} · {{ $result->admissionWish->admissionProgram->admissionMethod->name }}</flux:text>
            <dl class="grid gap-4 sm:grid-cols-3">
                <div><dt>{{ __('Final score') }}</dt><dd>{{ $result->final_score }}</dd></div>
                <div><dt>{{ __('Rank') }}</dt><dd>{{ $result->rank ?? '—' }}</dd></div>
                <div><dt>{{ __('Decision') }}</dt><dd>{{ $result->decision->value === 'admitted' ? __('Trúng tuyển') : __('Không trúng tuyển') }}</dd></div>
            </dl>
            <flux:text>{{ __('Published') }}: {{ $result->published_at->format('Y-m-d H:i:s') }}</flux:text>
            @if ($result->confirmed_at)
                <flux:text>{{ __('Đã xác nhận') }}: {{ $result->confirmed_at->format('Y-m-d H:i:s') }}</flux:text>
            @elseif ($result->decision->value === 'admitted')
                <flux:button variant="primary" wire:click="confirm({{ $result->id }})" wire:confirm="{{ __('Confirm this admitted result?') }}" wire:loading.attr="disabled">{{ __('Xác nhận nhập học') }}</flux:button>
            @endif
        </article>
    @empty
        <flux:text>{{ __('Hiện chưa có kết quả nào được công bố cho tài khoản của bạn.') }}</flux:text>
    @endforelse
    {{ $results->links() }}
    <div role="status" wire:loading.delay>{{ __('Đang tải…') }}</div>
</section>