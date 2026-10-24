<section class="admission-workspace mx-auto flex min-w-0 w-full max-w-7xl flex-col gap-6">
    <x-admin.workspace-heading :title="__('Công cụ xét tuyển')" :description="__('Review readiness and allocate admission places for a closed round. Saved decisions are immutable and remain unpublished.')" />

    <form wire:submit="preview" class="admission-panel grid min-w-0 gap-4 p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
        <flux:select wire:model="roundSelection" :label="__('Admission round')" class="min-w-0 w-full">
            <option value="">{{ __('Select a round') }}</option>
            @foreach ($rounds as $round)
                <option wire:key="round-{{ $round->id }}" value="{{ $round->id }}">{{ $round->year }} · {{ $round->name }} ({{ App\Support\CandidateStatusLabels::round($round->status, $round->end_date) }})</option>
            @endforeach
        </flux:select>
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Preview readiness') }}</flux:button>
    </form>
    <flux:error name="roundSelection" />
    <flux:error name="engine" />
    <div role="status" wire:loading.delay>{{ __('Checking and processing admission data…') }}</div>

    @if (($previewData['native'] ?? false) === true)
        <div class="admission-panel min-w-0 space-y-4 p-5">
        <flux:heading>Báo cáo xét tuyển theo ngành — đợt #{{ $previewData['round_id'] }}</flux:heading>
        <flux:text>Đăng ký theo ngành · {{ $previewData['applications'] }} hồ sơ</flux:text>
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($previewData['counts'] as $status => $count)
            <div class="workspace-stat" wire:key="native-count-{{ $status }}"><dt class="text-sm text-zinc-500">{{ match ($status) { 'eligible' => 'Đủ điều kiện', 'ineligible' => 'Không đủ điều kiện', 'pending_data' => 'Chờ dữ liệu', 'unsupported' => 'Chưa được hỗ trợ', 'needs_resolution' => 'Cần xử lý', 'not_scored' => 'Chưa tính điểm', 'stale' => 'Cần tính lại', default => 'Trạng thái khác' } }}</dt><dd class="mt-2 text-xl font-semibold">{{ $count }}</dd></div>
        @endforeach
        </dl>
        @if ($previewData['blockers'] !== [])
        <flux:callout variant="warning">
            @foreach ($previewData['blockers'] as $index => $blocker)
                <p wire:key="native-blocker-{{ $index }}">{{ __($blocker) }}</p>
            @endforeach
        </flux:callout>
        @endif
        <flux:button wire:click="exportNative">Xuất báo cáo điểm xét theo ngành (CSV)</flux:button>
        </div>
    @elseif ($previewData !== [])
        <div class="space-y-4 admission-panel p-5">
            <flux:heading size="lg">{{ __('Readiness for round') }} #{{ $previewData['round_id'] }}</flux:heading>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (['round_status' => 'Round status', 'verified' => 'Verified applications ready', 'drafts' => 'Excluded drafts', 'submitted' => 'Submitted awaiting review', 'under_review' => 'Under review', 'needs_revision' => 'Needs revision', 'wishes' => 'Participating wishes ready', 'existing_results' => 'Existing results', 'existing_events' => 'Existing engine events'] as $field => $label)
                    <div class="workspace-stat" wire:key="readiness-{{ $field }}"><dt class="text-sm text-zinc-500">{{ __($label) }}</dt><dd class="mt-2 text-xl font-semibold tabular-nums">{{ $field === 'round_status' ? App\Support\CandidateStatusLabels::round(App\Enums\AdmissionRoundStatus::from($previewData[$field])) : $previewData[$field] }}</dd></div>
                @endforeach
            </dl>
            <flux:text>{{ __('Drafts are excluded. Submitted, under-review, and needs-revision applications must be resolved first. Dates do not automatically close a round.') }}</flux:text>
        </div>

        <div class="admission-panel min-w-0 space-y-4 p-5">
            <flux:heading size="lg">{{ __('Relevant programs') }}</flux:heading>
            <div class="overflow-x-auto" tabindex="0" aria-label="Chương trình tham gia xét tuyển">
                <flux:table class="workspace-table min-w-[760px]">
                    <flux:table.columns>
                        <flux:table.column class="table-stt" align="center">STT</flux:table.column>
                        <flux:table.column>Ngành / Chương trình</flux:table.column>
                        <flux:table.column>{{ __('Method') }}</flux:table.column>
                        <flux:table.column>{{ __('Scoring contract') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Quota') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Minimum score') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($previewData['programs'] as $program)
                            <flux:table.row :key="'program-'.$program['id']">
                                <flux:table.cell class="table-stt" align="center">{{ $loop->iteration }}</flux:table.cell>
                                <flux:table.cell><span class="font-semibold">{{ $programLabels->get($program['id'])?->major?->name ?? 'Chương trình không còn tồn tại' }}</span><p class="text-sm text-zinc-500">#{{ $program['id'] }}</p></flux:table.cell>
                                <flux:table.cell>{{ $programLabels->get($program['id'])?->admissionMethod?->name ?? $program['method'] }}<p class="text-sm text-zinc-500">{{ $program['method'] }}</p></flux:table.cell>
                                <flux:table.cell><flux:badge :color="$program['type'] === 'unsupported' ? 'amber' : 'zinc'">{{ match ($program['type']) { 'weighted' => 'Điểm có trọng số', 'unsupported' => 'Chưa được hỗ trợ', 'scalar' => 'Điểm đơn', default => 'Quy tắc tính điểm' } }}</flux:badge></flux:table.cell>
                                <flux:table.cell align="end">{{ $program['quota'] }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $program['minimum_score'] === null ? __('No threshold') : App\Support\DisplayNumber::decimal($program['minimum_score']) }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row><flux:table.cell colspan="6">{{ __('No participating programs available.') }}</flux:table.cell></flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
            <flux:text>{{ __('Quota is total capacity and will not be decremented. Equal scores crossing a quota boundary block the entire run.') }}</flux:text>
        </div>

        @if ($previewData['blockers'] !== [])
            <flux:callout variant="warning">
                <flux:heading>{{ __('Processing blocked') }}</flux:heading>
                <ul class="mt-2 list-inside list-disc space-y-2">
                    @foreach ($previewData['blockers'] as $index => $blocker)
                        <li wire:key="blocker-{{ $index }}">{{ __($blocker) }}</li>
                    @endforeach
                </ul>
            </flux:callout>
        @elseif ($previewData['completed'])
            <flux:callout variant="success">{{ __('This round has a completed immutable run. Existing decisions are shown without recalculation.') }}</flux:callout>
        @else
            <flux:callout variant="success">{{ __('Ready for confirmation. Current records will be checked again before any decisions are saved.') }}</flux:callout>
            <div><flux:button variant="primary" wire:click="confirm" wire:loading.attr="disabled">{{ __('Review processing confirmation') }}</flux:button></div>
        @endif
    @else
        <div class="admission-panel p-6 text-base leading-7 text-zinc-600 dark:text-zinc-300">Chọn đợt tuyển sinh và kiểm tra điều kiện để xem hồ sơ, chỉ tiêu và các vấn đề cần xử lý trước khi xét tuyển.</div>
    @endif

    @if ($summary !== [])
        <div class="space-y-4 admission-panel p-5" role="status">
            <flux:heading size="lg">{{ __('Saved admission summary') }}</flux:heading>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (['run_id' => 'Run', 'included' => 'Completed applications', 'excluded_drafts' => 'Excluded drafts at processing', 'wishes' => 'Processed wishes', 'results' => 'Results saved', 'admitted' => 'Admitted wishes', 'not_admitted' => 'Not admitted wishes', 'decided_at' => 'Decision time'] as $field => $label)
                    <div class="workspace-stat" wire:key="summary-{{ $field }}"><dt class="text-sm text-zinc-500">{{ __($label) }}</dt><dd class="mt-2 break-words text-lg font-semibold">{{ $field === 'decided_at' ? Illuminate\Support\Carbon::parse($summary[$field])->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') : $summary[$field] }}</dd></div>
                @endforeach
            </dl>
            <flux:text>{{ __('Results remain unpublished and unconfirmed. The round remains processing until the publication phase.') }}</flux:text>
        </div>
    @endif

    <flux:modal wire:model="showConfirmation" class="w-full md:max-w-xl">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Confirm admission processing') }}</flux:heading>
            <flux:text>{{ __('Process round') }} #{{ $roundId }}? {{ __('This saves immutable decisions for every participating wish and completes the verified applications. All changes succeed together or are rolled back.') }}</flux:text>
            <flux:text>{{ __('Processing runs synchronously. Wait for the saved summary before leaving this page.') }}</flux:text>
            <flux:error name="engine" />
            <div class="flex flex-wrap justify-end gap-3">
                <flux:modal.close><flux:button wire:loading.attr="disabled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="process" wire:loading.attr="disabled">{{ __('Process admission round') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
