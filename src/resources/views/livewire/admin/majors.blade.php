<x-admin.page
    :title="__('Majors')"
    :description="__('Maintain the university major catalog and default tuition.')"
    :singular="__('major')"
    :resource-model="$resourceModel">
    <x-slot:filters>
        <flux:select wire:model.live="statusFilter" :label="__('Availability')">
            <option value="">{{ __('All statuses') }}</option>
            <option value="active">{{ __('Active') }}</option>
            <option value="inactive">{{ __('Inactive') }}</option>
        </flux:select>
    </x-slot:filters>

    <flux:table :paginate="$records">
        <flux:table.columns>
            <flux:table.column>{{ __('Major') }}</flux:table.column>
            <flux:table.column>{{ __('Default tuition') }}</flux:table.column>
            <flux:table.column>{{ __('Availability') }}</flux:table.column>
            <flux:table.column>{{ __('Programs') }}</flux:table.column>
            <flux:table.column>{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($records as $record)
            <flux:table.row :key="$record->id">
                <flux:table.cell>
                    <div class="font-medium">{{ $record->name }}</div>
                    <div class="text-xs text-zinc-500">{{ $record->code }}</div>
                </flux:table.cell>

                <flux:table.cell>
                    {{ $record->default_tuition_fee === null ? __('Not specified') : number_format((float) $record->default_tuition_fee, 2) }}
                </flux:table.cell>

                <flux:table.cell>
                    <flux:badge :color="$record->is_active ? 'green' : 'zinc'">
                        {{ $record->is_active ? __('Active') : __('Inactive') }}
                    </flux:badge>
                </flux:table.cell>

                <flux:table.cell>{{ $record->programs_count }}</flux:table.cell>

                <flux:table.cell>
                    <div class="flex justify-end gap-2">
                        <flux:button size="sm" wire:click="details({{ $record->id }})">
                            {{ __('View') }}
                        </flux:button>

                        @can('update', $record)
                        <flux:button size="sm" wire:click="edit({{ $record->id }})">
                            {{ __('Edit') }}
                        </flux:button>

                        <flux:button size="sm" variant="ghost" wire:click="confirmDeletion({{ $record->id }})">
                            {{ __('Delete') }}
                        </flux:button>
                        @endcan
                    </div>
                </flux:table.cell>
            </flux:table.row>
            @empty
            <flux:table.row>
                <flux:table.cell colspan="5">
                    <div class="py-12 text-center">
                        @if ($this->search !== '' || $this->statusFilter !== '')
                        <flux:heading>{{ __('No majors found') }}</flux:heading>
                        <flux:text class="mt-2">
                            {{ __('Try another search or clear the filters.') }}
                        </flux:text>
                        @else
                        <flux:heading>{{ __('No majors yet') }}</flux:heading>
                        <flux:text class="mt-2">
                            {{ __('No records have been configured. An administrator can create the first major.') }}
                        </flux:text>
                        @endif
                    </div>
                </flux:table.cell>
            </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <x-slot:editor>
        <flux:input
            wire:model="form.code"
            :label="__('Code *')"
            maxlength="30"
            required />

        <flux:input
            wire:model="form.name"
            :label="__('Name *')"
            maxlength="255"
            required />

        <div class="sm:col-span-2">
            <flux:textarea
                wire:model="form.description"
                :label="__('Description')"
                maxlength="10000"
                rows="4" />
        </div>

        <flux:input
            wire:model="form.default_tuition_fee"
            :label="__('Default tuition fee')"
            type="number"
            min="0"
            max="9999999999999.99"
            step="0.01"
            :description="__('Leave blank if not specified.')" />

        <flux:checkbox
            wire:model="form.is_active"
            :label="__('Active')" />
    </x-slot:editor>
</x-admin.page>