<div data-admission-section="thpt-exam-scores" class="admission-panel min-w-0 space-y-5 p-5">
    <div>
        <flux:heading size="lg">Điểm thi tốt nghiệp THPT</flux:heading>
        <flux:text class="mt-2">Khai báo điểm các môn đã thi và ảnh minh chứng theo từng năm. Hệ thống lấy điểm theo tổ hợp môn trong quy tắc xét tuyển của từng ngành, sau khi dữ liệu được xác minh.</flux:text>
        <flux:text class="mt-2">Lưu điểm và minh chứng chưa phải là gửi hồ sơ. Hãy vào <a class="underline" href="{{ route('candidate.applications.index') }}" wire:navigate>Đăng ký nguyện vọng</a>, mở hồ sơ đúng đợt và chọn nộp hồ sơ. Hồ sơ cần bổ sung phải được nộp lại sau khi sửa.</flux:text>
    </div>
    @foreach ($records as $record)
        <article wire:key="native-thpt-{{ $record->id }}" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading level="3">Năm thi {{ $record->exam_year }}</flux:heading>
                <flux:badge :color="$record->getRawOriginal('status') === 'verified' ? 'green' : 'zinc'">{{ App\Support\CandidateStatusLabels::verification($record->status) }}</flux:badge>
            </div>
            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($record->subjectScores as $score)
                    <div wire:key="thpt-score-{{ $score->id }}" class="flex items-center justify-between gap-3 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800">
                        <dt class="text-sm text-zinc-600 dark:text-zinc-300">{{ $score->subject_name }}</dt>
                        <dd class="font-semibold tabular-nums">{{ App\Support\DisplayNumber::decimal($score->score) }}</dd>
                    </div>
                @endforeach
            </dl>
            @if ($record->rejection_reason)<flux:callout variant="warning">{{ $record->rejection_reason }}</flux:callout>@endif
            <div class="flex flex-wrap gap-2">
                @if ($record->evidence_path)
                    <flux:button size="sm" :href="route('candidate.admission-information.evidence', ['type' => 'exam-results', 'record' => $record->id])" target="_blank">Xem minh chứng</flux:button>
                @endif
                @can('update', $record)
                    <flux:button size="sm" wire:click="edit({{ $record->id }})">Chỉnh sửa</flux:button>
                @endcan
            </div>
        </article>
    @endforeach
    <form wire:submit="save" class="space-y-5">
        <flux:heading>{{ $recordId ? 'Chỉnh sửa điểm thi THPT' : 'Khai báo điểm thi THPT' }}</flux:heading>
        <flux:input type="number" wire:model="year" label="Năm thi" min="2000" max="2100" required />
        <fieldset class="space-y-3">
            <legend class="text-sm font-semibold">Chọn các môn đã thi</legend>
            <flux:text>Chỉ nhập điểm cho môn đã chọn. Môn không thi không được tính là 0 điểm.</flux:text>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($subjects as $code => $label)
                    <label wire:key="thpt-subject-choice-{{ $code }}" class="flex cursor-pointer items-center gap-3 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700">
                        <input type="checkbox" wire:click="toggleSubject('{{ $code }}')" @checked(array_key_exists($code, $scores)) class="size-4 accent-admission-blue" />
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            <flux:error name="scores" />
        </fieldset>
        @if ($scores !== [])
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($scores as $code => $value)
                    @if (isset($subjects[$code]))
                        <div wire:key="thpt-subject-input-{{ $code }}">
                            <flux:input type="number" step="0.001" min="0" max="10" wire:model="scores.{{ $code }}" :label="'Điểm môn '.$subjects[$code]" required />
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
        <flux:input type="file" wire:model="evidence" label="Ảnh minh chứng JPG/PNG (tối đa 2 MiB)" :description="$recordId ? 'Để trống nếu giữ nguyên ảnh minh chứng đã lưu.' : 'Bắt buộc có ảnh minh chứng khi khai báo mới.'" />
        <div class="flex flex-wrap gap-3">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save,evidence">{{ $recordId ? 'Lưu thay đổi' : 'Lưu điểm thi' }}</flux:button>
            @if ($recordId)
                <flux:button wire:click="resetEditor" wire:loading.attr="disabled" wire:target="save,evidence">Hủy chỉnh sửa</flux:button>
            @endif
        </div>
    </form>
</div>
