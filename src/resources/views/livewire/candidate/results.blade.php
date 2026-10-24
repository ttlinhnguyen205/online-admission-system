<section class="admission-workspace mx-auto flex min-w-0 w-full max-w-7xl flex-col gap-6">
    <header class="admission-panel border-t-4 border-t-admission-blue p-5 sm:p-6">
        <flux:heading size="xl" level="1">Kết quả xét tuyển</flux:heading>
        <p class="mt-2 text-base leading-7 text-zinc-600 dark:text-zinc-300">Theo dõi kết quả các nguyện vọng theo từng đợt và xác nhận nhập học khi trúng tuyển.</p>
    </header>
    <flux:error name="engine" />
    @if ($nativeResults)
        @foreach ($nativeResults->groupBy('version.admission_round_id') as $entries)
            <section class="admission-panel p-5" wire:key="native-round-{{ $entries->first()->version->admission_round_id }}">
                <flux:heading size="lg">{{ $entries->first()->version->admissionRound->name }} | Xét theo ngành</flux:heading>
                @foreach ($entries as $entry)
                    <article class="mt-4 space-y-2 border-t border-zinc-200 pt-4 dark:border-zinc-700" wire:key="native-result-{{ $entry->id }}">
                        <flux:badge :color="$entry->decision === 'admitted' ? 'green' : 'zinc'">{{ $entry->decision === 'admitted' ? 'Trúng tuyển' : 'Không trúng tuyển' }}</flux:badge>
                        @if ($entry->decision === 'admitted')
                            <p>NV{{ $entry->getAttribute('payload')['priority'] }} | {{ $entry->getAttribute('payload')['major_name'] }} | Điểm: {{ App\Support\DisplayNumber::decimal($entry->score) }}</p>
                        @endif
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $entry->reason }} | Phiên bản {{ $entry->version->version }} | Công bố: {{ $entry->version->published_at->format('d/m/Y H:i') }}</p>
                    </article>
                @endforeach
            </section>
        @endforeach
        {{ $nativeResults->links() }}
    @endif
    @foreach ($resultRounds as $round)
        <section wire:key="results-round-{{ $round->id }}" class="admission-panel min-w-0 overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="lg">{{ $round->name }}</flux:heading>
                <flux:badge color="green">Đã công bố</flux:badge>
            </header>
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($results->filter(fn ($result) => $result->admissionWish->application->admission_round_id === $round->id)->sortBy('admissionWish.priority') as $result)
                    @php
                        $wish = $result->admissionWish;
                        $admitted = $result->decision->value === 'admitted';
                        $canConfirm = $admitted && !$result->confirmed_at && $wish->application->status->value === 'completed'
                            && $wish->status->value === 'admitted' && $wish->admissionProgram->major && $wish->admissionProgram->admissionMethod;
                    @endphp
                    <article wire:key="result-{{ $result->id }}" @class(['p-5', 'bg-green-50/60 dark:bg-green-950/20' => $admitted])>
                        <div class="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(260px,auto)] lg:items-center">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-start gap-3"><flux:badge color="rose">NV{{ $wish->priority }}</flux:badge><h3 class="text-base font-semibold">{{ $wish->admissionProgram->major->name }}</h3></div>
                                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ $wish->application->application_code }}</p>
                                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Thời gian công bố: {{ $result->published_at->format('d/m/Y H:i') }}</p>
                            </div>
                            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                <div><dt class="text-sm text-zinc-600 dark:text-zinc-300">Điểm xét tuyển</dt><dd class="mt-1 text-lg font-semibold tabular-nums">{{ App\Support\DisplayNumber::decimal($result->final_score) }}</dd></div>
                                <div><dt class="text-sm text-zinc-600 dark:text-zinc-300">Thứ hạng</dt><dd class="mt-1 text-lg font-semibold tabular-nums">{{ $result->rank ?? '—' }}</dd></div>
                                <div class="col-span-2 sm:col-span-1"><dt class="text-sm text-zinc-600 dark:text-zinc-300">Kết quả</dt><dd class="mt-1"><flux:badge :color="$admitted ? 'green' : 'zinc'">{{ App\Support\CandidateStatusLabels::result($result->decision) }}</flux:badge></dd></div>
                            </dl>
                        </div>
                        @if ($result->confirmed_at)
                            <p class="mt-4 text-sm font-semibold text-green-800 dark:text-green-200">Đã xác nhận nhập học | {{ $result->confirmed_at->format('d/m/Y H:i') }}</p>
                        @elseif ($canConfirm)
                            <div class="mt-4"><flux:button variant="primary" wire:click="confirm({{ $result->id }})" wire:confirm="{{ __('Xác nhận nhập học cho kết quả trúng tuyển này?') }}" wire:loading.attr="disabled">Xác nhận nhập học</flux:button></div>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach
    @if ($resultRounds->isEmpty() && (!$nativeResults || $nativeResults->isEmpty()))
        <div class="admission-panel p-6" role="status">Chưa có kết quả xét tuyển nào được công bố cho bạn.</div>
    @endif
    {{ $resultRounds->links() }}
    <div role="status" wire:loading.delay>{{ __('Đang tải...') }}</div>
    <div class="flex justify-end"><flux:button :href="route('candidate.notifications.index')" wire:navigate>{{ __('Tiếp theo: Thông báo') }}</flux:button></div>
</section>
