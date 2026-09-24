<x-admin.page title="Admission Programs" description="Connect a round, major and admission method with seats, score thresholds and tuition." singular="program" :resource-model="$resourceModel">
    <x-slot:filters>
        <flux:select wire:model.live="roundFilter" label="Round">
            <option value="">All rounds</option>
            @foreach ($rounds as $round)
                <option wire:key="roundFilter-{{ $round->id }}" value="{{ $round->id }}">{{ $round->code }} - {{ $round->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="majorFilter" label="Major">
            <option value="">All majors</option>
            @foreach ($majors as $major)
                <option wire:key="majorFilter-{{ $major->id }}" value="{{ $major->id }}">{{ $major->code }} - {{ $major->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="methodFilter" label="Method">
            <option value="">All methods</option>
            @foreach ($methods as $method)
                <option wire:key="methodFilter-{{ $method->id }}" value="{{ $method->id }}">{{ $method->code }} - {{ $method->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="statusFilter" label="Availability">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </flux:select>
    </x-slot:filters>
    <flux:table :paginate="$records">
        <flux:table.columns>
            <flux:table.column>Offering</flux:table.column>
            <flux:table.column>Round</flux:table.column>
            <flux:table.column>Quota</flux:table.column>
            <flux:table.column>Minimum score</flux:table.column>
            <flux:table.column>Tuition</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column>Actions</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($records as $record)
                <flux:table.row :key="$record->id">
                    <flux:table.cell>
                        <div class="font-medium">{{ $record->major->name }}</div>
                        <div class="text-xs text-zinc-500">{{ $record->major->code }} / {{ $record->admissionMethod->name }}</div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $record->admissionRound->code }}</flux:table.cell>
                    <flux:table.cell>{{ number_format($record->quota) }}</flux:table.cell>
                    <flux:table.cell>{{ $record->minimum_score ?? 'Not specified' }}</flux:table.cell>
                    <flux:table.cell>{{ $record->tuition_fee === null ? 'Not specified' : number_format((float) $record->tuition_fee, 2) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge :color="$record->status === 'active' ? 'green' : 'zinc'">{{ ucfirst($record->status) }}</flux:badge>
                    </flux:table.cell>
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
                    <flux:table.cell colspan="7">
                        <div class="py-12 text-center">
                            @if ($this->search !== '' || $this->statusFilter !== '' || $this->roundFilter !== '' || $this->majorFilter !== '' || $this->methodFilter !== '')
                                <flux:heading>No admission programs found</flux:heading>
                                <flux:text class="mt-2">Try another search or clear the filters.</flux:text>
                            @else
                                <flux:heading>No admission programs yet</flux:heading>
                                <flux:text class="mt-2">No records have been configured. An administrator can create the first program.</flux:text>
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    <x-slot:editor>
        @if ($this->relationshipsLocked)
            <div class="sm:col-span-2">
                <flux:callout>This program has wishes or candidate offerings. Its round, major and method cannot be changed.</flux:callout>
            </div>
        @endif
        <flux:select wire:model="form.admission_round_id" label="Admission round *" :disabled="$this->relationshipsLocked" required>
            <option value="">Select a round</option>
            @foreach ($rounds as $round)
                <option wire:key="form.admission_round_id-{{ $round->id }}" value="{{ $round->id }}">{{ $round->code }} - {{ $round->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model="form.major_id" label="Major *" :disabled="$this->relationshipsLocked" required>
            <option value="">Select a major</option>
            @foreach ($majors as $major)
                <option wire:key="form.major_id-{{ $major->id }}" value="{{ $major->id }}">{{ $major->code }} - {{ $major->name }}{{ $major->is_active ? '' : ' (inactive)' }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model="form.admission_method_id" label="Admission method *" :disabled="$this->relationshipsLocked" required>
            <option value="">Select a method</option>
            @foreach ($methods as $method)
                <option wire:key="form.admission_method_id-{{ $method->id }}" value="{{ $method->id }}">{{ $method->code }} - {{ $method->name }}{{ $method->is_active ? '' : ' (inactive)' }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model="form.status" label="Status *">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </flux:select>
        <flux:input wire:model="form.quota" label="Quota *" type="number" min="0" max="4294967295" step="1" description="Zero means no seats are currently allocated." required />
        <flux:input wire:model="form.minimum_score" label="Minimum score" type="number" min="0" max="99999.999" step="0.001" />
        <flux:input wire:model="form.previous_cutoff_score" label="Previous cutoff score" type="number" min="0" max="99999.999" step="0.001" />
        <flux:input wire:model="form.tuition_fee" label="Tuition fee" type="number" min="0" max="9999999999999.99" step="0.01" description="Leave blank if not specified." />
    </x-slot:editor>
</x-admin.page>
