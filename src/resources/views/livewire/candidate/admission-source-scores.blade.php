<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="lg">{{ __('Điểm và minh chứng từ Thông tin tuyển sinh') }}</flux:heading>
        <flux:button :href="route('candidate.admission-information.index')" wire:navigate>{{ __('Mở Thông tin tuyển sinh') }}</flux:button>
    </div>
    <flux:text>{{ __('Dữ liệu và trạng thái xác minh được hiển thị trực tiếp từ thông tin bạn đã khai báo. Không cần nhập lại điểm. Để chỉnh sửa, hãy mở Thông tin tuyển sinh.') }}</flux:text>
    <div class="admission-panel min-w-0 overflow-hidden">
        <flux:table :paginate="$sourceRecords" class="admin-records-table">
            <flux:table.columns>
                <flux:table.column class="table-stt">STT</flux:table.column>
                <flux:table.column>{{ __('Loại thông tin') }}</flux:table.column>
                <flux:table.column>{{ __('Điểm / Chi tiết') }}</flux:table.column>
                <flux:table.column>{{ __('Năm / Ngày cấp') }}</flux:table.column>
                <flux:table.column>{{ __('Minh chứng') }}</flux:table.column>
                <flux:table.column>{{ __('Trạng thái') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($sourceRecords as $item)
                    @php
                        $type = $item['type'];
                        $source = $item['source'];
                        $files = App\Support\AdmissionSourceEvidence::files($type, $source);
                    @endphp
                    <flux:table.row :key="'source-'.$type.'-'.$source->id">
                        <flux:table.cell class="table-stt" data-label="STT">{{ $sourceRecords->firstItem() + $loop->index }}</flux:table.cell>
                        <flux:table.cell data-label="Loại thông tin">
                            {{ App\Support\AdmissionSourceEvidence::TYPES[$type] }}
                            @if ($type === 'exam-results')<div>{{ strtoupper($source->exam_type->value) }}</div>@endif
                            @if ($type === 'certificates')<div>{{ strtoupper($source->certificate_type->value) }}</div>@endif
                        </flux:table.cell>
                        <flux:table.cell data-label="Điểm / Chi tiết">
                            @if ($type === 'exam-results')
                                @if ($source->overall_score !== null)<div>{{ __('Tổng điểm') }}: {{ App\Support\DisplayNumber::decimal($source->overall_score) }}</div>@endif
                                @foreach ($source->subjectScores as $score)<div>{{ $score->subject_name }} ({{ $score->subject_code }}): {{ App\Support\DisplayNumber::decimal($score->score) }}</div>@endforeach
                            @elseif ($type === 'transcripts')
                                <div>{{ $source->school_name }}</div>
                                @foreach ($source->scores as $score)<div>{{ $score->subject_name }} ({{ $score->subject_code }}, {{ __('Lớp') }} {{ $score->grade_level }}): {{ App\Support\DisplayNumber::decimal($score->score) }}</div>@endforeach
                            @elseif ($type === 'certificates')
                                {{ App\Support\DisplayNumber::decimal($source->score) }}
                            @else
                                <div class="max-w-sm whitespace-normal break-words">{{ $source->claim_code }} {{ $source->description }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell data-label="Năm / Ngày cấp">{{ match ($type) { 'exam-results' => $source->exam_year, 'transcripts' => $source->graduation_year, 'certificates' => $source->issued_at?->format('d/m/Y') ?? '—', default => '—' } }}</flux:table.cell>
                        <flux:table.cell data-label="Minh chứng">
                            @forelse ($files as $file)
                                @if ($file['available'])
                                    <flux:button size="sm" :href="route('candidate.admission-information.evidence', ['type' => $file['type'], 'record' => $file['id']])" target="_blank">{{ __('Xem minh chứng') }} {{ $loop->iteration }}</flux:button>
                                @else
                                    <flux:text>{{ __('Minh chứng bị thiếu hoặc không đọc được') }}</flux:text>
                                @endif
                            @empty
                                <flux:text>{{ __('Chưa có minh chứng') }}</flux:text>
                            @endforelse
                        </flux:table.cell>
                        <flux:table.cell data-label="Trạng thái">
                            <flux:badge>{{ App\Support\CandidateStatusLabels::verification($source->status) }}</flux:badge>
                            @if ($source->rejection_reason)<div class="mt-2 max-w-sm whitespace-normal break-words">{{ $source->rejection_reason }}</div>@endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="6">{{ __('Chưa có điểm hoặc minh chứng trong Thông tin tuyển sinh.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</div>
