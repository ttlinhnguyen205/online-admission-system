<section class="admission-workspace mx-auto flex min-w-0 w-full max-w-7xl flex-col gap-6">
    <header class="admission-panel border-t-4 border-t-admission-blue p-5 sm:p-6">
        <flux:heading size="xl" level="1">Lịch sử xử lý</flux:heading>
        <p class="mt-2 text-base leading-7 text-zinc-600 dark:text-zinc-300">Tra cứu thao tác duyệt hồ sơ, người thực hiện và kết quả tại thời điểm xử lý.</p>
    </header>

    <div class="admission-panel grid min-w-0 gap-4 p-5 sm:grid-cols-2 xl:grid-cols-3">
        <flux:input type="search" wire:model.live.debounce.400ms="search" label="Tìm kiếm" placeholder="Mã hồ sơ, thí sinh, người xử lý" />
        <flux:select wire:model.live="actionFilter" label="Hành động">
            <option value="">Tất cả hành động</option>
            <option value="application.review_started">Bắt đầu duyệt</option>
            <option value="application.revision_requested">Yêu cầu bổ sung</option>
            <option value="application.rejected">Từ chối hồ sơ</option>
            <option value="application.verified">Xác minh hồ sơ</option>
        </flux:select>
        <flux:select wire:model.live="statusFilter" label="Kết quả tại thời điểm xử lý">
            <option value="">Tất cả kết quả</option>
            <option value="under_review">Đang xét duyệt</option>
            <option value="needs_revision">Cần bổ sung</option>
            <option value="verified">Đã xác minh</option>
            <option value="rejected">Không hợp lệ</option>
        </flux:select>
        <flux:input type="date" wire:model.live="dateFrom" label="Từ ngày" />
        <flux:input type="date" wire:model.live="dateTo" label="Đến ngày" />
        <div class="flex flex-wrap items-center justify-between gap-3 sm:col-span-2 xl:col-span-3">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ number_format($records->total()) }} thao tác phù hợp | Giờ Việt Nam (UTC+7)</p>
            <flux:button wire:click="clearFilters">Xóa bộ lọc</flux:button>
        </div>
        <div role="status" wire:loading.delay class="text-sm text-zinc-600">Đang tải lịch sử…</div>
    </div>

    <div class="admission-panel min-w-0 overflow-hidden">
        <div class="overflow-x-auto" tabindex="0" aria-label="Bảng lịch sử xử lý">
            <table class="history-table w-full text-left text-sm leading-6">
                <caption class="sr-only">Lịch sử duyệt hồ sơ, 15 thao tác mỗi trang</caption>
                <thead class="bg-admission-soft text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100">
                    <tr><th scope="col" class="table-stt px-4 py-4 font-semibold">STT</th>@foreach (['Thời gian', 'Người thực hiện', 'Vai trò hiện tại', 'Hồ sơ / Đối tượng', 'Hành động', 'Kết quả', 'Chi tiết'] as $label)<th scope="col" class="px-4 py-4 font-semibold">{{ $label }}</th>@endforeach</tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        @php
                            $application = $record->subject;
                            $actionLabel = match ($record->action) {
                                'application.review_started' => 'Bắt đầu duyệt',
                                'application.revision_requested' => 'Yêu cầu bổ sung',
                                'application.rejected' => 'Từ chối hồ sơ',
                                'application.verified' => 'Xác minh hồ sơ',
                                default => 'Xử lý hồ sơ',
                            };
                            $status = App\Enums\ApplicationStatus::tryFrom((string) ($record->new_values['status'] ?? ''));
                            $color = match ($status?->value) { 'verified' => 'green', 'needs_revision' => 'amber', 'rejected' => 'red', default => 'zinc' };
                            $reason = $record->new_values['revision_reason'] ?? null;
                        @endphp
                        <tr wire:key="history-{{ $record->id }}" class="border-t border-zinc-200 dark:border-zinc-700">
                            <td data-label="STT" class="table-stt">{{ $records->firstItem() + $loop->index }}</td>
                            <td data-label="Thời gian"><time datetime="{{ $record->created_at?->toIso8601String() }}">{{ $record->created_at?->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') ?? '—' }}</time></td>
                            <td data-label="Người thực hiện">{{ $record->user?->name ?? 'Không có thông tin người xử lý' }}</td>
                            <td data-label="Vai trò hiện tại">{{ match ($record->user?->role?->value) { 'admin' => 'Quản trị viên', 'staff' => 'Nhân viên', 'candidate' => 'Thí sinh', default => 'Không xác định' } }}</td>
                            <td data-label="Hồ sơ / Đối tượng">
                                @if ($application)
                                    <a href="{{ route('admin.applications.show', $application->id) }}" wire:navigate class="font-semibold text-admission-blue underline-offset-4 hover:underline dark:text-rose-300">{{ $application->application_code }}</a>
                                    <p class="text-zinc-600 dark:text-zinc-300">{{ $application->candidateProfile?->user?->name ?? 'Không xác định' }}</p>
                                @else
                                    Hồ sơ không còn tồn tại
                                @endif
                            </td>
                            <td data-label="Hành động">{{ $actionLabel }}</td>
                            <td data-label="Kết quả"><flux:badge :color="$color">{{ $status ? App\Support\CandidateStatusLabels::application($status) : 'Không có thông tin' }}</flux:badge></td>
                            <td data-label="Chi tiết">
                                @if (is_string($reason) && $reason !== '')
                                    <details><summary class="cursor-pointer text-admission-blue dark:text-rose-300">Xem lý do</summary><p class="mt-2 whitespace-pre-wrap">{{ $reason }}</p></details>
                                @else
                                    <span class="text-zinc-500">Không có ghi chú</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><p role="status" class="py-8 text-center text-zinc-600 dark:text-zinc-300">Chưa có lịch sử xử lý phù hợp. Hãy thử thay đổi bộ lọc.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">{{ $records->links() }}</div>
    </div>
</section>
