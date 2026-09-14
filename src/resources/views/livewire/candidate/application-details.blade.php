<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $application->application_code }}</flux:heading>
            <flux:text class="mt-2">{{ $round->name }} · {{ $round->code }}</flux:text>
        </div>
        <div class="flex flex-wrap gap-3">
            <flux:button :href="route('candidate.applications.index')" wire:navigate>{{ __('All applications') }}</flux:button>
            <flux:button :href="route('candidate.applications.documents.index', $application->id)" wire:navigate>{{ __('Application documents') }}</flux:button>
        </div>
    </div>
    <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap gap-3">
            <flux:badge>{{ __(ucwords(str_replace('_', ' ', $application->status->value))) }}</flux:badge>
            <flux:badge>{{ __('Round') }}: {{ __(ucfirst($round->status->value)) }}</flux:badge>
        </div>
        <flux:text>{{ __('Application window') }}: {{ $round->start_date->format('Y-m-d H:i:s') }} – {{ $round->end_date->format('Y-m-d H:i:s') }} ({{ config('app.timezone') }})</flux:text>
        <flux:text>{{ __('Latest submission') }}: {{ $application->submitted_at?->format('Y-m-d H:i:s') ?? __('Not submitted') }} ({{ config('app.timezone') }})</flux:text>
        @if ($application->revision_reason)
            <flux:callout><div class="font-medium">{{ __('Previous review: revision reason') }}</div><p class="whitespace-pre-wrap break-words">{{ $application->revision_reason }}</p></flux:callout>
        @endif
        @if (! $editable)
            <flux:callout>{{ __('Read-only: wish changes require a draft or needs-revision application and an open round within its date window.') }}</flux:callout>
        @endif
    </div>
    <flux:error name="round" />
    <flux:error name="wishes" />
    <flux:error name="order" />
    <flux:error name="order.*" />
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><flux:heading>{{ __('Ranked admission wishes / Nguyện vọng') }}</flux:heading><flux:text>{{ __('Priority 1 is your highest preference. Use Move up or Move down to change the order.') }}</flux:text></div>
        <flux:button size="sm" wire:click="reloadWishes" wire:loading.attr="disabled">{{ __('Reload wishes') }}</flux:button>
    </div>
    <div role="status" wire:loading.delay>{{ __('Updating application...') }}</div>
    <div class="flex flex-col gap-3">
        @forelse ($wishes as $wish)
            @php($reason = App\Actions\CandidateWishes::unavailableReason($wish->admissionProgram, $round))
            <article wire:key="wish-{{ $wish->id }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 sm:flex-row sm:justify-between">
                <div class="min-w-0 space-y-2">
                    <flux:heading>{{ $wish->priority }}. {{ $wish->admissionProgram->major->name }}</flux:heading>
                    <flux:text>{{ $wish->admissionProgram->major->code }} · {{ $wish->admissionProgram->admissionMethod->name }} ({{ $wish->admissionProgram->admissionMethod->code }})</flux:text>
                    <flux:text>{{ __('Round') }}: {{ $wish->admissionProgram->admissionRound->name }}</flux:text>
                    @if ($reason)<flux:badge color="amber">{{ $reason }}</flux:badge>@endif
                    @if ($wish->result_exists)<flux:text>{{ __('An admission result protects this wish from removal or priority changes.') }}</flux:text>@endif
                </div>
                @if ($editable)
                    <div class="flex flex-wrap items-start gap-2">
                        <flux:button size="sm" wire:click="moveWish({{ $wish->id }}, 'up')" :disabled="$loop->first || $wish->result_exists" wire:loading.attr="disabled">{{ __('Move up') }}</flux:button>
                        <flux:button size="sm" wire:click="moveWish({{ $wish->id }}, 'down')" :disabled="$loop->last || $wish->result_exists" wire:loading.attr="disabled">{{ __('Move down') }}</flux:button>
                        @can('delete', $wish)<flux:button size="sm" variant="ghost" wire:click="confirmDeletion({{ $wish->id }})" wire:loading.attr="disabled">{{ __('Remove') }}</flux:button>@endcan
                    </div>
                @endif
            </article>
        @empty
            <flux:callout>{{ __('No wishes yet. Choose a program below to add your first wish.') }}</flux:callout>
        @endforelse
    </div>
    @if ($editable)
        <form wire:submit="addWish" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading>{{ __('Add an admission wish') }}</flux:heading>
            <flux:error name="form" />
            <flux:select wire:model="form.admission_program_id" :label="__('Program / Major / Admission method')" required>
                <flux:select.option value="">{{ __('Select a program') }}</flux:select.option>
                @foreach ($programs as $program)
                    @php($unavailable = $reasons->get($program->id))
                    @php($alreadySelected = in_array($program->id, $selected, true))
                    <flux:select.option :value="$program->id" :disabled="$unavailable !== null || $alreadySelected" wire:key="program-{{ $program->id }}">{{ $program->major->name }} · {{ $program->admissionMethod->name }}{{ $alreadySelected ? ' — '.__('Already selected') : ($unavailable ? ' — '.$unavailable : '') }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text>{{ __('Only active programs with active majors and methods and positive configured capacity can be selected. Capacity is not a count of remaining places.') }}</flux:text>
            @if ($programs->isEmpty())<flux:callout>{{ __('No programs have been configured for this round.') }}</flux:callout>@endif
            <div><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="addWish">{{ __('Add wish at the end') }}</flux:button></div>
        </form>
    @endif
    <details class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <summary class="cursor-pointer font-medium">{{ __('Program information for this round') }}</summary>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            @forelse ($programs as $program)
                <div wire:key="catalog-{{ $program->id }}" class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="font-medium">{{ $program->major->name }} · {{ $program->admissionMethod->name }}</div>
                    <flux:text>{{ __('Configured capacity') }}: {{ $program->quota }} · {{ __('Minimum score') }}: {{ $program->minimum_score ?? __('Unspecified') }} · {{ __('Previous cutoff') }}: {{ $program->previous_cutoff_score ?? __('Unspecified') }}</flux:text>
                    <flux:text>{{ __('Tuition fee') }}: {{ $program->tuition_fee ?? __('Unspecified') }}</flux:text>
                    @if ($reasons->get($program->id))<flux:badge color="amber">{{ $reasons->get($program->id) }}</flux:badge>@endif
                </div>
            @empty
                <flux:text>{{ __('No programs for this round.') }}</flux:text>
            @endforelse
        </div>
    </details>
    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading>{{ __('Submission checklist') }}</flux:heading>
        <flux:error name="profile" />
        <flux:error name="submission" />
        @if ($checklist === [])
            <flux:callout variant="success">{{ __('Your saved profile, wishes and round are ready for submission. They will be checked again when you submit.') }}</flux:callout>
        @else
            <ul class="list-inside list-disc space-y-2 text-sm">
                @foreach ($checklist as $key => $message)<li wire:key="check-{{ $key }}">{{ $message }}</li>@endforeach
            </ul>
        @endif
        <flux:text>{{ __('No document count or document type is required for submission in this phase. You can manage application files on the Documents page.') }}</flux:text>
        <div class="flex flex-wrap gap-3">
            <flux:button :href="route('candidate.profile.edit')" wire:navigate>{{ __('Open candidate profile') }}</flux:button>
            @if ($editable)
                <flux:button variant="primary" wire:click="confirmSubmission" :disabled="$checklist !== []" wire:loading.attr="disabled">{{ $application->status === App\Enums\ApplicationStatus::NeedsRevision ? __('Resubmit application') : __('Submit application') }}</flux:button>
            @endif
        </div>
    </div>
    <flux:modal wire:model="showDeletion" class="md:w-96">
        <form wire:submit="deleteWish" class="flex flex-col gap-5">
            <flux:heading>{{ __('Remove this wish?') }}</flux:heading>
            <flux:text>{{ __('This wish will be removed. Remaining priorities will be updated in order.') }}</flux:text>
            <flux:error name="deletion" /><flux:error name="round" /><flux:error name="order" />
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="deleteWish">{{ __('Remove wish') }}</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model="showSubmission" class="w-full md:max-w-xl">
        <form wire:submit="submit" class="flex flex-col gap-5">
            <flux:heading>{{ __('Submit this application?') }}</flux:heading>
            <flux:text>{{ __('After submission, you cannot change wishes or application documents unless the application is returned for revision. Your saved information will be checked again now.') }}</flux:text>
            <flux:error name="submission" /><flux:error name="profile" /><flux:error name="round" /><flux:error name="wishes" />
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="submit">{{ __('Confirm submission') }}</flux:button></div>
        </form>
    </flux:modal>
</section>
