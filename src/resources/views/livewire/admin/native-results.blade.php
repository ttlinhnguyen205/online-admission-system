<div>
    <flux:heading>Phiên bản kết quả xét tuyển theo ngành</flux:heading>
    <flux:error name="nativeResults" />
    <flux:error name="allocation" />
    @if ($allocationAvailable)
        <flux:heading>Chính sách phối hợp phương thức xét tuyển</flux:heading>
        <flux:text>Thứ tự phương thức giúp giải quyết ưu tiên chưa xác định và chọn phương thức phù hợp. Không thay đổi thứ hạng hợp lệ. Cần phê duyệt trước khi đợt bắt đầu và có hồ sơ.</flux:text>
        <flux:input wire:model="methodOrder" label="Mã phương thức theo thứ tự đã phê duyệt, cách nhau bằng dấu phẩy" />
        <flux:input wire:model="policyReference" label="Căn cứ chính sách phối hợp" />
        <flux:textarea wire:model="ruleEquivalences" label="Nhóm phiên bản quy tắc tương đương (không bắt buộc)" description="Mỗi dòng là các mã phiên bản cách nhau bằng dấu phẩy. Phiên bản 1 chỉ chấp nhận các quy tắc có cùng nội dung và cách xếp hạng." />
        <flux:button wire:click="draftPolicy">Tạo bản nháp chính sách</flux:button>
        @foreach ($allocationPolicies as $policy)
            <flux:text>Chính sách V{{ $policy->version }} · {{ __($policy->status) }} · {{ $policy->getAttribute('payload')['policy_reference'] }}</flux:text>
            <flux:text>Thứ tự phương thức: {{ implode(', ', array_map(fn ($id) => $allocationMethods->get($id, 'Không còn trong danh mục'), $policy->getAttribute('payload')['method_priority'])) }} · Đồng điểm chưa xử lý sẽ bị chặn.</flux:text>
            <flux:text>Nhóm quy tắc tương đương: {{ json_encode($policy->getAttribute('payload')['rule_equivalences']) }} · Người phê duyệt: {{ $policy->approved_by ?? '—' }} · {{ $policy->approved_at?->format('d/m/Y H:i:s') ?? 'Chưa phê duyệt' }}</flux:text>
            @if ($policy->status === 'draft')
                <flux:button wire:click="approvePolicy({{ $policy->id }}, '{{ $policy->content_hash }}')">Phê duyệt chính sách</flux:button>
            @endif
        @endforeach
        <flux:checkbox wire:model="confirmed" label="Tôi xác nhận chính sách hoặc thao tác tạo phiên bản kết quả xét tuyển theo ngành" />
        <flux:error name="confirmed" />
        <flux:button wire:click="allocate" wire:loading.attr="disabled">Chạy phân bổ theo ngành — tạo bản nháp kết quả</flux:button>
    @else
        <flux:text>Chưa cập nhật dữ liệu chính sách phối hợp. Chức năng phân bổ theo ngành chưa khả dụng.</flux:text>
    @endif
    @if (!$available)
        <flux:text>Chưa cập nhật dữ liệu phiên bản kết quả xét tuyển theo ngành.</flux:text>
    @else
        @forelse ($versions as $version)
            <div wire:key="native-result-version-{{ $version->id }}">
                <flux:heading>V{{ $version->version }} · {{ __($version->status) }} · {{ $version->entries->count() }} quyết định</flux:heading>
                <flux:text>{{ $version->algorithm_version }} · {{ $version->policy_reference }}</flux:text>
                <flux:text>Phê duyệt: {{ $version->approved_at?->format('d/m/Y H:i:s') ?? '—' }} · Công bố: {{ $version->published_at?->format('d/m/Y H:i:s') ?? '—' }}</flux:text>
                <flux:button wire:click="select({{ $version->id }})">Kiểm tra phiên bản</flux:button>
                @if ($versionId === $version->id)
                    @foreach ($blockers as $blocker)<flux:text>{{ __($blocker) }}</flux:text>@endforeach
                    @foreach ($version->entries as $entry)
                        <flux:text>Hồ sơ #{{ $entry->application_id }} · {{ __($entry->decision) }} · {{ $entry->score ?? '—' }} · {{ $entry->reason }}</flux:text>
                    @endforeach
                    <flux:checkbox wire:model="confirmed" label="Tôi xác nhận thao tác trên đúng phiên bản đã kiểm tra" />
                    <flux:error name="confirmed" />
                    @if ($version->status === 'draft')
                        <flux:button wire:click="approve" :disabled="$blockers !== []">Phê duyệt phiên bản</flux:button>
                        <flux:textarea wire:model="rejectionReason" label="Lý do từ chối phiên bản" />
                        <flux:button wire:click="reject">Từ chối phiên bản</flux:button>
                    @elseif ($version->status === 'approved')
                        <flux:button wire:click="publish" :disabled="$blockers !== []">Công bố phiên bản</flux:button>
                    @endif
                @endif
            </div>
        @empty
            <flux:text>Chưa có đề xuất kết quả từ thuật toán xét tuyển theo ngành đã kiểm chứng. Không nhập tay người trúng tuyển.</flux:text>
        @endforelse
    @endif
</div>
