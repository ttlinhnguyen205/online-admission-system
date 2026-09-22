<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div><flux:heading size="xl" level="1">{{ __('Tài liệu hồ sơ') }}</flux:heading><flux:text class="mt-2">{{ __('Mỗi tài liệu thuộc về một hồ sơ xét tuyển. Hãy chọn hồ sơ để quản lý tài liệu của hồ sơ đó.') }}</flux:text></div>
    <flux:error name="cleanup" />
    <div class="flex flex-wrap gap-3">
        <flux:button :href="route('candidate.applications.index')" wire:navigate>{{ __('Đăng ký nguyện vọng') }}</flux:button>
        @if ($application)
            <flux:button :href="route('candidate.applications.show', $application->id)" wire:navigate>{{ __('Quay lại hồ sơ và nguyện vọng') }}</flux:button>
        @endif
    </div>
    @if ($applications->isEmpty())
        <flux:callout>{{ __('Chưa có hồ sơ xét tuyển. Bạn có thể tải tài liệu lên sau khi tạo hồ sơ xét tuyển.') }}</flux:callout>
        @if (! $profile)<flux:button :href="route('candidate.profile.edit')" wire:navigate>{{ __('Hoàn thiện hồ sơ cá nhân') }}</flux:button>@endif
    @else
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($applications as $item)
                <a wire:key="application-{{ $item->id }}" href="{{ route('candidate.applications.documents.index', $item->id) }}" wire:navigate class="rounded-xl border p-4 {{ $applicationId === $item->id ? 'border-blue-500' : 'border-zinc-200 dark:border-zinc-700' }}" @if ($applicationId === $item->id) aria-current="page" @endif>
                    <div class="font-medium">{{ $item->application_code }}</div><div class="text-sm">{{ $item->admissionRound->name }}</div><flux:badge class="mt-2">{{ App\Support\CandidateStatusLabels::application($item->status) }}</flux:badge>
                </a>
            @endforeach
        </div>
    @endif
    @if ($application)
        <div class="flex flex-wrap items-center justify-between gap-3"><flux:heading>{{ $application->application_code }}</flux:heading>
            @can('create', [App\Models\CandidateDocument::class, $application])
                <flux:button variant="primary" wire:click="create">{{ __('Tải tài liệu lên') }}</flux:button>
            @else
                <flux:badge>{{ __('Không thể chỉnh sửa tài liệu ở trạng thái hồ sơ hiện tại') }}</flux:badge>
            @endcan
        </div>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:table :paginate="$records">
                <flux:table.columns><flux:table.column>{{ __('Tài liệu') }}</flux:table.column><flux:table.column>{{ __('Trạng thái duyệt') }}</flux:table.column><flux:table.column>{{ __('Thao tác') }}</flux:table.column></flux:table.columns>
                <flux:table.rows>
                    @forelse ($records as $record)
                        <flux:table.row :key="$record->id">
                            <flux:table.cell><div>{{ $record->document_type }}</div><div class="max-w-sm whitespace-normal break-words text-xs">{{ $record->original_name }}</div></flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="match ($record->status) { App\Enums\DocumentStatus::Verified => 'green', App\Enums\DocumentStatus::Rejected => 'red', default => 'zinc' }">{{ App\Support\CandidateStatusLabels::document($record->status) }}</flux:badge>
                                @if ($record->status === App\Enums\DocumentStatus::Rejected && $record->rejection_reason)<p class="mt-2 max-w-md whitespace-pre-wrap break-words text-sm">{{ $record->rejection_reason }}</p>@endif
                            </flux:table.cell>
                            <flux:table.cell><div class="flex flex-wrap gap-2">
                                @can('download', $record)<flux:button size="sm" :href="route('admission.documents.download', $record->id)">{{ __('Tải xuống') }}</flux:button>@endcan
                                @can('update', $record)<flux:button size="sm" wire:click="edit({{ $record->id }})">{{ __('Thay thế / Sửa') }}</flux:button>@endcan
                                @can('delete', $record)<flux:button size="sm" variant="ghost" wire:click="confirmDeletion({{ $record->id }})">{{ __('Xóa') }}</flux:button>@endcan
                            </div></flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="3"><div class="py-10 text-center">{{ __('Hồ sơ này chưa có tài liệu.') }}</div></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
    <flux:modal wire:model="showEditor" class="w-full md:max-w-xl">
        <form wire:submit="save" class="flex flex-col gap-5" x-data="{ uploading: false, progress: 0, fileName: '' }"
            x-on:livewire-upload-start="uploading = true; progress = 0" x-on:livewire-upload-finish="uploading = false"
            x-on:livewire-upload-cancel="uploading = false" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-progress="progress = $event.detail.progress">
            <flux:heading>{{ $recordId ? __('Thay thế / Sửa tài liệu') : __('Tải tài liệu lên') }}</flux:heading>
            <flux:error name="form" />
            <flux:input wire:model="form.document_type" :label="__('Loại tài liệu *')" maxlength="50" :description="__('Ví dụ: học bạ hoặc chứng chỉ. Có thể tải nhiều tệp cùng loại.')" required />
            <div>
                <div class="mb-2 text-sm font-medium text-zinc-800 dark:text-white">{{ $recordId ? __('Tệp thay thế (không bắt buộc)') : __('Tệp *') }}</div>
                <input id="candidate-document" wire:model="file" type="file" accept="application/pdf,image/jpeg,image/png" class="sr-only" x-on:change="fileName = $event.target.files[0]?.name ?? ''" />
                <label for="candidate-document" class="inline-flex h-10 cursor-pointer items-center rounded-lg border border-zinc-200 bg-white px-4 text-sm font-medium text-zinc-800 shadow-xs hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-white dark:hover:bg-zinc-600/75">{{ __('Chọn tệp') }}</label>
                <span class="ms-3 text-sm text-zinc-500 dark:text-zinc-300" x-text="fileName || 'Chưa chọn tệp nào'"></span>
                <flux:error name="file" />
            </div>
            <flux:text>{{ __('Tệp PDF, JPEG hoặc PNG, tối đa 10 MiB. Thay tệp hoặc đổi loại tài liệu sẽ đưa trạng thái duyệt về chờ xét duyệt.') }}</flux:text>
            <div x-show="uploading" x-cloak role="status">{{ __('Đang tải lên:') }} <span x-text="progress + '%' "></span><progress x-bind:value="progress" max="100" class="w-full"></progress></div>
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary" x-bind:disabled="uploading" wire:loading.attr="disabled" wire:target="save,file">{{ __('Lưu tài liệu') }}</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model="showDeletion" class="md:w-96"><form wire:submit="delete" class="flex flex-col gap-5"><flux:heading>{{ __('Xóa tài liệu này?') }}</flux:heading><flux:text>{{ __('Tệp và bản ghi sẽ bị xóa. Thao tác này không thể hoàn tác.') }}</flux:text><flux:error name="deletion" /><div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="delete">{{ __('Xóa') }}</flux:button></div></form></flux:modal>
</section>
