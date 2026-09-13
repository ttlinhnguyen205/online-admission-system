<x-admin.page title="Majors" description="Maintain the university major catalog and default tuition." singular="major" :resource-model="$resourceModel">
    <x-slot:filters>
        <flux:select wire:model.live="statusFilter" label="Availability">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </flux:select>
    </x-slot:filters>
    <flux:table :paginate="$records">
        <flux:table.columns>
            <flux:table.column>Major</flux:table.column>
            <flux:table.column>Default tuition</flux:table.column>
            <flux:table.column>Availability</flux:table.column>
            <flux:table.column>Programs</flux:table.column>
            <flux:table.column>Actions</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($records as $record)
                <flux:table.row :key="$record->id">
                    <flux:table.cell>
                        <div class="font-medium">{{ $record->name }}</div>
                        <div class="text-xs text-zinc-500">{{ $record->code }}</div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $record->default_tuition_fee === null ? 'Not specified' : number_format((float) $record->default_tuition_fee, 2) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge :color="$record->is_active ? 'green' : 'zinc'">{{ $record->is_active ? 'Active' : 'Inactive' }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ $record->programs_count }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" wire:click="details({{ $record->id }})">View</flux:button>
                            @can('update', $record)
                                <flux:button size="sm" wire:click="edit({{ $record->id }})">Edit</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="confirmDeletion({{ $record->id }})">Delete</flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5">
                        <div class="py-12 text-center">
                            @if ($this->search !== '' || $this->statusFilter !== '')
                                <flux:heading>No majors found</flux:heading>
                                <flux:text class="mt-2">Try another search or clear the filters.</flux:text>
                            @else
                                <flux:heading>No majors yet</flux:heading>
                                <flux:text class="mt-2">No records have been configured. An administrator can create the first major.</flux:text>
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    <x-slot:editor>
        <flux:input wire:model="form.code" label="Code *" maxlength="30" required />
        <flux:input wire:model="form.name" label="Name *" maxlength="255" required />
        <div class="sm:col-span-2">
            <flux:textarea wire:model="form.description" label="Description" maxlength="10000" rows="4" />
        </div>
        <flux:input wire:model="form.default_tuition_fee" label="Default tuition fee" type="number" min="0" max="9999999999999.99" step="0.01" description="Leave blank if not specified." />
        <flux:checkbox wire:model="form.is_active" label="Active" />
    </x-slot:editor>
</x-admin.page>
