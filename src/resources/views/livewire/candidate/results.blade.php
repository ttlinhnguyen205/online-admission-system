<section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <flux:heading size="xl" level="1">{{ __('Kết quả xét tuyển') }}</flux:heading>
    <flux:error name="engine" />
    @forelse ($results as $result)
        <article wire:key="result-{{ $result->id }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Đợt tuyển sinh') }}: {{ $result->admissionWish->application->admissionRound->name }}</flux:heading>
            <flux:text>{{ __('Thứ tự nguyện vọng') }}: {{ $result->admissionWish->priority }} · {{ __('Ngành xét tuyển') }}: {{ $result->admissionWish->admissionProgram->major->name }} · {{ $result->admissionWish->admissionProgram->admissionMethod->name }}</flux:text>
            <dl class="grid gap-4 sm:grid-cols-3">
                <div><dt>{{ __('Điểm xét tuyển') }}</dt><dd>{{ $result->final_score }}</dd></div>
                <div><dt>{{ __('Thứ hạng') }}</dt><dd>{{ $result->rank ?? '—' }}</dd></div>
                <div><dt>{{ __('Kết quả') }}</dt><dd>{{ App\Support\CandidateStatusLabels::result($result->decision) }}</dd></div>
            </dl>
            <flux:text>{{ __('Thời gian công bố') }}: {{ $result->published_at->format('Y-m-d H:i:s') }}</flux:text>
            @if ($result->confirmed_at)
                <flux:text>{{ __('Đã xác nhận nhập học') }}: {{ $result->confirmed_at->format('Y-m-d H:i:s') }}</flux:text>
            @elseif ($result->decision->value === 'admitted')
                <flux:button variant="primary" wire:click="confirm({{ $result->id }})" wire:confirm="{{ __('Xác nhận nhập học cho kết quả trúng tuyển này?') }}" wire:loading.attr="disabled">{{ __('Xác nhận nhập học') }}</flux:button>
            @endif
        </article>
    @empty
        <flux:text>{{ __('Chưa có kết quả xét tuyển nào được công bố cho bạn.') }}</flux:text>
    @endforelse
    {{ $results->links() }}
    <div role="status" wire:loading.delay>{{ __('Đang tải...') }}</div>
    <div class="flex justify-end">
        <flux:button :href="route('candidate.notifications.index')" wire:navigate>{{ __('Tiếp theo: Thông báo') }}</flux:button>
    </div>
</section>
