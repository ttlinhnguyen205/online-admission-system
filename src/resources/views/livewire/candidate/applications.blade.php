<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Admission applications / Hồ sơ xét tuyển') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Create one application per round, rank your wishes, then submit when ready.') }}</flux:text>
        </div>
        @if ($profile && $rounds->isNotEmpty())
            <flux:button variant="primary" wire:click="create">{{ __('Create application') }}</flux:button>
        @endif
    </div>
    @if (! $profile)
        <flux:callout>{{ __('Create your candidate profile before creating an application.') }} <a class="underline" href="{{ route('candidate.profile.edit') }}" wire:navigate>{{ __('Open profile') }}</a></flux:callout>
    @else
        @if ($rounds->isEmpty())
            <flux:callout>{{ __('No new admission round is available right now. A round must be open within its date window and must not already have your application.') }}</flux:callout>
        @endif
        <div role="status" wire:loading.delay>{{ __('Updating applications...') }}</div>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:table :paginate="$records">
                <flux:table.columns>
                    <flux:table.column>{{ __('Application code') }}</flux:table.column>
                    <flux:table.column>{{ __('Admission round') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Submitted at') }} ({{ config('app.timezone') }})</flux:table.column>
                    <flux:table.column>{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($records as $record)
                        <flux:table.row :key="$record->id">
                            <flux:table.cell>{{ $record->application_code }}</flux:table.cell>
                            <flux:table.cell><div>{{ $record->admissionRound->name }}</div><div class="text-xs">{{ $record->admissionRound->code }}</div></flux:table.cell>
                            <flux:table.cell><flux:badge>{{ __(ucwords(str_replace('_', ' ', $record->status->value))) }}</flux:badge></flux:table.cell>
                            <flux:table.cell>{{ $record->submitted_at?->format('Y-m-d H:i:s') ?? __('Not submitted') }}</flux:table.cell>
                            <flux:table.cell><flux:button size="sm" :href="route('candidate.applications.show', $record->id)" wire:navigate>{{ __('Open application') }}</flux:button></flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="5"><div class="py-10 text-center">{{ __('No applications yet. Create a draft for an available admission round.') }}</div></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
    <flux:modal wire:model="showEditor" class="w-full md:max-w-xl">
        <form wire:submit="save" class="flex flex-col gap-5">
            <flux:heading>{{ __('Create a draft application') }}</flux:heading>
            <flux:text>{{ __('You can complete your profile and documents after creating the draft. The round cannot be changed afterward.') }}</flux:text>
            <flux:error name="form" />
            <flux:select wire:model="form.admission_round_id" :label="__('Admission round')" required>
                <flux:select.option value="">{{ __('Select an admission round') }}</flux:select.option>
                @foreach ($rounds as $round)
                    <flux:select.option :value="$round->id" wire:key="round-{{ $round->id }}">{{ $round->name }} · {{ $round->code }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text>{{ __('All round dates use') }} {{ config('app.timezone') }}.</flux:text>
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button>{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">{{ __('Create draft') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
