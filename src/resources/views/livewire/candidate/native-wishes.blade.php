<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ $application->application_code }}</flux:heading>
        <flux:text>{{ $round->name }} · {{ App\Support\CandidateStatusLabels::application($application->status) }}</flux:text>
    </div>
    <flux:callout>Đăng ký theo ngành, xét nhiều phương thức. Chức năng phân bổ và công bố native chưa được kích hoạt.</flux:callout>
    <flux:text>Thời gian nhận hồ sơ: {{ $round->start_date->format('d/m/Y H:i') }} – {{ $round->end_date->format('d/m/Y H:i') }} ({{ config('app.timezone') }})</flux:text>
    @if ($round->nativeRegistrationState() === 'native_closed')
        <flux:callout>Đăng ký native đã đóng. Chỉ xem hồ sơ và lịch sử đã nộp.</flux:callout>
    @elseif ($round->nativeRegistrationState() !== 'native_open')
        <flux:callout>Đợt chưa kích hoạt đăng ký native. Chỉ xem hồ sơ.</flux:callout>
    @elseif (now()->lessThan($round->start_date))
        <flux:callout>Chưa tới thời gian nhận hồ sơ. Bắt đầu {{ $round->start_date->format('d/m/Y H:i') }}.</flux:callout>
    @elseif (now()->greaterThan($round->end_date))
        <flux:callout>Thời gian nhận hồ sơ đã kết thúc.</flux:callout>
    @endif
    @if ($checklist !== [])
        <flux:heading>Kiểm tra hồ sơ trước khi nộp</flux:heading>
        @foreach ($checklist as $field => $message)
            <flux:callout wire:key="native-checklist-{{ $field }}" variant="warning">{{ $message }}</flux:callout>
        @endforeach
    @endif
    @if (! App\Actions\NativeWishRegistration::enabled())
        <flux:callout>Đăng ký native đang tạm khóa. Lịch sử đã nộp được giữ nguyên.</flux:callout>
    @endif
    @if ($application->revision_reason)
        <flux:text>{{ $application->revision_reason }}</flux:text>
    @endif
    <flux:error name="wishes" /><flux:error name="profile" /><flux:error name="round" /><flux:error name="submission" />
    <flux:heading>Nguyện vọng theo thứ tự ưu tiên</flux:heading>
    <div><flux:button wire:click="reloadWishes" wire:loading.attr="disabled">Kiểm tra lại hồ sơ và phương thức</flux:button></div>
    @forelse ($wishes as $wish)
        <article wire:key="native-wish-{{ $wish->id }}" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 sm:flex-row sm:justify-between">
            <div>
                <flux:heading>NV{{ $wish->priority }} · {{ $wish->offering->major->code }} — {{ $wish->offering->major->name }}</flux:heading>
                <flux:text>Phương thức được chấp nhận: {{ $programs->get($wish->major_id, collect())->pluck('admissionMethod.name')->join(', ') ?: 'Chưa cấu hình' }}</flux:text>
            </div>
            @if ($editable)
                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" wire:click="moveWish({{ $wish->id }}, 'up')" :disabled="$loop->first" wire:loading.attr="disabled">Lên</flux:button>
                    <flux:button size="sm" wire:click="moveWish({{ $wish->id }}, 'down')" :disabled="$loop->last" wire:loading.attr="disabled">Xuống</flux:button>
                    <flux:button size="sm" wire:click="deleteWish({{ $wish->id }})" wire:confirm="Xóa nguyện vọng này? Lịch sử đã nộp vẫn được giữ nguyên." wire:loading.attr="disabled">Xóa</flux:button>
                </div>
            @endif
        </article>
    @empty
        <flux:text>Chưa có nguyện vọng.</flux:text>
    @endforelse
    @if ($editable)
        <form wire:submit="addWish" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:select wire:model="offeringId" label="Ngành đang mở trong đợt" required>
                <flux:select.option value="">Chọn ngành</flux:select.option>
                @foreach ($offerings as $offering)
                    <flux:select.option :value="$offering->id" wire:key="native-offering-{{ $offering->id }}">{{ $offering->major->code }} — {{ $offering->major->name }} ({{ $programs->get($offering->major_id)->pluck('admissionMethod.name')->join(', ') }})</flux:select.option>
                @endforeach
            </flux:select>
            <div><flux:button type="submit" variant="primary" wire:loading.attr="disabled">Thêm nguyện vọng</flux:button></div>
        </form>
        <flux:text>Nộp hồ sơ sẽ cố định toàn bộ phương thức và quy tắc đã phê duyệt. Thiếu quy tắc sẽ chặn nộp native; không tự loại phương thức vì chưa có điểm.</flux:text>
        <div><flux:button variant="primary" wire:click="submit" wire:confirm="Nộp hồ sơ và cố định phiên bản nguyện vọng?" wire:loading.attr="disabled">{{ $application->status === App\Enums\ApplicationStatus::NeedsRevision ? 'Nộp lại hồ sơ' : 'Nộp hồ sơ' }}</flux:button></div>
    @endif
    <flux:heading>Lịch sử đã nộp</flux:heading>
    @foreach ($snapshots as $snapshot)
        <details wire:key="submission-{{ $snapshot->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <summary>Phiên bản {{ $snapshot->submission_version }} · {{ $snapshot->submitted_at->format('d/m/Y H:i') }} · Quy tắc đã cố định</summary>
            @foreach ($snapshot->manifest as $entry)
                <flux:text>NV{{ $entry['priority'] }} · {{ $entry['major_name'] }} · {{ collect($entry['methods'])->pluck('method_name')->join(', ') }}</flux:text>
            @endforeach
        </details>
    @endforeach
    <div class="flex flex-wrap gap-3">
        <flux:button :href="route('candidate.applications.index')" wire:navigate>Danh sách hồ sơ</flux:button>
        <flux:button :href="route('candidate.profile.edit')" wire:navigate>Hồ sơ cá nhân</flux:button>
        <flux:button :href="route('candidate.applications.documents.index', $application->id)" wire:navigate>Tài liệu hồ sơ</flux:button>
    </div>
</div>
