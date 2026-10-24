<div class="admission-workspace flex min-w-0 flex-col gap-5">
    <flux:heading size="lg">Đăng ký nguyện vọng theo ngành</flux:heading>
    <flux:text>{{ $round->code }} · {{ $round->name }}</flux:text>
    <flux:callout>Chỉ kích hoạt đợt thử nghiệm riêng. Không chuyển đổi hồ sơ theo chương trình và không tự tính điểm, phân bổ hoặc công bố. Trạng thái sẵn sàng không tự mở đăng ký; cần bật tính năng sau khi kiểm tra điều kiện.</flux:callout>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <flux:text>Chế độ: {{ __($check['state']) }} · Điều kiện: {{ __($check['status']) }}</flux:text>
        <flux:text>Ngành sẵn sàng: {{ $check['ready'] }} · Chưa sẵn sàng: {{ $check['not_ready'] }}</flux:text>
        <flux:text>Hồ sơ theo chương trình: {{ $check['legacy'] }} · Theo ngành: {{ $check['native'] }}</flux:text>
        <flux:text>Thời gian nhận: {{ $round->start_date->format('d/m/Y H:i') }} – {{ $round->end_date->format('d/m/Y H:i') }} · {{ $check['time_open'] ? 'Đang nhận' : 'Chưa tới thời gian, đã kết thúc hoặc vòng đời chưa mở' }}</flux:text>
        <flux:text>Người kích hoạt: {{ $activator?->name ?? 'Chưa kích hoạt' }}</flux:text>
        <flux:text>Thời điểm: {{ $round->native_activated_at?->format('d/m/Y H:i') ?? 'Chưa kích hoạt' }}</flux:text>
    </div>
    @foreach ($check['blockers'] as $index => $blocker)
        <flux:callout wire:key="native-blocker-{{ $index }}" variant="warning">{{ $blocker }}</flux:callout>
    @endforeach
    @foreach ($check['offerings'] as $offering)
        <div wire:key="native-offering-state-{{ $offering['id'] }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
            <flux:text>{{ $offering['name'] }}: {{ __($offering['status']) }} · {{ $offering['total'] }} phương thức · {{ $offering['approved'] }} hợp lệ · {{ $offering['missing'] }} thiếu · {{ $offering['unsupported'] }} chưa hỗ trợ</flux:text>
            @foreach ($offering['methods'] as $method)
                <flux:text wire:key="native-method-state-{{ $offering['id'] }}-{{ $method['program_id'] }}">{{ $method['name'] }}: {{ $method['reason'] }}</flux:text>
            @endforeach
        </div>
    @endforeach
    <flux:error name="native" />
    <div class="flex flex-wrap gap-3">
        <flux:button wire:click="checkConditions" wire:loading.attr="disabled">Kiểm tra điều kiện</flux:button>
        @if ($check['state'] === 'legacy')
            <flux:button wire:click="confirm('prepare')" :disabled="$check['conflicts'] !== []">Chuẩn bị đăng ký theo ngành</flux:button>
        @elseif (in_array($check['state'], ['native_draft', 'native_closed'], true))
            <flux:button variant="primary" wire:click="confirm('activate')" :disabled="$check['blockers'] !== []">Kích hoạt đăng ký thử nghiệm</flux:button>
        @elseif ($check['state'] === 'native_open')
            <flux:button wire:click="confirm('close')">Đóng đăng ký</flux:button>
        @endif
    </div>
    <flux:modal wire:model="showConfirmation" class="w-full md:max-w-xl">
        <form wire:submit="apply" class="flex flex-col gap-4">
            <flux:heading>Xác nhận {{ ['prepare' => 'chuẩn bị', 'activate' => 'kích hoạt', 'close' => 'đóng'][$pendingAction] ?? '' }} đăng ký theo ngành</flux:heading>
            <flux:text>Phạm vi: {{ $round->code }} · {{ $round->name }}. Không thay đổi thời gian hoặc cấu hình bật tính năng. Không thể chuyển về đăng ký theo chương trình sau bước chuẩn bị.</flux:text>
            <flux:input wire:model="confirmationCode" label="Nhập chính xác mã đợt" />
            <flux:textarea wire:model="reason" label="Lý do thay đổi" />
            <flux:checkbox wire:model="confirmed" label="Tôi xác nhận đúng đợt thử nghiệm và phạm vi đăng ký nguyện vọng, chưa thực hiện xét tuyển chính thức." />
            <flux:error name="native" /><flux:error name="confirmed" /><flux:error name="reason" />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Xác nhận thay đổi</flux:button>
        </form>
    </flux:modal>
</div>
