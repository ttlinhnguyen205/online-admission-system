<x-admin.page
    :title="__('Admission Rounds')"
    :description="__('Set admission periods, dates and availability for each intake.')"
    :singular="__('round')"
    :resource-model="$resourceModel">
    <x-slot:filters>
        <flux:input
            wire:model.live.debounce.300ms="yearFilter"
            :label="__('Year')"
            type="number"
            min="2000"
            max="2100"
            :placeholder="__('All years')" />

        <flux:select wire:model.live="statusFilter" :label="__('Status')">
            <option value="">{{ __('All statuses') }}</option>

            @foreach (App\Enums\AdmissionRoundStatus::cases() as $status)
            <option
                wire:key="statusFilter-{{ $status->value }}"
                value="{{ $status->value }}">
                {{ __(ucfirst($status->value)) }}
            </option>
            @endforeach
        </flux:select>
    </x-slot:filters>

    <flux:table :paginate="$records">
        <flux:table.columns>
            <flux:table.column>{{ __('Round') }}</flux:table.column>
            <flux:table.column>{{ __('Year') }}</flux:table.column>
            <flux:table.column>{{ __('Application period') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Dependencies') }}</flux:table.column>
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
                    {{ $record->year }}
                </flux:table.cell>

                <flux:table.cell>
                    <div>{{ $record->start_date->format('d/m/Y H:i:s') }}</div>
                    <div>{{ $record->end_date->format('d/m/Y H:i:s') }}</div>
                    <div class="text-xs text-zinc-500">{{ config('app.timezone') }}</div>
                </flux:table.cell>

                <flux:table.cell>
                    <flux:badge>
                        {{ __(ucfirst($record->status->value)) }}
                    </flux:badge>
                </flux:table.cell>

                <flux:table.cell>
                    {{ $record->programs_count }} {{ __('programs') }} /
                    {{ $record->applications_count }} {{ __('applications') }}
                </flux:table.cell>

                <flux:table.cell>
                    <div class="flex justify-end gap-2">
                        <flux:button
                            size="sm"
                            wire:click="details({{ $record->id }})">
                            {{ __('View') }}
                        </flux:button>

                        @can('update', $record)
                        <flux:button
                            size="sm"
                            wire:click="edit({{ $record->id }})">
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
                <flux:table.cell colspan="6">
                    <div class="py-12 text-center">
                        @if ($this->search !== '' || $this->statusFilter !== '' || $this->yearFilter !== '')
                        <flux:heading>
                            {{ __('No admission rounds found') }}
                        </flux:heading>

                        <flux:text class="mt-2">
                            {{ __('Try another search or clear the filters.') }}
                        </flux:text>
                        @else
                        <flux:heading>
                            {{ __('No admission rounds yet') }}
                        </flux:heading>

                        <flux:text class="mt-2">
                            {{ __('No records have been configured. An administrator can create the first round.') }}
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

        <flux:input
            wire:model="form.year"
            :label="__('Admission year *')"
            type="number"
            min="2000"
            max="2100"
            required />

        <flux:select
            wire:model="form.status"
            :label="__('Status *')">
            @foreach (App\Enums\AdmissionRoundStatus::cases() as $status)
            <option
                wire:key="form.status-{{ $status->value }}"
                value="{{ $status->value }}">
                {{ __(ucfirst($status->value)) }}
            </option>
            @endforeach
        </flux:select>

        <flux:text>
            {{ __('Use Admission results to publish results. Published rounds cannot be reopened here.') }}
        </flux:text>

        <div class="sm:col-span-2">
            <flux:callout>
                {{ __('All date and time values use :timezone (application timezone). Enter times in this timezone; no automatic local-time conversion is applied.', ['timezone' => config('app.timezone')]) }}
            </flux:callout>
        </div>

        <flux:input
            wire:model="form.start_date"
            :label="__('Starts at *')"
            type="datetime-local"
            step="1"
            required />

        <flux:input
            wire:model="form.end_date"
            :label="__('Ends at *')"
            type="datetime-local"
            step="1"
            required />

        <flux:input
            wire:model="form.result_date"
            :label="__('Result date')"
            type="datetime-local"
            step="1" />
    </x-slot:editor>
</x-admin.page>