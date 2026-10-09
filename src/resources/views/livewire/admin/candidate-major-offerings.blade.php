<x-admin.page :title="__('Candidate Major Offerings')" :description="__('Configure the majors candidates may register for in each round.')" :singular="__('candidate major offering')" :resource-model="$resourceModel" burgundy>
    <x-slot:filters>
        <flux:select wire:model.live="roundFilter" :label="__('Round')">
            <option value="">{{ __('All rounds') }}</option>
            @foreach ($rounds as $round)
                <option wire:key="offering-filter-round-{{ $round->id }}" value="{{ $round->id }}">{{ $round->code }} · {{ $round->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="statusFilter" :label="__('Candidate registration')">
            <option value="">{{ __('All offerings') }}</option>
            <option value="enabled">{{ __('Registration enabled') }}</option>
            <option value="disabled">{{ __('Registration disabled') }}</option>
        </flux:select>
    </x-slot:filters>
    <flux:table :paginate="$records">
        <flux:table.columns>
            <flux:table.column>{{ __('Major') }}</flux:table.column>
            <flux:table.column>{{ __('Round') }}</flux:table.column>
            <flux:table.column>{{ __('Compatibility program') }}</flux:table.column>
            <flux:table.column>{{ __('Candidate registration') }}</flux:table.column>
            <flux:table.column>{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($records as $record)
                <flux:table.row :key="$record->id">
                    <flux:table.cell>{{ $record->major->name }} · {{ $record->major->code }}</flux:table.cell>
                    <flux:table.cell>{{ $record->admissionRound->code }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $record->admissionProgram?->admissionMethod?->name ?? __('Not configured') }}
                        @if ($record->admissionProgram)
                            <div class="text-xs text-zinc-500">#{{ $record->admission_program_id }} · {{ $record->admissionProgram->admissionMethod->code }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge :color="$record->is_selectable ? 'green' : 'zinc'">{{ $record->is_selectable ? __('Registration enabled') : __('Registration disabled') }}</flux:badge>
                        @if ($record->is_selectable)
                            <p class="mt-2 max-w-xs text-xs text-zinc-500 dark:text-zinc-400">
                                {{ App\Actions\CandidateMajorOfferings::available($record, $record->admissionProgram, $record->admissionRound) ? __('Currently accepting registration') : ($record->admissionProgram ? App\Actions\CandidateWishes::unavailableReason($record->admissionProgram, $record->admissionRound) : __('Not configured')) }}
                            </p>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" wire:click="edit({{ $record->id }})">{{ __('Edit') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="confirmDeletion({{ $record->id }})">{{ __('Delete') }}</flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row><flux:table.cell colspan="5"><div class="py-10 text-center"><flux:heading>{{ __('No candidate major offerings found') }}</flux:heading><flux:text class="mt-2">{{ __('Configure a program first, then enable its major for candidate registration. Clear filters to view all configured offerings.') }}</flux:text></div></flux:table.cell></flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    <x-slot:editor>
        <div class="sm:col-span-2">
            <flux:callout>{{ __('The compatibility program is transitional: each wish is evaluated only under its explicitly mapped legacy program, not every admission method. Candidates see only the major. Enabling registration also requires an open round within its dates, an active major/program/method and positive quota.') }}</flux:callout>
        </div>
        @if ($this->relationshipsLocked)
            <div class="sm:col-span-2"><flux:callout>{{ __('This offering has wishes. Its round, major and compatibility program cannot change. Existing wishes retain their pinned program.') }}</flux:callout></div>
        @endif
        <flux:select wire:model.live="form.admission_round_id" :label="__('Admission round *')" :disabled="$this->relationshipsLocked" required>
            <option value="">{{ __('Select a round') }}</option>
            @foreach ($rounds as $round)
                <option value="{{ $round->id }}" wire:key="offering-round-{{ $round->id }}">{{ $round->code }} · {{ $round->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="form.major_id" :label="__('Major *')" :disabled="$this->relationshipsLocked" required>
            <option value="">{{ __('Select a major') }}</option>
            @foreach ($majors as $major)
                <option value="{{ $major->id }}" wire:key="offering-major-{{ $major->id }}">{{ $major->code }} · {{ $major->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model="form.admission_program_id" :label="__('Explicit compatibility program')" :disabled="$this->relationshipsLocked" wire:key="offering-program-options-{{ $this->form['admission_round_id'] ?? '' }}-{{ $this->form['major_id'] ?? '' }}">
            <option value="">{{ __('Not configured') }}</option>
            @foreach ($programs as $program)
                <option value="{{ $program->id }}" wire:key="offering-program-{{ $program->id }}">#{{ $program->id }} · {{ $program->admissionMethod->name }} ({{ $program->admissionMethod->code }})</option>
            @endforeach
        </flux:select>
        <flux:checkbox wire:model="form.is_selectable" :label="__('Enable candidate registration')" />
    </x-slot:editor>
</x-admin.page>
