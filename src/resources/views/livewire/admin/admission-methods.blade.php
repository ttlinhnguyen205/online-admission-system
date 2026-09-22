<x-admin.page
    :title="__('Admission Methods')"
    :description="__('Manage admission methods and optional subject-weight configuration.')"
    :singular="__('method')"
    :resource-model="$resourceModel"
>
    <x-slot:filters>
        <flux:select wire:model.live="statusFilter" :label="__('Availability')">
            <option value="">{{ __('All statuses') }}</option>
            <option value="active">{{ __('Active') }}</option>
            <option value="inactive">{{ __('Inactive') }}</option>
        </flux:select>
    </x-slot:filters>

    <flux:table :paginate="$records">
        <flux:table.columns>
            <flux:table.column>{{ __('Method') }}</flux:table.column>
            <flux:table.column>{{ __('Score configuration') }}</flux:table.column>
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
                        {{ $record->score_config === null ? __('Not configured') : __('Configured') }}
                    </flux:table.cell>

                    <flux:table.cell>
                        <flux:badge :color="$record->is_active ? 'green' : 'zinc'">
                            {{ $record->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>

                    <flux:table.cell>
                        {{ $record->programs_count }}
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
                                <flux:heading>
                                    {{ __('No admission methods found') }}
                                </flux:heading>

                                <flux:text class="mt-2">
                                    {{ __('Try another search or clear the filters.') }}
                                </flux:text>
                            @else
                                <flux:heading>
                                    {{ __('No admission methods yet') }}
                                </flux:heading>

                                <flux:text class="mt-2">
                                    {{ __('No records have been configured. An administrator can create the first method.') }}
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
            required
        />

        <flux:input
            wire:model="form.name"
            :label="__('Name *')"
            maxlength="255"
            required
        />

        <div class="sm:col-span-2">
            <flux:textarea
                wire:model="form.description"
                :label="__('Description')"
                maxlength="10000"
                rows="3"
            />
        </div>

        <flux:checkbox
            wire:model="form.is_active"
            :label="__('Active')"
        />

        <div class="flex flex-col gap-4 sm:col-span-2">
            <flux:heading>
                {{ __('Subject weights') }}
            </flux:heading>

            <flux:text>
                {{ __('Optional. Add up to 30 unique subject codes and positive weights up to 100. This configures weights only; it does not calculate scores.') }}
            </flux:text>

            <flux:error name="form.weights" />

            @if ($this->unsupportedConfiguration)
                <flux:callout>
                    {{ __('This existing configuration uses another format. It will be preserved when you save other fields.') }}
                </flux:callout>

                <pre class="overflow-auto rounded-lg bg-zinc-100 p-3 text-xs dark:bg-zinc-900">{{ $this->configurationPreview }}</pre>
            @else
                @foreach ((is_array($this->form['weights'] ?? null) ? $this->form['weights'] : []) as $index => $weight)
                    <div
                        wire:key="weight-{{ $this->recordId ?? 'new' }}-{{ $index }}"
                        class="grid grid-cols-[1fr_1fr_auto] items-start gap-2"
                    >
                        <flux:input
                            wire:model="form.weights.{{ $index }}.subject"
                            :label="__('Subject code')"
                            placeholder="MATH"
                            maxlength="30"
                        />

                        <flux:input
                            wire:model="form.weights.{{ $index }}.weight"
                            :label="__('Weight')"
                            type="number"
                            min="0.001"
                            max="100"
                            step="0.001"
                        />

                        @if (! $this->readOnly)
                            <flux:button
                                wire:click="removeWeight({{ $index }})"
                                class="mt-6"
                                :aria-label="__('Remove subject')"
                            >
                                {{ __('Remove') }}
                            </flux:button>
                        @endif

                        <flux:error name="form.weights.{{ $index }}" />
                    </div>
                @endforeach

                @if (! $this->readOnly)
                    <flux:button
                        wire:click="addWeight"
                        :disabled="count(is_array($this->form['weights'] ?? null) ? $this->form['weights'] : []) >= 30"
                    >
                        {{ __('Add subject') }}
                    </flux:button>
                @endif
            @endif
        </div>
    </x-slot:editor>
</x-admin.page>