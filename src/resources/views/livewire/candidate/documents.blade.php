<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div><flux:heading size="xl" level="1">{{ __('Tài liệu hồ sơ') }}</flux:heading><flux:text class="mt-2">{{ __('Documents belong to an admission application. Select an application to manage its files.') }}</flux:text></div>
    <flux:error name="cleanup" />
    <div class="flex flex-wrap gap-3">
        <flux:button :href="route('candidate.applications.index')" wire:navigate>{{ __('Admission applications') }}</flux:button>
        @if ($application)
            <flux:button :href="route('candidate.applications.show', $application->id)" wire:navigate>{{ __('Back to application and wishes') }}</flux:button>
        @endif
    </div>
    @if ($applications->isEmpty())
        <flux:callout>{{ __('No admission application yet. Documents can be uploaded after an admission application exists.') }}</flux:callout>
        @if (! $profile)<flux:button :href="route('candidate.profile.edit')" wire:navigate>{{ __('Complete your candidate profile') }}</flux:button>@endif
    @else
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($applications as $item)
                <a wire:key="application-{{ $item->id }}" href="{{ route('candidate.applications.documents.index', $item->id) }}" wire:navigate class="rounded-xl border p-4 {{ $applicationId === $item->id ? 'border-blue-500' : 'border-zinc-200 dark:border-zinc-700' }}" @if ($applicationId === $item->id) aria-current="page" @endif>
                    <div class="font-medium">{{ $item->application_code }}</div><div class="text-sm">{{ $item->admissionRound->name }}</div><flux:badge class="mt-2">{{ __(ucwords(str_replace('_', ' ', $item->status->value))) }}</flux:badge>
                </a>
            @endforeach
        </div>
    @endif
    @if ($application)
        <div class="flex flex-wrap items-center justify-between gap-3"><flux:heading>{{ $application->application_code }}</flux:heading>
            @can('create', [App\Models\CandidateDocument::class, $application])
                <flux:button variant="primary" wire:click="create">{{ __('Tải tài liệu / Upload document') }}</flux:button>
            @else
                <flux:badge>{{ __('Read-only: application is not editable') }}</flux:badge>
            @endcan
        </div>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:table :paginate="$records">
                <flux:table.columns><flux:table.column>{{ __('Document') }}</flux:table.column><flux:table.column>{{ __('Review status') }}</flux:table.column><flux:table.column>{{ __('Actions') }}</flux:table.column></flux:table.columns>
                <flux:table.rows>
                    @forelse ($records as $record)
                        <flux:table.row :key="$record->id">
                            <flux:table.cell><div>{{ $record->document_type }}</div><div class="max-w-sm whitespace-normal break-words text-xs">{{ $record->original_name }}</div></flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="match ($record->status) { App\Enums\DocumentStatus::Verified => 'green', App\Enums\DocumentStatus::Rejected => 'red', default => 'zinc' }">{{ __(ucfirst($record->status->value)) }}</flux:badge>
                                @if ($record->status === App\Enums\DocumentStatus::Rejected && $record->rejection_reason)<p class="mt-2 max-w-md whitespace-pre-wrap break-words text-sm">{{ $record->rejection_reason }}</p>@endif
                            </flux:table.cell>
                            <flux:table.cell><div class="flex flex-wrap gap-2">
                                @can('download', $record)<flux:button size="sm" :href="route('admission.documents.download', $record->id)">{{ __('Download') }}</flux:button>@endcan
                                @can('update', $record)<flux:button size="sm" wire:click="edit({{ $record->id }})">{{ __('Replace / Edit') }}</flux:button>@endcan
                                @can('delete', $record)<flux:button size="sm" variant="ghost" wire:click="confirmDeletion({{ $record->id }})">{{ __('Delete') }}</flux:button>@endcan
                            </div></flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="3"><div class="py-10 text-center">{{ __('No documents for this application.') }}</div></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
    <flux:modal wire:model="showEditor" class="w-full md:max-w-xl">
        <form wire:submit="save" class="flex flex-col gap-5" x-data="{ uploading: false, progress: 0 }"
            x-on:livewire-upload-start="uploading = true; progress = 0" x-on:livewire-upload-finish="uploading = false"
            x-on:livewire-upload-cancel="uploading = false" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-progress="progress = $event.detail.progress">
            <flux:heading>{{ $recordId ? __('Replace / Edit document') : __('Upload document') }}</flux:heading>
            <flux:error name="form" />
            <flux:input wire:model="form.document_type" :label="__('Loại tài liệu / Document type *')" maxlength="50" :description="__('For example: transcript or certificate. Multiple files of the same type are allowed.')" required />
            <flux:input wire:model="file" type="file" accept="application/pdf,image/jpeg,image/png" :label="$recordId ? __('Replacement file (optional)') : __('File *')" />
            <flux:text>{{ __('PDF/JPEG/PNG, up to 10 MiB. Replacing the file or changing its type resets its review status to pending.') }}</flux:text>
            <div x-show="uploading" x-cloak role="status">{{ __('Uploading:') }} <span x-text="progress + '%' "></span><progress x-bind:value="progress" max="100" class="w-full"></progress></div>
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary" x-bind:disabled="uploading" wire:loading.attr="disabled" wire:target="save,file">{{ __('Save document') }}</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model="showDeletion" class="md:w-96"><form wire:submit="delete" class="flex flex-col gap-5"><flux:heading>{{ __('Delete this document?') }}</flux:heading><flux:text>{{ __('The file and its record will be removed. This action cannot be undone.') }}</flux:text><flux:error name="deletion" /><div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="delete">{{ __('Delete') }}</flux:button></div></form></flux:modal>
</section>
