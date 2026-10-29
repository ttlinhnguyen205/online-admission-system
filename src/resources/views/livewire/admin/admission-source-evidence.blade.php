<div class="admission-panel space-y-4 p-5">
    <flux:heading size="lg">{{ __('Minh chứng thông tin xét tuyển') }}</flux:heading>
    <flux:text>{{ __('Nguồn dùng chung của thí sinh: kết quả kỳ thi, học bạ, chứng chỉ và diện ưu tiên. Xác minh minh chứng không tự tính điểm hoặc thay đổi kết quả xét tuyển.') }}</flux:text>
    @forelse ($application->getRelation('admissionSources') as $key => $source)
        @php
            $type = explode(':', $key)[0];
            $files = App\Support\AdmissionSourceEvidence::files($type, $source);
            $available = $files !== [] && ! collect($files)->contains('available', false);
        @endphp
        <article wire:key="admission-source-{{ $key }}" class="space-y-3 rounded-lg border border-zinc-200 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading level="3">{{ App\Support\AdmissionSourceEvidence::TYPES[$type] }} #{{ $source->id }}</flux:heading>
                <flux:badge>{{ App\Support\CandidateStatusLabels::verification($source->status) }}</flux:badge>
            </div>
            <flux:text>{{ __('Cập nhật') }}: {{ $source->updated_at?->format('d/m/Y H:i') }}</flux:text>
            @if ($type === 'exam-results')
                <flux:text>{{ $source->exam_type }} · {{ __('Năm thi') }} {{ $source->exam_year }} @if ($source->overall_score !== null) · {{ $source->overall_score }} @endif</flux:text>
                <div class="flex flex-wrap gap-3">@foreach ($source->subjectScores as $score)<span>{{ $score->subject_name }}: {{ App\Support\DisplayNumber::decimal($score->score) }}</span>@endforeach</div>
            @elseif ($type === 'transcripts')
                <flux:text>{{ $source->school_name }} · {{ __('Năm tốt nghiệp') }} {{ $source->graduation_year }}</flux:text>
                <div class="flex flex-wrap gap-3">@foreach ($source->scores as $score)<span>{{ $score->subject_name }} ({{ $score->grade_level }}): {{ App\Support\DisplayNumber::decimal($score->score) }}</span>@endforeach</div>
            @elseif ($type === 'certificates')
                <flux:text>{{ $source->getRawOriginal('certificate_type') }} · {{ $source->score }} · {{ $source->certificate_number }}</flux:text>
            @else
                <flux:text>{{ $source->getRawOriginal('claim_type') }} · {{ $source->claim_code }} · {{ $source->description }}</flux:text>
            @endif
            @if ($source->rejection_reason)<flux:callout variant="warning">{{ $source->rejection_reason }}</flux:callout>@endif
            <div class="flex flex-wrap gap-2">
                @foreach ($files as $file)
                    @if ($file['available'])
                        <flux:button size="sm" :href="route('candidate.admission-information.evidence', ['type' => $file['type'], 'record' => $file['id'], 'application' => $application->id])" target="_blank">{{ __('Xem minh chứng') }} {{ $loop->iteration }}</flux:button>
                    @endif
                @endforeach
                @if (! $available)<flux:badge color="rose">{{ __('Minh chứng bị thiếu hoặc không đọc được') }}</flux:badge>@endif
                @if ($reviewable && $source->getRawOriginal('status') === 'pending')
                    <flux:button size="sm" wire:click="confirm('verifyAdmissionSource', {{ $source->id }}, '{{ $type }}')" :disabled="$stale || ! $available" wire:loading.attr="disabled">{{ __('Xác minh minh chứng') }}</flux:button>
                    <flux:button size="sm" variant="danger" wire:click="confirm('rejectAdmissionSource', {{ $source->id }}, '{{ $type }}')" :disabled="$stale" wire:loading.attr="disabled">{{ __('Từ chối minh chứng') }}</flux:button>
                @endif
            </div>
        </article>
    @empty
        <flux:text>{{ __('Chưa có nguồn minh chứng thông tin xét tuyển cho hồ sơ đã gửi.') }}</flux:text>
    @endforelse
</div>
