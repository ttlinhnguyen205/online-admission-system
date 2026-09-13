<x-admin.page title="Admission Methods" description="Manage admission methods and optional subject-weight configuration." singular="method" :resource-model="$resourceModel">
    <x-slot:filters>
        <flux:select wire:model.live="statusFilter" label="Availability">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </flux:select>
    </x-slot:filters>
    <flux:table :paginate="$records">
        <flux:table.columns>
            <flux:table.column>Method</flux:table.column>
            <flux:table.column>Score configuration</flux:table.column>
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
                    <flux:table.cell>{{ $record->score_config === null ? 'Not configured' : 'Configured' }}</flux:table.cell>
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
                                <flux:heading>No admission methods found</flux:heading>
                                <flux:text class="mt-2">Try another search or clear the filters.</flux:text>
                            @else
                                <flux:heading>No admission methods yet</flux:heading>
                                <flux:text class="mt-2">No records have been configured. An administrator can create the first method.</flux:text>
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
            <flux:textarea wire:model="form.description" label="Description" maxlength="10000" rows="3" />
        </div>
        <flux:checkbox wire:model="form.is_active" label="Active" />
        <div class="flex flex-col gap-4 sm:col-span-2">
            <flux:heading>Subject weights</flux:heading>
            <flux:text>Optional. Add up to 30 unique subject codes and positive weights up to 100. This configures weights only; it does not calculate scores.</flux:text>
            <flux:error name="form.weights" />
            @if ($this->unsupportedConfiguration)
                <flux:callout>This existing configuration uses another format. It will be preserved when you save other fields.</flux:callout>
                <pre class="overflow-auto rounded-lg bg-zinc-100 p-3 text-xs dark:bg-zinc-900">{{ $this->configurationPreview }}</pre>
            @else
                @foreach ((is_array($this->form['weights'] ?? null) ? $this->form['weights'] : []) as $index => $weight)
                    <div wire:key="weight-{{ $this->recordId ?? 'new' }}-{{ $index }}" class="grid grid-cols-[1fr_1fr_auto] items-start gap-2">
                        <flux:input wire:model="form.weights.{{ $index }}.subject" label="Subject code" placeholder="MATH" maxlength="30" />
                        <flux:input wire:model="form.weights.{{ $index }}.weight" label="Weight" type="number" min="0.001" max="100" step="0.001" />
                        @if (! $this->readOnly)
                            <flux:button wire:click="removeWeight({{ $index }})" class="mt-6" aria-label="Remove subject">Remove</flux:button>
                        @endif
                        <flux:error name="form.weights.{{ $index }}" />
                    </div>
                @endforeach
                @if (! $this->readOnly)
                    <flux:button wire:click="addWeight" :disabled="count(is_array($this->form['weights'] ?? null) ? $this->form['weights'] : []) >= 30">Add subject</flux:button>
                @endif
            @endif
        </div>
    </x-slot:editor>
</x-admin.page>
