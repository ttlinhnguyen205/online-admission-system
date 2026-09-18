<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <flux:heading size="xl" level="1">{{ __('Admission results') }}</flux:heading>
    <flux:text>{{ __('Review saved admission decisions before publishing. Publication does not recalculate results.') }}</flux:text>
    <flux:select wire:model.live="roundSelection" :label="__('Admission round')">
        <option value="">{{ __('Select a round') }}</option>
        @foreach ($rounds as $round)
            <option wire:key="round-{{ $round->id }}" value="{{ $round->id }}">{{ $round->year }} · {{ $round->name }} ({{ $round->status->value }})</option>
        @endforeach
    </flux:select>
    <flux:error name="roundSelection" />
    <flux:error name="engine" />
    @if ($selectedRound)
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($counts as $label => $count)
                <div wire:key="count-{{ $loop->index }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><dt>{{ __($label) }}</dt><dd class="text-2xl font-semibold">{{ $count }}</dd></div>
            @endforeach
        </dl>
        @if ($selectedRound->status->value === 'published')
            <flux:text>{{ __('Already published. Original publication times are preserved.') }}</flux:text>
        @elseif ($selectedRound->status->value === 'processing')
            <flux:button variant="primary" wire:click="publish" wire:confirm="{{ __('Publish these saved results and notify candidates?') }}" wire:loading.attr="disabled">{{ __('Publish results') }}</flux:button>
        @else
            <flux:text>{{ __('Only a processing round with a completed admission-engine run can be published.') }}</flux:text>
        @endif
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr>@foreach (['Application', 'Priority', 'Major / method', 'Final score', 'Rank', 'Decision', 'Published', 'Confirmed'] as $label)<th class="p-3">{{ __($label) }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($results as $result)
                        <tr wire:key="result-{{ $result->id }}" class="border-t border-zinc-200 dark:border-zinc-700">
                            <td class="p-3">{{ $result->admissionWish->application->application_code }}</td>
                            <td class="p-3">{{ $result->admissionWish->priority }}</td>
                            <td class="p-3">{{ $result->admissionWish->admissionProgram->major->name }} / {{ $result->admissionWish->admissionProgram->admissionMethod->name }}</td>
                            <td class="p-3">{{ $result->final_score }}</td><td class="p-3">{{ $result->rank ?? '—' }}</td>
                            <td class="p-3">{{ $result->getRawOriginal('decision') === 'admitted' ? __('Admitted') : __('Not admitted') }}</td>
                            <td class="p-3">{{ $result->published_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                            <td class="p-3">{{ $result->confirmed_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-3">{{ __('No results for this round.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $results->links() }}
    @endif
    <div role="status" wire:loading.delay>{{ __('Loading…') }}</div>
</section>