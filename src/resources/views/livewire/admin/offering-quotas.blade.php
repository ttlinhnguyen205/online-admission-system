<section class="mt-6 space-y-5 rounded-xl border border-zinc-200 bg-white p-4 sm:p-6 dark:border-zinc-700 dark:bg-zinc-900" aria-label="Quản lý chỉ tiêu ngành">
    <div>
        <flux:heading size="lg">Chỉ tiêu: {{ $offering->major->name }}</flux:heading>
        <flux:text>{{ $offering->admissionRound->name }} · {{ $offering->major->code }}</flux:text>
        <flux:callout class="mt-3">Phiên bản chỉ tiêu phục vụ quản lý Q/q. Phê duyệt không kích hoạt phiên bản và không thay đổi chỉ tiêu engine legacy. Phần chưa phân bổ không được tự sử dụng.</flux:callout>
    </div>
    <flux:error name="quota" />
    <div class="flex flex-wrap items-end gap-3">
        <flux:input wire:model="quotaForm.reason" label="Lý do tạo / điều chỉnh phiên bản" class="min-w-60 flex-1" />
        <flux:button wire:click="createDraft" wire:loading.attr="disabled" class="bg-[#9D2036]! text-white!">Tạo bản nháp mới</flux:button>
    </div>
    <flux:error name="reason" />
    <flux:error name="quotaForm.reason" />
    <div class="flex flex-wrap gap-2">
        @forelse ($versions as $version)
            <flux:button wire:key="quota-version-{{ $version->id }}" size="sm" wire:click="selectVersion({{ $version->id }})">
                V{{ $version->version }} · {{ ['draft'=>'Bản nháp','approved'=>'Đã phê duyệt','retired'=>'Đã ngừng sử dụng'][$version->status] }}
            </flux:button>
        @empty
            <flux:text>Chưa có phiên bản chỉ tiêu. Không tự chuyển chỉ tiêu program legacy sang Q/q.</flux:text>
        @endforelse
    </div>
    @if ($selected)
        <div wire:key="quota-editor-{{ $selected->id }}" class="space-y-4">
            <flux:heading>Phiên bản {{ $selected->version }} · {{ ['draft'=>'Bản nháp','approved'=>'Đã phê duyệt','retired'=>'Đã ngừng sử dụng'][$selected->status] }}</flux:heading>
            <flux:input type="number" min="0" step="1" wire:model.live="quotaForm.total_quota" label="Tổng chỉ tiêu ngành Q" :disabled="$selected->status !== 'draft'" />
            <flux:error name="total_quota" />
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b border-zinc-200 dark:border-zinc-700"><th class="p-3">Phương thức xét tuyển</th><th class="p-3">Chỉ tiêu tối đa q</th></tr></thead>
                    <tbody>
                        @foreach ($quotaForm['limits'] as $index => $row)
                            @php
                                $program = $programs->firstWhere('id', $row['program_id']);
                            @endphp
                            <tr wire:key="quota-limit-{{ $selected->id }}-{{ $row['program_id'] }}" class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="p-3">{{ $program?->admissionMethod?->name ?? 'Phương thức đã thay đổi' }} <span class="text-xs text-zinc-500">{{ $program?->admissionMethod?->code }}</span></td>
                                <td class="p-3">
                                    <flux:input type="number" min="0" step="1" wire:model.live="quotaForm.limits.{{ $index }}.quota" placeholder="Chưa cấu hình q" :disabled="$selected->status !== 'draft'" />
                                    <flux:error name="limits.{{ $index }}.quota" />
                                    <flux:error name="limits.{{ $index }}.program_id" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @php
                $configured = collect($quotaForm['limits'])->filter(fn ($row) => is_numeric($row['quota']));
                $sum = $configured->sum(fn ($row) => (float) $row['quota']);
                $total = is_numeric($quotaForm['total_quota']) ? (float) $quotaForm['total_quota'] : 0;
            @endphp
            <div class="grid gap-3 sm:grid-cols-3">
                <flux:text>Đã phân bổ: <strong>{{ number_format($sum, 0, ',', '.') }}</strong></flux:text>
                <flux:text>Chưa phân bổ: <strong>{{ number_format(max(0, $total - $sum), 0, ',', '.') }}</strong></flux:text>
                <flux:text>Chưa cấu hình q: <strong>{{ count($quotaForm['limits']) - $configured->count() }}</strong></flux:text>
            </div>
            @if ($sum > $total)<flux:callout>Tổng q đang vượt Q. Không thể lưu hoặc phê duyệt.</flux:callout>@endif
            <div class="flex flex-wrap gap-2">
                @if ($selected->status === 'draft')
                    <flux:button wire:click="saveDraft" wire:loading.attr="disabled">Lưu bản nháp</flux:button>
                    <flux:button wire:click="approve" wire:loading.attr="disabled" class="bg-[#9D2036]! text-white!">Phê duyệt bản nháp đã lưu</flux:button>
                @elseif ($selected->status === 'approved')
                    <flux:button wire:click="retire" wire:loading.attr="disabled">Ngừng sử dụng phiên bản</flux:button>
                @endif
            </div>
            <flux:text>Phê duyệt: {{ $selected->approved_at?->format('d/m/Y H:i') ?? 'Chưa phê duyệt' }}. Không có phiên bản active tự động.</flux:text>
        </div>
    @endif
    <div wire:loading class="text-sm text-zinc-500" role="status">Đang xử lý chỉ tiêu…</div>
</section>
