<div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
    <flux:heading size="lg">Tính điểm xét tuyển Native theo phương thức</flux:heading>
    <flux:text>Đánh giá nội bộ theo rule đã pin. Chưa xếp hạng, phân bổ chỉ tiêu hoặc quyết định trúng tuyển.</flux:text>
    @if (!$available)
        <flux:callout variant="warning">Migration tính điểm native chưa được áp dụng.</flux:callout>
    @endif
    <flux:error name="nativeScoring" />
    <flux:error name="sourceVerification" />
    <flux:heading>Nguồn điểm THPT / học bạ cần kiểm tra minh chứng</flux:heading>
    @foreach (['thpt' => $examSources, 'transcript' => $transcriptSources] as $type => $sources)
        @forelse ($sources as $source)
            <div wire:key="source-{{ $type }}-{{ $source->id }}" class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text>{{ $type === 'thpt' ? 'THPT' : 'Học bạ' }} #{{ $source->id }} · Năm {{ $type === 'thpt' ? $source->exam_year : $source->graduation_year }} · {{ $source->getRawOriginal('status') }}</flux:text>
                <div class="flex flex-wrap gap-2">
                    @foreach (($type === 'thpt' ? $source->subjectScores : $source->scores) as $score)
                        <flux:badge>{{ $score->subject_code }}{{ $type === 'transcript' ? ' / lớp '.$score->grade_level : '' }}: {{ $score->getRawOriginal('score') }}</flux:badge>
                    @endforeach
                </div>
                @if ($source->evidence_path)
                    <flux:button size="sm" :href="route('candidate.admission-information.evidence', ['type' => $type === 'thpt' ? 'exam-results' : 'transcripts', 'record' => $source->id])" target="_blank">Xem minh chứng</flux:button>
                @endif
                @if ($type === 'transcript')
                    @foreach ($source->evidenceImages as $image)
                        <flux:button size="sm" :href="route('candidate.admission-information.evidence', ['type' => 'transcript-images', 'record' => $image->id])" target="_blank">Ảnh học bạ #{{ $image->id }}</flux:button>
                    @endforeach
                @endif
                @if ($canScore && $source->getRawOriginal('status') === 'pending')
                    <flux:button size="sm" wire:click="confirmSource('{{ $type }}', {{ $source->id }})">Xác minh nguồn</flux:button>
                @endif
            </div>
        @empty
            <flux:text>{{ $type === 'thpt' ? 'Chưa có nguồn THPT.' : 'Chưa có nguồn học bạ.' }}</flux:text>
        @endforelse
    @endforeach
    <flux:modal wire:model="showSourceConfirmation" class="space-y-4">
        <flux:heading>Xác minh nguồn {{ $sourceType }} #{{ $sourceId }}</flux:heading>
        <flux:checkbox wire:model="sourceConfirmed" label="Tôi đã đối chiếu điểm, năm/lớp và ảnh minh chứng của nguồn này" />
        <flux:error name="sourceConfirmed" /><flux:error name="sourceVerification" />
        <flux:button wire:click="verifySource" variant="primary" wire:loading.attr="disabled">Xác nhận nguồn đã kiểm tra</flux:button>
    </flux:modal>
    @if ($canScore)
        <form wire:submit="run" class="flex flex-col gap-3 sm:items-start">
            <flux:checkbox wire:model="confirmed" label="Xác nhận tính lại điểm từng phương thức từ nguồn đã xác minh" />
            <flux:error name="confirmed" />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Chạy tính điểm Native</flux:button>
        </form>
    @endif
    @if ($snapshot)
        <flux:text>Snapshot V{{ $snapshot->submission_version }} · {{ $snapshot->sealed_at ? 'Đã niêm phong' : 'Chưa niêm phong' }}</flux:text>
        @foreach ($snapshot->entries as $entry)
            <flux:heading>NV{{ $entry->priority }} — {{ $entry->payload['major_name'] }}</flux:heading>
            <div class="grid gap-3 md:grid-cols-2">
                @foreach ($entry->bindings as $binding)
                    @php($evaluation = $evaluations->get($binding->id))
                    <div wire:key="evaluation-{{ $binding->id }}" class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:heading>{{ $binding->catalog_reference['method_name'] }}</flux:heading>
                        <flux:text>Rule #{{ $binding->evaluation_rule_version_id }}</flux:text>
                        @if ($evaluation)
                            <flux:badge>{{ ['eligible' => 'Đủ điều kiện', 'ineligible' => 'Không đủ điều kiện', 'pending_data' => 'Chờ dữ liệu', 'unsupported' => 'Chưa hỗ trợ', 'needs_resolution' => 'Cần xử lý nguồn/rule'][$evaluation->status] }}</flux:badge>
                            <flux:text>Điểm: {{ $evaluation->score ?? '—' }} / 30</flux:text>
                            @foreach ($evaluation->reasons as $reason)<flux:text>{{ $reason }}</flux:text>@endforeach
                            <flux:text>{{ $evaluation->evaluated_at->format('d/m/Y H:i:s') }} · {{ $evaluation->algorithm_version }}</flux:text>
                            <details class="text-sm text-zinc-600 dark:text-zinc-300">
                                <summary>Nguồn điểm và dấu vết đánh giá</summary>
                                <pre class="mt-2 overflow-x-auto whitespace-pre-wrap">{{ json_encode($evaluation->provenance, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </details>
                        @else
                            <flux:text>Chưa tính điểm.</flux:text>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    @else
        <flux:text>Chưa có snapshot để đánh giá.</flux:text>
    @endif
</div>
