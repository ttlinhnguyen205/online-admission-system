@props(['title', 'description', 'resourceModel', 'singular'])

<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('admin.home')" wire:navigate>{{ __('Configuration') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $title }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
        <div>
            <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
            <flux:text class="mt-2">{{ $description }}</flux:text>
        </div>
        @can('create', $resourceModel)
            <flux:button variant="primary" wire:click="create">{{ __('Create :resource', ['resource' => $singular]) }}</flux:button>
        @else
            <flux:badge>{{ __('Read-only access') }}</flux:badge>
        @endcan
    </div>
    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" :placeholder="__('Search code or name')" maxlength="100" type="search" />
            {{ $filters }}
            <flux:button wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
        </div>
        <div role="status" class="min-h-5 text-sm text-zinc-500">
            <span wire:loading.delay>{{ __('Updating records...') }}</span>
        </div>
        {{ $slot }}
    </div>

    <flux:modal wire:model="showEditor" class="w-full md:max-w-2xl">
        <form wire:submit="save" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ $this->readOnly ? __('View :resource', ['resource' => $singular]) : ($this->recordId ? __('Edit :resource', ['resource' => $singular]) : __('Create :resource', ['resource' => $singular])) }}</flux:heading>
                <flux:text class="mt-2">{{ __('Review the configuration below. Required fields are marked *.') }}</flux:text>
            </div>
            <flux:error name="form" />
            <fieldset @disabled($this->readOnly) class="grid min-w-0 gap-5 sm:grid-cols-2">
                {{ $editor }}
            </fieldset>
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button>{{ $this->readOnly ? __('Close') : __('Cancel') }}</flux:button></flux:modal.close>
                @if (! $this->readOnly)
                    @can('create', $resourceModel)
                        <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Save configuration') }}</flux:button>
                    @endcan
                @endif
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showDeletion" class="md:w-96">
        <form wire:submit="delete" class="flex flex-col gap-5">
            <flux:heading size="lg">{{ __('Delete configuration?') }}</flux:heading>
            <flux:text>{{ $this->deleteLabel }}</flux:text>
            <flux:text>{{ __('Deletion is permanent. Records used by other admission records cannot be deleted.') }}</flux:text>
            <flux:error name="deletion" />
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button>{{ __('Cancel') }}</flux:button></flux:modal.close>
                @can('create', $resourceModel)
                    <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="delete" :disabled="$errors->has('deletion')">{{ __('Delete') }}</flux:button>
                @endcan
            </div>
        </form>
    </flux:modal>
</section>
