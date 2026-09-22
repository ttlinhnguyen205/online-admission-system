<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><flux:heading size="xl" level="1">{{ __('Điểm & minh chứng') }}</flux:heading><flux:text class="mt-2">{{ __('Quản lý các điểm đã khai báo. Đây không phải là điểm xét tuyển được hệ thống tính.') }}</flux:text></div>
        @if ($profile)
            <flux:button variant="primary" wire:click="create">{{ __('Thêm điểm') }}</flux:button>
        @endif
    </div>
    <flux:error name="cleanup" />
    @if (! $profile)
        <flux:callout>{{ __('Hãy tạo hồ sơ cá nhân trước khi thêm điểm.') }} <a class="underline" href="{{ route('candidate.profile.edit') }}" wire:navigate>{{ __('Mở hồ sơ') }}</a></flux:callout>
    @else
        <div class="grid items-end gap-4 sm:grid-cols-[1fr_1fr_auto]">
            <flux:select wire:model.live="typeFilter" :label="__('Loại điểm')">
                <flux:select.option value="">{{ __('Tất cả loại điểm') }}</flux:select.option>
                @foreach (App\Livewire\Candidate\Scores::SCORE_TYPES as $value => $label)
                    <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="yearFilter" :label="__('Năm thi')">
                <flux:select.option value="">{{ __('Tất cả năm') }}</flux:select.option>
                @foreach ($availableYears as $year)
                    <flux:select.option :value="(string) $year">{{ $year }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button wire:click="resetFilters">{{ __('Đặt lại bộ lọc') }}</flux:button>
        </div>
        <div role="status" wire:loading.delay>{{ __('Đang cập nhật điểm...') }}</div>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:table :paginate="$records">
                <flux:table.columns>
                    <flux:table.column>{{ __('Loại điểm / Môn học') }}</flux:table.column><flux:table.column>{{ __('Điểm') }}</flux:table.column><flux:table.column>{{ __('Năm') }}</flux:table.column><flux:table.column>{{ __('Minh chứng') }}</flux:table.column><flux:table.column>{{ __('Trạng thái') }}</flux:table.column><flux:table.column>{{ __('Thao tác') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($records as $record)
                        <flux:table.row :key="$record->id">
                            <flux:table.cell><div>{{ App\Livewire\Candidate\Scores::SCORE_TYPES[$record->score_type] ?? $record->score_type }}</div><div class="text-xs">{{ $record->subject_code }} {{ $record->subject_name }}</div></flux:table.cell>
                            <flux:table.cell>{{ $record->score }}</flux:table.cell><flux:table.cell>{{ $record->exam_year }}</flux:table.cell>
                            <flux:table.cell>@if ($evidenceAvailability->get($record->id))<flux:button size="sm" :href="route('admission.scores.evidence', $record->id)" target="_blank">{{ __('Xem minh chứng') }}</flux:button>@else<flux:badge color="amber">{{ __('Chưa có minh chứng hợp lệ') }}</flux:badge>@endif</flux:table.cell>
                            <flux:table.cell><flux:badge :color="$record->verified ? 'green' : 'zinc'">{{ $record->verified ? __('Đã xác minh') : __('Chưa xác minh') }}</flux:badge></flux:table.cell>
                            <flux:table.cell>
                                @can('update', $record)<flux:button size="sm" wire:click="edit({{ $record->id }})">{{ __('Sửa') }}</flux:button>@endcan
                                @can('delete', $record)<flux:button size="sm" variant="ghost" wire:click="confirmDeletion({{ $record->id }})">{{ __('Xóa') }}</flux:button>@endcan
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="6"><div class="py-10 text-center">{{ __('Không tìm thấy điểm nào. Hãy thêm điểm hoặc điều chỉnh bộ lọc.') }}</div></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
    <flux:modal wire:model="showEditor" class="w-full md:max-w-xl">
        <form wire:submit="save" class="flex flex-col gap-5">
            <flux:heading size="lg">{{ $recordId ? __('Sửa điểm') : __('Thêm điểm') }}</flux:heading>
            <flux:error name="form" />
            <flux:select wire:model.live="form.score_type" :label="__('Loại điểm *')" required>
                <flux:select.option value="">{{ __('Chọn loại điểm') }}</flux:select.option>
                @foreach (App\Livewire\Candidate\Scores::SCORE_TYPES as $value => $label)
                    <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="form.subject_code" :label="__('Mã môn')" maxlength="30" /><flux:input wire:model="form.subject_name" :label="__('Tên môn (không bắt buộc)')" maxlength="100" /></div>
            <flux:text>{{ __('Mã môn bắt buộc cho điểm THPT và Học bạ; chọn đúng mã môn theo phương thức xét tuyển. ĐGNL, IELTS và SAT có thể bỏ trống thông tin môn học.') }}</flux:text>
            <flux:input wire:model="form.score" :label="__('Điểm *')" inputmode="decimal" :description="__('Từ 0 đến 99999.999; tối đa 3 chữ số thập phân. Dùng dấu chấm.')" required />
            <flux:input wire:model="form.exam_year" :label="__('Năm thi *')" type="number" min="1900" :max="now()->year" required />
            <div>
                <label for="candidate-score-evidence" class="mb-2 block text-sm font-medium text-zinc-800 dark:text-white">{{ __('Ảnh minh chứng') }} {{ $recordId ? __('(thay thế nếu cần)') : __('*') }}</label>
                <input id="candidate-score-evidence" type="file" wire:model="evidence" accept="image/jpeg,image/png" class="block w-full text-sm text-zinc-800 file:me-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-2 dark:text-white dark:file:bg-zinc-700" />
                <flux:error name="evidence" />
                <flux:text>{{ __('Bắt buộc khi thêm điểm mới. Chỉ nhận ảnh JPG, JPEG hoặc PNG, tối đa 2 MiB. Điểm chưa xác minh có thể thay ảnh minh chứng.') }}</flux:text>
            </div>
            <flux:text>{{ __('Có thể khai báo nhiều lần thi. Điểm đã xác minh không thể sửa hoặc xóa.') }}</flux:text>
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save,evidence">{{ __('Lưu điểm') }}</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model="showDeletion" class="md:w-96">
        <form wire:submit="delete" class="flex flex-col gap-5"><flux:heading>{{ __('Xóa điểm này?') }}</flux:heading><flux:text>{{ __('Thao tác này không thể hoàn tác.') }}</flux:text><flux:error name="deletion" /><div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="delete">{{ __('Xóa') }}</flux:button></div></form>
    </flux:modal>
</section>
