<div>
    <flux:heading>Phiên bản kết quả Native</flux:heading>
    <flux:error name="nativeResults" />
    <flux:error name="allocation" />
    @if ($allocationAvailable)
        <flux:heading>Chính sách phối hợp Native</flux:heading>
        <flux:text>Thứ tự phương thức chỉ giải quyết ưu tiên chưa xác định và chọn binding. Không thay đổi thứ hạng hợp lệ. Phê duyệt trước khi đợt bắt đầu và có hồ sơ.</flux:text>
        <flux:input wire:model="methodOrder" label="Mã phương thức theo thứ tự đã phê duyệt, cách nhau bằng dấu phẩy" />
        <flux:input wire:model="policyReference" label="Căn cứ chính sách phối hợp" />
        <flux:textarea wire:model="ruleEquivalences" label="Nhóm phiên bản rule tương đương (không bắt buộc)" description="Mỗi dòng là các ID phiên bản cách nhau bằng dấu phẩy. V1 chỉ chấp nhận các phiên bản có cùng nội dung và ranking contract." />
        <flux:button wire:click="draftPolicy">Tạo draft chính sách</flux:button>
        @foreach ($allocationPolicies as $policy)
            <flux:text>Chính sách V{{ $policy->version }} · {{ $policy->status }} · {{ $policy->getAttribute('payload')['policy_reference'] }}</flux:text>
            <flux:text>Thứ tự phương thức: {{ implode(', ', array_map(fn ($id) => $allocationMethods->get($id, 'Không còn trong catalog'), $policy->getAttribute('payload')['method_priority'])) }} · Đồng điểm chưa xử lý sẽ bị chặn.</flux:text>
            <flux:text>Nhóm rule tương đương: {{ json_encode($policy->getAttribute('payload')['rule_equivalences']) }} · Người phê duyệt: {{ $policy->approved_by ?? '—' }} · {{ $policy->approved_at?->format('d/m/Y H:i:s') ?? 'Chưa phê duyệt' }}</flux:text>
            @if ($policy->status === 'draft')
                <flux:button wire:click="approvePolicy({{ $policy->id }}, '{{ $policy->content_hash }}')">Phê duyệt chính sách</flux:button>
            @endif
        @endforeach
        <flux:checkbox wire:model="confirmed" label="Tôi xác nhận chính sách hoặc thao tác tạo phiên bản kết quả Native" />
        <flux:error name="confirmed" />
        <flux:button wire:click="allocate" wire:loading.attr="disabled">Chạy phân bổ Native — tạo phiên bản draft</flux:button>
    @else
        <flux:text>Migration chính sách phối hợp chưa được áp dụng. Phân bổ Native chưa khả dụng.</flux:text>
    @endif
    @if (!$available)
        <flux:text>Migration phiên bản kết quả Native chưa được áp dụng.</flux:text>
    @else
        @forelse ($versions as $version)
            <div wire:key="native-result-version-{{ $version->id }}">
                <flux:heading>V{{ $version->version }} · {{ $version->status }} · {{ $version->entries->count() }} quyết định</flux:heading>
                <flux:text>{{ $version->algorithm_version }} · {{ $version->policy_reference }}</flux:text>
                <flux:text>Phê duyệt: {{ $version->approved_at?->format('d/m/Y H:i:s') ?? '—' }} · Công bố: {{ $version->published_at?->format('d/m/Y H:i:s') ?? '—' }}</flux:text>
                <flux:button wire:click="select({{ $version->id }})">Kiểm tra phiên bản</flux:button>
                @if ($versionId === $version->id)
                    @foreach ($blockers as $blocker)<flux:text>{{ $blocker }}</flux:text>@endforeach
                    @foreach ($version->entries as $entry)
                        <flux:text>Hồ sơ #{{ $entry->application_id }} · {{ $entry->decision }} · {{ $entry->score ?? '—' }} · {{ $entry->reason }}</flux:text>
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
            <flux:text>Chưa có đề xuất kết quả từ thuật toán Native đã kiểm chứng. Không nhập tay người trúng tuyển.</flux:text>
        @endforelse
    @endif
</div>
