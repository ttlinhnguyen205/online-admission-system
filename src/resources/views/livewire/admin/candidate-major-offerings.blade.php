<x-admin.page title="Candidate Major Offerings" description="Configure the majors candidates may register for in each round." singular="candidate major offering" :resource-model="$resourceModel">
    <x-slot:filters>
        <flux:select wire:model.live="statusFilter" label="Candidate registration">
            <option value="">All offerings</option>
            <option value="enabled">Enabled</option>
            <option value="disabled">Disabled</option>
        </flux:select>
    </x-slot:filters>
    <flux:table :paginate="$records">
        <flux:table.columns>
            <flux:table.column>Major</flux:table.column>
            <flux:table.column>Round</flux:table.column>
            <flux:table.column>Compatibility program</flux:table.column>
            <flux:table.column>Registration</flux:table.column>
            <flux:table.column>Actions</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($records as $record)
                <flux:table.row :key="$record->id">
                    <flux:table.cell>{{ $record->major->name }} · {{ $record->major->code }}</flux:table.cell>
                    <flux:table.cell>{{ $record->admissionRound->code }}</flux:table.cell>
                    <flux:table.cell>{{ $record->admissionProgram?->admissionMethod?->name ?? 'Not configured' }}</flux:table.cell>
                    <flux:table.cell>{{ $record->is_selectable ? 'Enabled' : 'Disabled' }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" wire:click="edit({{ $record->id }})">Edit</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="confirmDeletion({{ $record->id }})">Delete</flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row><flux:table.cell colspan="5">No candidate major offerings configured.</flux:table.cell></flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    <x-slot:editor>
        <div class="sm:col-span-2">
            <flux:callout>The compatibility program is transitional: each wish is evaluated only under its explicitly mapped legacy program, not every admission method. Candidates see only the major. Enabling registration also requires an open round within its dates, an active major/program/method and positive quota.</flux:callout>
        </div>
        @if ($this->relationshipsLocked)
            <div class="sm:col-span-2"><flux:callout>This offering has wishes. Its round, major and compatibility program cannot change. Existing wishes retain their pinned program.</flux:callout></div>
        @endif
        <flux:select wire:model.live="form.admission_round_id" label="Admission round *" :disabled="$this->relationshipsLocked" required>
            <option value="">Select a round</option>
            @foreach ($rounds as $round)
                <option value="{{ $round->id }}" wire:key="offering-round-{{ $round->id }}">{{ $round->code }} · {{ $round->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="form.major_id" label="Major *" :disabled="$this->relationshipsLocked" required>
            <option value="">Select a major</option>
            @foreach ($majors as $major)
                <option value="{{ $major->id }}" wire:key="offering-major-{{ $major->id }}">{{ $major->code }} · {{ $major->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model="form.admission_program_id" label="Explicit compatibility program" :disabled="$this->relationshipsLocked">
            <option value="">Not configured</option>
            @foreach ($programs as $program)
                <option value="{{ $program->id }}" wire:key="offering-program-{{ $program->id }}">#{{ $program->id }} · {{ $program->admissionMethod->name }} ({{ $program->admissionMethod->code }})</option>
            @endforeach
        </flux:select>
        <flux:checkbox wire:model="form.is_selectable" label="Enable candidate registration" />
    </x-slot:editor>
</x-admin.page>