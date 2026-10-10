<div class="flex flex-col gap-5">
    <flux:heading size="lg">Quản lý quy tắc đánh giá</flux:heading>
    <flux:callout>Chỉ cấu hình và pin quy tắc cho đăng ký native. Chưa tính điểm, phân bổ hoặc công bố kết quả. Phê duyệt áp dụng nội dung đã lưu; hãy lưu mọi thay đổi trước khi duyệt.</flux:callout>
    @if ($program)
        <flux:text>{{ $program->major->name }} · {{ $program->admissionMethod->name }} · Chương trình #{{ $program->id }}</flux:text>
        <flux:text>Rule đang gắn: {{ $program->evaluationRule ? '#'.$program->evaluationRule->id.' / v'.$program->evaluationRule->version.' / '.(['draft' => 'Nháp', 'approved' => 'Đã duyệt', 'retired' => 'Ngừng sử dụng'][$program->evaluationRule->status] ?? $program->evaluationRule->status) : 'Chưa có rule đã duyệt' }}</flux:text>
    @endif
    <div class="overflow-x-auto">
        <flux:table :paginate="$rules">
            <flux:table.columns>
                <flux:table.column>Phương thức / Template</flux:table.column>
                <flux:table.column>Phiên bản / Trạng thái</flux:table.column>
                <flux:table.column>Phê duyệt / Số chương trình</flux:table.column>
                <flux:table.column>Thao tác</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($rules as $rule)
                    <flux:table.row :key="$rule->id">
                        <flux:table.cell>{{ $rule->admissionMethod->name }}<div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $rule->template_identifier }} / v{{ $rule->template_version }}</div></flux:table.cell>
                        <flux:table.cell>v{{ $rule->version }} · {{ ['draft' => 'Nháp', 'approved' => 'Đã duyệt', 'retired' => 'Ngừng sử dụng'][$rule->status] ?? $rule->status }}</flux:table.cell>
                        <flux:table.cell>{{ $rule->approved_at?->format('d/m/Y H:i') ?? 'Chưa duyệt' }} · {{ $rule->programs_count }} chương trình</flux:table.cell>
                        <flux:table.cell><flux:button size="sm" wire:click="selectRule({{ $rule->id }})">Chọn</flux:button></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="4">Chưa có quy tắc.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
    <flux:button wire:click="newDraft">Tạo bản nháp</flux:button>
    <form wire:submit="saveDraft" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <flux:select wire:model="methodId" label="Phương thức" :disabled="$program !== null || $selected !== null">
            <option value="">Chọn phương thức</option>
            @foreach ($methods as $method)<option wire:key="rule-method-{{ $method->id }}" value="{{ $method->id }}">{{ $method->name }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="template" label="Template" :disabled="$selected !== null">
            @foreach ($templates as $identifier => $definition)
                @if ($definition['supported'])<option wire:key="rule-template-{{ $identifier }}" value="{{ $identifier }}">{{ $definition['name'] }} / v1</option>@endif
            @endforeach
        </flux:select>
        @for ($index = 0; $index < 3; $index++)
            <flux:select wire:key="rule-subject-{{ $index }}" wire:model="payload.subjects.{{ $index }}" label="Môn {{ $index + 1 }} (hệ số 1)" :disabled="$selected !== null && $selected->status !== 'draft'">
                <option value="">Chọn môn</option>
                @foreach ($subjects as $code => $name)<option wire:key="rule-subject-{{ $index }}-{{ $code }}" value="{{ $code }}">{{ $name }}</option>@endforeach
            </flux:select>
        @endfor
        <flux:input type="number" wire:model="payload.source_year" label="Năm thi THPT / năm tốt nghiệp học bạ" :disabled="$selected !== null && $selected->status !== 'draft'" />
        @if ($template === 'TRANSCRIPT_SCORE')
            <flux:select wire:model="payload.grade_level" label="Điểm cả năm lớp (không theo học kỳ)" :disabled="$selected !== null && $selected->status !== 'draft'">
                <option value="">Chọn lớp</option><option value="10">10</option><option value="11">11</option><option value="12">12</option>
            </flux:select>
        @endif
        <flux:input type="number" step="0.001" wire:model="payload.minimum_subject_score" label="Ngưỡng từng môn (0–10)" :disabled="$selected !== null && $selected->status !== 'draft'" />
        <flux:input type="number" step="0.001" wire:model="payload.minimum_total_score" label="Ngưỡng tổng ba môn (0–30)" :disabled="$selected !== null && $selected->status !== 'draft'" />
        <div class="sm:col-span-2"><flux:textarea wire:model="payload.policy_reference" label="Căn cứ chính sách phê duyệt công thức và ngưỡng" :disabled="$selected !== null && $selected->status !== 'draft'" /></div>
        <div class="sm:col-span-2"><flux:textarea wire:model="reason" label="Lý do tạo / sửa / ngừng sử dụng / gắn rule" /></div>
        @if ($selected)
            <div class="sm:col-span-2 text-sm text-zinc-600 dark:text-zinc-300">Ranking contract: {{ $selected->ranking_contract }} / v{{ $selected->ranking_contract_version }}. Chỉ so sánh trong cùng phiên bản rule; không so sánh giữa phương thức hoặc thang điểm.</div>
        @endif
        <div class="sm:col-span-2"><flux:error name="rules" /><flux:error name="reason" /><flux:error name="payload" /><flux:error name="payload.subjects" /></div>
        <div class="flex flex-wrap gap-2 sm:col-span-2">
            @if ($selected === null || $selected->status === 'draft')
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Lưu nháp</flux:button>
            @endif
            @if ($selected?->status === 'draft')<flux:button type="button" wire:click="approve" wire:loading.attr="disabled">Phê duyệt bản đã lưu</flux:button>@endif
            @if ($selected !== null && $selected->status !== 'draft')<flux:button type="button" wire:click="newVersion" wire:loading.attr="disabled">Tạo phiên bản mới</flux:button>@endif
            @if ($selected?->status === 'approved')
                <flux:button type="button" wire:click="retire" wire:loading.attr="disabled">Ngừng sử dụng</flux:button>
                @if ($program)<flux:button type="button" wire:click="bind" wire:loading.attr="disabled">Gắn phiên bản này vào chương trình</flux:button>@endif
            @endif
        </div>
    </form>
    @foreach ($templates as $identifier => $definition)
        @if (! $definition['supported'])<flux:callout wire:key="unsupported-template-{{ $identifier }}">{{ $definition['name'] }} — UNSUPPORTED: {{ $definition['reason'] }}</flux:callout>@endif
    @endforeach
</div>
