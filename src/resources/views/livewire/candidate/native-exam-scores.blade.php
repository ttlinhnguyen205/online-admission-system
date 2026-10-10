<div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
    <flux:heading size="lg">Điểm thi THPT A00 cho đăng ký Native</flux:heading>
    <flux:text>Nhập đúng điểm thi và ảnh minh chứng. Nguồn này chờ xác minh, không tự tính điểm hoặc nộp hồ sơ.</flux:text>
    @foreach ($records as $record)
        <div wire:key="native-thpt-{{ $record->id }}" class="space-y-2">
            <flux:text>Nguồn #{{ $record->id }} · Năm {{ $record->exam_year }} · {{ $record->getRawOriginal('status') }}</flux:text>
            @foreach ($record->subjectScores as $score)<flux:text>{{ $score->subject_name }}: {{ $score->score }}</flux:text>@endforeach
            <flux:button size="sm" :href="route('candidate.admission-information.evidence', ['type' => 'exam-results', 'record' => $record->id])" target="_blank">Xem minh chứng</flux:button>
        </div>
    @endforeach
    <form wire:submit="save" class="grid gap-3 sm:grid-cols-2">
        <flux:input type="number" wire:model="year" label="Năm thi" />
        @foreach (['MATH' => 'Toán', 'PHYSICS' => 'Vật lý', 'CHEMISTRY' => 'Hóa học'] as $code => $label)
            <flux:input type="number" step="0.001" min="0" max="10" wire:model="scores.{{ $code }}" :label="$label" />
        @endforeach
        <flux:input type="file" wire:model="evidence" label="Ảnh minh chứng JPG/PNG (tối đa 2 MB)" />
        <div><flux:error name="year" /><flux:error name="scores" /><flux:error name="evidence" /></div>
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Lưu nguồn THPT mới</flux:button>
    </form>
</div>
