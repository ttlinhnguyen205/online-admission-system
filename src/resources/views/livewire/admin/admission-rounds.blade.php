<x-admin.page title="Admission Rounds" description="Set admission periods, dates and availability for each intake." singular="round" :resource-model="$resourceModel">
    <x-slot:filters>
        <flux:input wire:model.live.debounce.300ms="yearFilter" label="Year" type="number" min="2000" max="2100" placeholder="All years" />
        <flux:select wire:model.live="statusFilter" label="Status">
            <option value="">All statuses</option>
            @foreach (App\Enums\AdmissionRoundStatus::cases() as $status)
                <option wire:key="statusFilter-{{ $status->value }}" value="{{ $status->value }}">{{ ucfirst($status->value) }}</option>
            @endforeach
        </flux:select>
    </x-slot:filters>
    <flux:table :paginate="$records">
        <flux:table.columns>
            <flux:table.column>Round</flux:table.column>
            <flux:table.column>Year</flux:table.column>
            <flux:table.column>Application period</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column>Dependencies</flux:table.column>
            <flux:table.column>Actions</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($records as $record)
                <flux:table.row :key="$record->id">
                    <flux:table.cell>
                        <div class="font-medium">{{ $record->name }}</div>
                        <div class="text-xs text-zinc-500">{{ $record->code }}</div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $record->year }}</flux:table.cell>
                    <flux:table.cell>
                        <div>{{ $record->start_date->format('d/m/Y H:i:s') }}</div>
                        <div>{{ $record->end_date->format('d/m/Y H:i:s') }}</div>
                        <div class="text-xs text-zinc-500">{{ config('app.timezone') }}</div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge>{{ ucfirst($record->status->value) }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ $record->programs_count }} programs / {{ $record->applications_count }} applications</flux:table.cell>
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
                    <flux:table.cell colspan="6">
                        <div class="py-12 text-center">
                            @if ($this->search !== '' || $this->statusFilter !== '' || $this->yearFilter !== '')
                                <flux:heading>No admission rounds found</flux:heading>
                                <flux:text class="mt-2">Try another search or clear the filters.</flux:text>
                            @else
                                <flux:heading>No admission rounds yet</flux:heading>
                                <flux:text class="mt-2">No records have been configured. An administrator can create the first round.</flux:text>
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
        <flux:input wire:model="form.year" label="Admission year *" type="number" min="2000" max="2100" required />
        <flux:select wire:model="form.status" label="Status *">
            @foreach (App\Enums\AdmissionRoundStatus::cases() as $status)
                <option wire:key="form.status-{{ $status->value }}" value="{{ $status->value }}">{{ ucfirst($status->value) }}</option>
            @endforeach
        </flux:select>
        <div class="sm:col-span-2">
            <flux:callout>All date and time values use {{ config('app.timezone') }} (application timezone). Enter times in this timezone; no automatic local-time conversion is applied.</flux:callout>
        </div>
        <flux:input wire:model="form.start_date" label="Starts at *" type="datetime-local" step="1" required />
        <flux:input wire:model="form.end_date" label="Ends at *" type="datetime-local" step="1" required />
        <flux:input wire:model="form.result_date" label="Result date" type="datetime-local" step="1" />
    </x-slot:editor>
</x-admin.page>
