<section class="admission-workspace mx-auto flex min-w-0 w-full max-w-7xl flex-col gap-6">
    <x-admin.workspace-heading title="Quản lý tin tức" description="Soạn bài, lưu nháp và xuất bản thông tin nhà trường, tuyển sinh.">
        <flux:button variant="primary" wire:click="create">Tạo bài viết</flux:button>
    </x-admin.workspace-heading>

    <div class="admission-panel flex min-w-0 flex-col gap-4 p-5">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <flux:input wire:model.live.debounce.300ms="search" label="Tìm bài viết" placeholder="Nhập tiêu đề" type="search" maxlength="100" />
            <flux:select wire:model.live="statusFilter" label="Trạng thái">
                <option value="">Tất cả trạng thái</option>
                @foreach (App\Models\NewsArticle::STATUSES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </flux:select>
        </div>
        <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ number_format($records->total()) }} bài viết phù hợp</p>
        <div role="status" wire:loading.delay class="text-sm text-zinc-500">Đang cập nhật danh sách…</div>
    </div>
    <div class="admission-panel min-w-0 overflow-hidden">
        <flux:table :paginate="$records" class="admin-records-table">
            <flux:table.columns>
                <flux:table.column class="table-stt">STT</flux:table.column>
                <flux:table.column>Bài viết</flux:table.column>
                <flux:table.column>Nhóm tin</flux:table.column>
                <flux:table.column>Trạng thái</flux:table.column>
                <flux:table.column>Ngày xuất bản</flux:table.column>
                <flux:table.column>Thao tác</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($records as $record)
                    <flux:table.row :key="$record->id">
                        <flux:table.cell class="table-stt" data-label="STT">{{ $records->firstItem() + $loop->index }}</flux:table.cell>
                        <flux:table.cell data-label="Bài viết"><div class="max-w-sm whitespace-normal break-words font-medium">{{ $record->title }}</div></flux:table.cell>
                        <flux:table.cell data-label="Nhóm tin">{{ App\Models\NewsArticle::CATEGORIES[$record->category] }}</flux:table.cell>
                        <flux:table.cell data-label="Trạng thái"><flux:badge :color="$record->status === 'published' ? 'green' : 'zinc'">{{ App\Models\NewsArticle::STATUSES[$record->status] }}</flux:badge></flux:table.cell>
                        <flux:table.cell data-label="Ngày xuất bản">{{ $record->published_at?->format('d/m/Y H:i') ?? '—' }}</flux:table.cell>
                        <flux:table.cell data-label="Thao tác">
                            <div class="flex flex-wrap justify-start gap-2">
                                <flux:button size="sm" wire:click="edit({{ $record->id }})">Sửa</flux:button>
                                @if ($record->status === 'published')
                                    <flux:button size="sm" :href="route('news.show', $record->id)" wire:navigate>Xem bài</flux:button>
                                    <flux:button size="sm" wire:click="hide({{ $record->id }})" wire:loading.attr="disabled">Ẩn bài</flux:button>
                                @else
                                    <flux:button size="sm" wire:click="publish({{ $record->id }})" wire:loading.attr="disabled">Xuất bản</flux:button>
                                @endif
                                <flux:button size="sm" variant="danger" wire:click="delete({{ $record->id }})" wire:confirm="Bạn có chắc muốn xóa vĩnh viễn bài viết này?" wire:loading.attr="disabled">Xóa</flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="6"><p class="py-10 text-center text-zinc-500">{{ $search !== '' || $statusFilter !== '' ? 'Không có bài viết phù hợp.' : 'Chưa có bài viết. Bấm “Tạo bài viết” để bắt đầu.' }}</p></flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal wire:model.self="showEditor" class="w-full md:max-w-3xl">
        <form wire:submit="save" class="flex flex-col gap-5">
            <flux:heading size="lg">{{ $recordId === null ? 'Tạo bài viết' : 'Sửa bài viết' }}</flux:heading>
            <flux:error name="form" />
            <flux:input wire:model="form.title" label="Tiêu đề *" maxlength="255" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.category" label="Nhóm tin *">
                    @foreach (App\Models\NewsArticle::CATEGORIES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="form.status" label="Trạng thái *">
                    @foreach (App\Models\NewsArticle::STATUSES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
            <flux:textarea wire:model="form.summary" label="Tóm tắt" maxlength="1000" rows="3" />
            <flux:textarea wire:model="form.content" label="Nội dung *" description="Nhập nội dung văn bản và xuống dòng để chia đoạn." maxlength="100000" rows="12" required />
            <flux:text>Chọn “Đã xuất bản” để bài xuất hiện trên trang Tin tức. Bản nháp và bài đã ẩn chỉ có Admin xem trong mục quản lý.</flux:text>
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button>Hủy</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Lưu bài viết</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
