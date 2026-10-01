<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">

    <div>
        <flux:heading size="xl" level="1">
            {{ __('Lịch sử xử lý') }}
        </flux:heading>

        <flux:text class="mt-2">
            {{ __('Theo dõi các thao tác duyệt hồ sơ, người thực hiện, thời gian và lý do xử lý.') }}
        </flux:text>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <flux:input
            wire:model.live.debounce.400ms="search"
            :label="__('Tìm kiếm')"
            :placeholder="__('Mã hồ sơ, thí sinh hoặc người xử lý')" />

        <flux:select
            wire:model.live="actionFilter"
            :label="__('Hành động')">
            <flux:select.option value="">
                {{ __('Tất cả hành động') }}
            </flux:select.option>

            <flux:select.option value="application.review_started">
                {{ __('Bắt đầu duyệt') }}
            </flux:select.option>

            <flux:select.option value="application.revision_requested">
                {{ __('Yêu cầu bổ sung') }}
            </flux:select.option>

            <flux:select.option value="application.rejected">
                {{ __('Từ chối hồ sơ') }}
            </flux:select.option>

            <flux:select.option value="application.verified">
                {{ __('Đủ điều kiện / Đã duyệt') }}
            </flux:select.option>
        </flux:select>

        <div class="flex items-end">
            <flux:button wire:click="clearFilters">
                {{ __('Xóa bộ lọc') }}
            </flux:button>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Hồ sơ') }}</flux:table.column>
                <flux:table.column>{{ __('Hành động') }}</flux:table.column>
                <flux:table.column>{{ __('Người xử lý') }}</flux:table.column>
                <flux:table.column>{{ __('Thời gian') }}</flux:table.column>
                <flux:table.column>{{ __('Lý do') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($records as $record)
                @php
                $application = $record->subject;

                $actionLabel = match ($record->action) {
                'application.review_started' => __('Bắt đầu duyệt'),
                'application.revision_requested' => __('Yêu cầu bổ sung'),
                'application.rejected' => __('Từ chối hồ sơ'),
                'application.verified' => __('Đủ điều kiện / Đã duyệt'),
                default => $record->action,
                };

                $reason = $record->new_values['revision_reason'] ?? null;
                @endphp

                <flux:table.row :key="$record->id">

                    <flux:table.cell>
                        @if ($application)
                        <div class="font-medium">
                            <a
                                href="{{ route('admin.applications.show', $application->id) }}"
                                wire:navigate
                                class="hover:underline">
                                {{ $application->application_code }}
                            </a>
                        </div>

                        <div class="text-sm text-zinc-500">
                            {{ $application->candidateProfile?->user?->name ?? __('Không xác định') }}
                        </div>
                        @else
                        <span class="text-zinc-500">
                            {{ __('Hồ sơ không còn tồn tại') }}
                        </span>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell>
                        {{ $actionLabel }}
                    </flux:table.cell>

                    <flux:table.cell>
                        <div>{{ $record->user?->name ?? __('Không xác định') }}</div>

                        @if ($record->user?->email)
                        <div class="text-sm text-zinc-500">
                            {{ $record->user->email }}
                        </div>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell>
                        {{ $record->created_at?->format('Y-m-d H:i:s') ?? '—' }}
                    </flux:table.cell>

                    <flux:table.cell>
                        @if ($reason)
                        <p class="max-w-md whitespace-pre-wrap break-words">
                            {{ $reason }}
                        </p>
                        @else
                        —
                        @endif
                    </flux:table.cell>

                </flux:table.row>

                @empty
                <flux:table.row>
                    <flux:table.cell colspan="5">
                        {{ __('Chưa có lịch sử xử lý phù hợp.') }}
                    </flux:table.cell>
                </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

    </div>

    <div>
        {{ $records->links() }}
    </div>

</section>