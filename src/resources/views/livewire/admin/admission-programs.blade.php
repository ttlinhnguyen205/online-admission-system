<x-admin.page
    :title="__('Admission Programs')"
    :description="__('Connect a round, major and admission method with seats, score thresholds and tuition.')"
    :singular="__('program')"
    :resource-model="$resourceModel">
    <x-slot:filters>
        <flux:select wire:model.live="roundFilter" :label="__('Round')">
            <option value="">{{ __('All rounds') }}</option>
            @foreach ($rounds as $round)
            <option wire:key="roundFilter-{{ $round->id }}" value="{{ $round->id }}">
                {{ $round->code }} - {{ $round->name }}
            </option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="majorFilter" :label="__('Major')">
            <option value="">{{ __('All majors') }}</option>
            @foreach ($majors as $major)
            <option wire:key="majorFilter-{{ $major->id }}" value="{{ $major->id }}">
                {{ $major->code }} - {{ $major->name }}
            </option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="methodFilter" :label="__('Method')">
            <option value="">{{ __('All methods') }}</option>
            @foreach ($methods as $method)
            <option wire:key="methodFilter-{{ $method->id }}" value="{{ $method->id }}">
                {{ $method->code }} - {{ $method->name }}
            </option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="statusFilter" :label="__('Availability')">
            <option value="">{{ __('All statuses') }}</option>
            <option value="active">{{ __('Active') }}</option>
            <option value="inactive">{{ __('Inactive') }}</option>
        </flux:select>
    </x-slot:filters>

    <flux:table :paginate="$records">
        <flux:table.columns>
            <flux:table.column>{{ __('Offering') }}</flux:table.column>
            <flux:table.column>{{ __('Round') }}</flux:table.column>
            <flux:table.column>{{ __('Quota') }}</flux:table.column>
            <flux:table.column>{{ __('Minimum score') }}</flux:table.column>
            <flux:table.column>{{ __('Tuition') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($records as $record)
            <flux:table.row :key="$record->id">
                <flux:table.cell>
                    <div class="font-medium">{{ $record->major->name }}</div>
                    <div class="text-xs text-zinc-500">
                        {{ $record->major->code }} / {{ $record->admissionMethod->name }}
                    </div>
                </flux:table.cell>

                <flux:table.cell>{{ $record->admissionRound->code }}</flux:table.cell>

                <flux:table.cell>{{ number_format($record->quota) }}</flux:table.cell>

                <flux:table.cell>
                    {{ $record->minimum_score ?? __('Not specified') }}
                </flux:table.cell>

                <flux:table.cell>
                    {{ $record->tuition_fee === null ? __('Not specified') : number_format((float) $record->tuition_fee, 2) }}
                </flux:table.cell>

                <flux:table.cell>
                    <flux:badge :color="$record->status === 'active' ? 'green' : 'zinc'">
                        {{ __(ucfirst($record->status)) }}
                    </flux:badge>
                </flux:table.cell>

                <flux:table.cell>
                    <div class="flex justify-end gap-2">
                        <flux:button size="sm" wire:click="details({{ $record->id }})">
                            {{ __('View') }}
                        </flux:button>

                        @can('update', $record)
                        <flux:button size="sm" wire:click="edit({{ $record->id }})">
                            {{ __('Edit') }}
                        </flux:button>

                        <flux:button
                            size="sm"
                            variant="ghost"
                            wire:click="confirmDeletion({{ $record->id }})">
                            {{ __('Delete') }}
                        </flux:button>
                        @endcan
                    </div>
                </flux:table.cell>
            </flux:table.row>
            @empty
            <flux:table.row>
                <flux:table.cell colspan="7">
                    <div class="py-12 text-center">
                        @if ($this->search !== '' || $this->statusFilter !== '' || $this->roundFilter !== '' || $this->majorFilter !== '' || $this->methodFilter !== '')
                        <flux:heading>
                            {{ __('No admission programs found') }}
                        </flux:heading>

                        <flux:text class="mt-2">
                            {{ __('Try another search or clear the filters.') }}
                        </flux:text>
                        @else
                        <flux:heading>
                            {{ __('No admission programs yet') }}
                        </flux:heading>

                        <flux:text class="mt-2">
                            {{ __('No records have been configured. An administrator can create the first program.') }}
                        </flux:text>
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
            <flux:callout>
                {{ __('This program has wishes or candidate offerings. Its round, major and method cannot be changed.') }}
            </flux:callout>
        </div>
        @endif

        <flux:select
            wire:model="form.admission_round_id"
            :label="__('Admission round *')"
            :disabled="$this->relationshipsLocked"
            required>
            <option value="">{{ __('Select a round') }}</option>

            @foreach ($rounds as $round)
            <option
                wire:key="form.admission_round_id-{{ $round->id }}"
                value="{{ $round->id }}">
                {{ $round->code }} - {{ $round->name }}
            </option>
            @endforeach
        </flux:select>

        <flux:select
            wire:model="form.major_id"
            :label="__('Major *')"
            :disabled="$this->relationshipsLocked"
            required>
            <option value="">{{ __('Select a major') }}</option>

            @foreach ($majors as $major)
            <option
                wire:key="form.major_id-{{ $major->id }}"
                value="{{ $major->id }}">
                {{ $major->code }} - {{ $major->name }}{{ $major->is_active ? '' : ' (' . __('inactive') . ')' }}
            </option>
            @endforeach
        </flux:select>

        <flux:select
            wire:model="form.admission_method_id"
            :label="__('Admission method *')"
            :disabled="$this->relationshipsLocked"
            required>
            <option value="">{{ __('Select a method') }}</option>

            @foreach ($methods as $method)
            <option
                wire:key="form.admission_method_id-{{ $method->id }}"
                value="{{ $method->id }}">
                {{ $method->code }} - {{ $method->name }}{{ $method->is_active ? '' : ' (' . __('inactive') . ')' }}
            </option>
            @endforeach
        </flux:select>

        <flux:select wire:model="form.status" :label="__('Status *')">
            <option value="active">{{ __('Active') }}</option>
            <option value="inactive">{{ __('Inactive') }}</option>
        </flux:select>

        <flux:input
            wire:model="form.quota"
            :label="__('Quota *')"
            type="number"
            min="0"
            max="4294967295"
            step="1"
            :description="__('Zero means no seats are currently allocated.')"
            required />

        <flux:input
            wire:model="form.minimum_score"
            :label="__('Minimum score')"
            type="number"
            min="0"
            max="99999.999"
            step="0.001" />

        <flux:input
            wire:model="form.previous_cutoff_score"
            :label="__('Previous cutoff score')"
            type="number"
            min="0"
            max="99999.999"
            step="0.001" />

        <flux:input
            wire:model="form.tuition_fee"
            :label="__('Tuition fee')"
            type="number"
            min="0"
            max="9999999999999.99"
            step="0.01"
            :description="__('Leave blank if not specified.')" />
    </x-slot:editor>
</x-admin.page>