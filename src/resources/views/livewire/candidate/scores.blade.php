<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><flux:heading size="xl" level="1">{{ __('Điểm xét tuyển') }}</flux:heading><flux:text class="mt-2">{{ __('Manage your recorded scores. These are not calculated admission scores.') }}</flux:text></div>
        @if ($profile)
            <flux:button variant="primary" wire:click="create">{{ __('Thêm điểm / Add score') }}</flux:button>
        @endif
    </div>
    @if (! $profile)
        <flux:callout>{{ __('Create your candidate profile before adding scores.') }} <a class="underline" href="{{ route('candidate.profile.edit') }}" wire:navigate>{{ __('Open profile') }}</a></flux:callout>
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model.live.debounce.300ms="typeFilter" :label="__('Filter by score type (exact match)')" maxlength="30" />
            <flux:input wire:model.live.debounce.300ms="yearFilter" :label="__('Filter by exam year')" type="number" />
        </div>
        <div role="status" wire:loading.delay>{{ __('Updating scores...') }}</div>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:table :paginate="$records">
                <flux:table.columns>
                    <flux:table.column>{{ __('Type / Subject') }}</flux:table.column><flux:table.column>{{ __('Score') }}</flux:table.column><flux:table.column>{{ __('Year') }}</flux:table.column><flux:table.column>{{ __('Status') }}</flux:table.column><flux:table.column>{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($records as $record)
                        <flux:table.row :key="$record->id">
                            <flux:table.cell><div>{{ $record->score_type }}</div><div class="text-xs">{{ $record->subject_code }} {{ $record->subject_name }}</div></flux:table.cell>
                            <flux:table.cell>{{ $record->score }}</flux:table.cell><flux:table.cell>{{ $record->exam_year }}</flux:table.cell>
                            <flux:table.cell><flux:badge :color="$record->verified ? 'green' : 'zinc'">{{ $record->verified ? __('Verified') : __('Unverified') }}</flux:badge></flux:table.cell>
                            <flux:table.cell>
                                @can('update', $record)<flux:button size="sm" wire:click="edit({{ $record->id }})">{{ __('Edit') }}</flux:button>@endcan
                                @can('delete', $record)<flux:button size="sm" variant="ghost" wire:click="confirmDeletion({{ $record->id }})">{{ __('Delete') }}</flux:button>@endcan
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="5"><div class="py-10 text-center">{{ __('No scores found. Add a score or adjust the filters.') }}</div></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
    <flux:modal wire:model="showEditor" class="w-full md:max-w-xl">
        <form wire:submit="save" class="flex flex-col gap-5">
            <flux:heading size="lg">{{ $recordId ? __('Edit score') : __('Add score') }}</flux:heading>
            <flux:error name="form" />
            <flux:input wire:model="form.score_type" :label="__('Loại điểm / Score type *')" maxlength="30" :description="__('Examples: thpt, học bạ, ĐGNL, IELTS, SAT. Use a consistent label.')" required />
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="form.subject_code" :label="__('Subject code (optional)')" maxlength="30" /><flux:input wire:model="form.subject_name" :label="__('Subject name (optional)')" maxlength="100" /></div>
            <flux:input wire:model="form.score" :label="__('Điểm / Score *')" inputmode="decimal" :description="__('0 to 99999.999; up to 3 decimal places. Use a decimal point.')" required />
            <flux:input wire:model="form.exam_year" :label="__('Năm thi / Exam year *')" type="number" min="1900" :max="now()->year" required />
            <flux:text>{{ __('Multiple attempts are allowed. Verified scores cannot be edited or deleted.') }}</flux:text>
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">{{ __('Save score') }}</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model="showDeletion" class="md:w-96">
        <form wire:submit="delete" class="flex flex-col gap-5"><flux:heading>{{ __('Delete this score?') }}</flux:heading><flux:text>{{ __('This action cannot be undone.') }}</flux:text><flux:error name="deletion" /><div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="delete">{{ __('Delete') }}</flux:button></div></form>
    </flux:modal>
</section>
