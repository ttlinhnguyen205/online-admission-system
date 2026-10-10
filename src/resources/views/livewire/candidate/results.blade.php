<section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <flux:heading size="xl" level="1">{{ __('Kết quả xét tuyển') }}</flux:heading>
    <flux:error name="engine" />
    @if ($nativeResults)
        @foreach ($nativeResults as $entry)
            <article wire:key="native-result-{{ $entry->id }}">
                <flux:heading>{{ $entry->version->admissionRound->name }} · Native V{{ $entry->version->version }}</flux:heading>
                <flux:text>{{ $entry->decision === 'admitted' ? 'Trúng tuyển' : 'Không trúng tuyển' }}</flux:text>
                @if ($entry->decision === 'admitted')
                    <flux:text>NV{{ $entry->getAttribute('payload')['priority'] }} · {{ $entry->getAttribute('payload')['major_name'] }} · Điểm: {{ $entry->score }}</flux:text>
                @endif
                <flux:text>{{ $entry->reason }} · Công bố: {{ $entry->version->published_at->format('d/m/Y H:i:s') }}</flux:text>
            </article>
        @endforeach
        {{ $nativeResults->links() }}
    @endif
    @forelse ($results as $result)
        <article wire:key="result-{{ $result->id }}" class="flex flex-col gap-4 admission-panel p-5">
            <flux:heading size="lg">{{ __('Đợt tuyển sinh') }}: {{ $result->admissionWish->application->admissionRound->name }}</flux:heading>
            <flux:text>{{ __('Thứ tự nguyện vọng') }}: {{ $result->admissionWish->priority }} · {{ __('Ngành xét tuyển') }}: {{ $result->admissionWish->admissionProgram->major->name }}</flux:text>
            <dl class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-admission-soft p-4 dark:bg-blue-400/10"><dt class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ __('Điểm xét tuyển') }}</dt><dd class="mt-2 text-2xl font-extrabold text-admission-navy dark:text-sky-50">{{ $result->final_score }}</dd></div>
                <div class="rounded-lg bg-admission-canvas p-4 dark:bg-zinc-800"><dt class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ __('Thứ hạng') }}</dt><dd class="mt-2 text-2xl font-extrabold text-admission-navy dark:text-sky-50">{{ $result->rank ?? '—' }}</dd></div>
                <div class="rounded-lg border border-sky-100 p-4 dark:border-zinc-700"><dt class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ __('Kết quả') }}</dt><dd class="mt-2 text-lg font-bold text-admission-navy dark:text-sky-50">{{ App\Support\CandidateStatusLabels::result($result->decision) }}</dd></div>
            </dl>
            <flux:text>{{ __('Thời gian công bố') }}: {{ $result->published_at->format('Y-m-d H:i:s') }}</flux:text>
            @if ($result->confirmed_at)
                <flux:text>{{ __('Đã xác nhận nhập học') }}: {{ $result->confirmed_at->format('Y-m-d H:i:s') }}</flux:text>
            @elseif ($result->decision->value === 'admitted')
                <flux:button variant="primary" wire:click="confirm({{ $result->id }})" wire:confirm="{{ __('Xác nhận nhập học cho kết quả trúng tuyển này?') }}" wire:loading.attr="disabled">{{ __('Xác nhận nhập học') }}</flux:button>
            @endif
        </article>
    @empty
        @if (!$nativeResults || $nativeResults->isEmpty())
            <flux:text>{{ __('Chưa có kết quả xét tuyển nào được công bố cho bạn.') }}</flux:text>
        @endif
    @endforelse
    {{ $results->links() }}
    <div role="status" wire:loading.delay>{{ __('Đang tải...') }}</div>
    <div class="flex justify-end">
        <flux:button :href="route('candidate.notifications.index')" wire:navigate>{{ __('Tiếp theo: Thông báo') }}</flux:button>
    </div>
</section>
