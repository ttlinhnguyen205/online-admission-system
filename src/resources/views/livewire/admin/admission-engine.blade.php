<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Admission engine') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Review readiness and allocate admission places for a closed round. Saved decisions are immutable and remain unpublished.') }}</flux:text>
    </div>

    <form wire:submit="preview" class="flex flex-wrap items-end gap-4">
        <flux:select wire:model="roundSelection" :label="__('Admission round')" class="min-w-64">
            <option value="">{{ __('Select a round') }}</option>
            @foreach ($rounds as $round)
                <option wire:key="round-{{ $round->id }}" value="{{ $round->id }}">{{ $round->year }} · {{ $round->name }} ({{ $round->status->value }})</option>
            @endforeach
        </flux:select>
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Preview readiness') }}</flux:button>
    </form>
    <flux:error name="roundSelection" />
    <flux:error name="engine" />
    <div role="status" wire:loading.delay>{{ __('Checking and processing admission data…') }}</div>

    @if ($previewData !== [])
        <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Readiness for round') }} #{{ $previewData['round_id'] }}</flux:heading>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (['round_status' => 'Round status', 'verified' => 'Verified applications ready', 'drafts' => 'Excluded drafts', 'submitted' => 'Submitted awaiting review', 'under_review' => 'Under review', 'needs_revision' => 'Needs revision', 'wishes' => 'Participating wishes ready', 'existing_results' => 'Existing results', 'existing_events' => 'Existing engine events'] as $field => $label)
                    <div wire:key="readiness-{{ $field }}"><dt class="text-sm text-zinc-500">{{ __($label) }}</dt><dd>{{ $previewData[$field] }}</dd></div>
                @endforeach
            </dl>
            <flux:text>{{ __('Drafts are excluded. Submitted, under-review, and needs-revision applications must be resolved first. Dates do not automatically close a round.') }}</flux:text>
        </div>

        <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Relevant programs') }}</flux:heading>
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Program') }}</flux:table.column>
                        <flux:table.column>{{ __('Method') }}</flux:table.column>
                        <flux:table.column>{{ __('Scoring contract') }}</flux:table.column>
                        <flux:table.column>{{ __('Quota') }}</flux:table.column>
                        <flux:table.column>{{ __('Minimum score') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($previewData['programs'] as $program)
                            <flux:table.row :key="'program-'.$program['id']">
                                <flux:table.cell>#{{ $program['id'] }}</flux:table.cell>
                                <flux:table.cell>{{ $program['method'] }}</flux:table.cell>
                                <flux:table.cell><flux:badge :color="$program['type'] === 'unsupported' ? 'red' : 'green'">{{ __(ucfirst($program['type'])) }}</flux:badge></flux:table.cell>
                                <flux:table.cell>{{ $program['quota'] }}</flux:table.cell>
                                <flux:table.cell>{{ $program['minimum_score'] ?? __('No threshold') }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row><flux:table.cell colspan="5">{{ __('No participating programs available.') }}</flux:table.cell></flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
            <flux:text>{{ __('Quota is total capacity and will not be decremented. Equal scores crossing a quota boundary block the entire run.') }}</flux:text>
        </div>

        @if ($previewData['blockers'] !== [])
            <flux:callout variant="warning">
                <flux:heading>{{ __('Processing blocked') }}</flux:heading>
                <ul class="mt-2 list-inside list-disc space-y-2">
                    @foreach ($previewData['blockers'] as $index => $blocker)
                        <li wire:key="blocker-{{ $index }}">{{ $blocker }}</li>
                    @endforeach
                </ul>
            </flux:callout>
        @elseif ($previewData['completed'])
            <flux:callout variant="success">{{ __('This round has a completed immutable run. Existing decisions are shown without recalculation.') }}</flux:callout>
        @else
            <flux:callout variant="success">{{ __('Ready for confirmation. Current records will be checked again before any decisions are saved.') }}</flux:callout>
            <div><flux:button variant="primary" wire:click="confirm" wire:loading.attr="disabled">{{ __('Review processing confirmation') }}</flux:button></div>
        @endif
    @endif

    @if ($summary !== [])
        <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" role="status">
            <flux:heading size="lg">{{ __('Saved admission summary') }}</flux:heading>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (['run_id' => 'Run', 'included' => 'Completed applications', 'excluded_drafts' => 'Excluded drafts at processing', 'wishes' => 'Processed wishes', 'results' => 'Results saved', 'admitted' => 'Admitted wishes', 'not_admitted' => 'Not admitted wishes', 'decided_at' => 'Decision time'] as $field => $label)
                    <div wire:key="summary-{{ $field }}"><dt class="text-sm text-zinc-500">{{ __($label) }}</dt><dd class="break-words">{{ $summary[$field] }}</dd></div>
                @endforeach
            </dl>
            <flux:text>{{ __('Results remain unpublished and unconfirmed. The round remains processing until the publication phase.') }}</flux:text>
        </div>
    @endif

    <flux:modal wire:model="showConfirmation" class="w-full md:max-w-xl">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Confirm admission processing') }}</flux:heading>
            <flux:text>{{ __('Process round') }} #{{ $roundId }}? {{ __('This saves immutable decisions for every participating wish and completes the verified applications. All changes succeed together or are rolled back.') }}</flux:text>
            <flux:text>{{ __('Processing runs synchronously. Wait for the saved summary before leaving this page.') }}</flux:text>
            <flux:error name="engine" />
            <div class="flex flex-wrap justify-end gap-3">
                <flux:modal.close><flux:button wire:loading.attr="disabled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="process" wire:loading.attr="disabled">{{ __('Process admission round') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
