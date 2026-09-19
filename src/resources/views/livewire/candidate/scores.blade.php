<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><flux:heading size="xl" level="1">{{ __('Điểm xét tuyển') }}</flux:heading><flux:text class="mt-2">{{ __('Quản lý các điểm đã khai báo. Đây không phải là điểm xét tuyển được hệ thống tính.') }}</flux:text></div>
        @if ($profile)
            <flux:button variant="primary" wire:click="create">{{ __('Thêm điểm') }}</flux:button>
        @endif
    </div>
    @if (! $profile)
        <flux:callout>{{ __('Hãy tạo hồ sơ thí sinh trước khi thêm điểm.') }} <a class="underline" href="{{ route('candidate.profile.edit') }}" wire:navigate>{{ __('Mở hồ sơ') }}</a></flux:callout>
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model.live.debounce.300ms="typeFilter" :label="__('Lọc theo loại điểm (khớp chính xác)')" maxlength="30" />
            <flux:input wire:model.live.debounce.300ms="yearFilter" :label="__('Lọc theo năm thi')" type="number" />
        </div>
        <div role="status" wire:loading.delay>{{ __('Đang cập nhật điểm...') }}</div>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:table :paginate="$records">
                <flux:table.columns>
                    <flux:table.column>{{ __('Loại điểm / Môn học') }}</flux:table.column><flux:table.column>{{ __('Điểm') }}</flux:table.column><flux:table.column>{{ __('Năm') }}</flux:table.column><flux:table.column>{{ __('Trạng thái') }}</flux:table.column><flux:table.column>{{ __('Thao tác') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($records as $record)
                        <flux:table.row :key="$record->id">
                            <flux:table.cell><div>{{ Illuminate\Support\Str::upper($record->score_type) }}</div><div class="text-xs">{{ $record->subject_code }} {{ $record->subject_name }}</div></flux:table.cell>
                            <flux:table.cell>{{ $record->score }}</flux:table.cell><flux:table.cell>{{ $record->exam_year }}</flux:table.cell>
                            <flux:table.cell><flux:badge :color="$record->verified ? 'green' : 'zinc'">{{ $record->verified ? __('Đã xác minh') : __('Chưa xác minh') }}</flux:badge></flux:table.cell>
                            <flux:table.cell>
                                @can('update', $record)<flux:button size="sm" wire:click="edit({{ $record->id }})">{{ __('Sửa') }}</flux:button>@endcan
                                @can('delete', $record)<flux:button size="sm" variant="ghost" wire:click="confirmDeletion({{ $record->id }})">{{ __('Xóa') }}</flux:button>@endcan
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="5"><div class="py-10 text-center">{{ __('Không tìm thấy điểm nào. Hãy thêm điểm hoặc điều chỉnh bộ lọc.') }}</div></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
    <flux:modal wire:model="showEditor" class="w-full md:max-w-xl">
        <form wire:submit="save" class="flex flex-col gap-5">
            <flux:heading size="lg">{{ $recordId ? __('Sửa điểm') : __('Thêm điểm') }}</flux:heading>
            <flux:error name="form" />
            <flux:input wire:model="form.score_type" :label="__('Loại điểm *')" maxlength="30" :description="__('Ví dụ: THPT, Học bạ, ĐGNL, IELTS, SAT,...')" required />
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="form.subject_code" :label="__('Mã môn (không bắt buộc)')" maxlength="30" /><flux:input wire:model="form.subject_name" :label="__('Tên môn (không bắt buộc)')" maxlength="100" /></div>
            <flux:input wire:model="form.score" :label="__('Điểm *')" inputmode="decimal" :description="__('Từ 0 đến 99999.999; tối đa 3 chữ số thập phân (dùng dấu chấm.)')" required />
            <flux:input wire:model="form.exam_year" :label="__('Năm thi *')" type="number" min="1900" :max="now()->year" required />
            <flux:text>{{ __('Có thể khai báo nhiều lần thi. Điểm đã xác minh không thể sửa hoặc xóa.') }}</flux:text>
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">{{ __('Lưu điểm') }}</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model="showDeletion" class="md:w-96">
        <form wire:submit="delete" class="flex flex-col gap-5"><flux:heading>{{ __('Xóa điểm này?') }}</flux:heading><flux:text>{{ __('Thao tác này không thể hoàn tác.') }}</flux:text><flux:error name="deletion" /><div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="delete">{{ __('Xóa') }}</flux:button></div></form>
    </flux:modal>
</section>
