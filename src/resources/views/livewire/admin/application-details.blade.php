<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><flux:heading size="xl" level="1">{{ $application->application_code }}</flux:heading><flux:text class="mt-2">{{ __('Application review details') }}</flux:text></div>
        <div class="flex flex-wrap gap-3"><flux:button :href="route('admin.applications.index')" wire:navigate>{{ __('Review queue') }}</flux:button><flux:button wire:click="reloadReview" wire:loading.attr="disabled">{{ __('Reload review data') }}</flux:button></div>
    </div>
    <flux:error name="review" /><flux:error name="form" />
    @if ($stale)<flux:callout variant="warning">{{ __('The review data changed. Reload and inspect the current information before making a decision.') }}</flux:callout>@endif
    @if ($hasResults)<flux:callout variant="warning">{{ __('Admission results already exist. All review mutations are blocked for this historical application.') }}</flux:callout>@endif
    <div role="status" wire:loading.delay>{{ __('Updating review...') }}</div>
    <div class="space-y-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap gap-3"><flux:badge>{{ __(ucwords(str_replace('_', ' ', $application->status->value))) }}</flux:badge>@if ($application->status === App\Enums\ApplicationStatus::Verified)<flux:badge color="green">{{ __('Review complete') }}</flux:badge>@endif</div>
        <flux:text>{{ __('Round') }}: {{ $application->admissionRound?->name ?? __('Unavailable') }} · {{ $application->admissionRound?->code }}</flux:text>
        <flux:text>{{ __('Latest submission') }}: {{ $application->submitted_at?->format('Y-m-d H:i:s') ?? __('Not recorded') }}</flux:text>
        <flux:text>{{ __('Latest application reviewer') }}: {{ $application->reviewer?->name ?? __('Not recorded / unavailable') }} · {{ __('Review time') }}: {{ $application->reviewed_at?->format('Y-m-d H:i:s') ?? __('Not recorded') }} ({{ config('app.timezone') }})</flux:text>
        <flux:text>{{ __('Review is collaborative. The latest reviewer is not an exclusive assignee.') }}</flux:text>
        @if ($application->revision_reason)<flux:callout><div class="font-medium">{{ __('Latest revision reason') }}</div><p class="whitespace-pre-wrap break-words">{{ $application->revision_reason }}</p></flux:callout>@endif
        @if (! $windowOpen)<flux:callout>{{ __('Advisory: this round is outside its open candidate submission window. Staff review can continue. A revision request will not grant a deadline exception for candidate resubmission.') }}</flux:callout>@endif
    </div>

    <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading size="lg">{{ __('Candidate profile — read only') }}</flux:heading>
        @if ($profile)
            <div class="flex flex-wrap gap-5">
                @if ($photoAvailable)<img src="{{ route('admission.profiles.photo', $profile->id) }}" alt="{{ __('Candidate profile photo') }}" class="h-40 w-30 rounded-lg object-cover" />@else<flux:badge color="amber">{{ __('Private profile photo unavailable') }}</flux:badge>@endif
                <div class="space-y-2"><div class="font-medium">{{ $profile->user?->name }}</div><flux:text>{{ $profile->user?->email }} · {{ $profile->candidate_code }}</flux:text><flux:badge>{{ __(ucfirst($profile->profile_status->value)) }}</flux:badge></div>
            </div>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (['date_of_birth' => 'Date of birth', 'gender' => 'Gender', 'citizen_id' => 'Citizen ID', 'phone' => 'Phone', 'address' => 'Address', 'province_code' => 'Province', 'high_school_code' => 'High school code', 'high_school_name' => 'High school', 'graduation_year' => 'Graduation year', 'priority_area' => 'Priority area', 'priority_object' => 'Priority object'] as $field => $label)
                    <div wire:key="profile-{{ $field }}"><dt class="text-sm text-zinc-500">{{ __($label) }}</dt><dd class="break-words">{{ $field === 'date_of_birth' ? ($profile->date_of_birth?->format('Y-m-d') ?? '—') : ($profile->getAttribute($field) ?? '—') }}</dd></div>
                @endforeach
            </dl>
            <flux:text>{{ __('Profile information is shared and may change after review. A verified profile status does not identify which version was reviewed.') }}</flux:text>
        @else<flux:callout variant="warning">{{ __('Candidate profile unavailable.') }}</flux:callout>@endif
    </div>

    <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading size="lg">{{ __('Candidate scores') }}</flux:heading>
        <flux:text>{{ __('Scores are profile-level records shared across applications. Verification locks that shared score against candidate edits; it does not calculate an admission score.') }}</flux:text>
        <div class="overflow-x-auto">
            <flux:table><flux:table.columns><flux:table.column>{{ __('Type / Subject') }}</flux:table.column><flux:table.column>{{ __('Score / Year') }}</flux:table.column><flux:table.column>{{ __('Review') }}</flux:table.column></flux:table.columns><flux:table.rows>
                @forelse ($profile?->scores ?? [] as $score)
                    <flux:table.row :key="$score->id">
                        <flux:table.cell><div>{{ $score->score_type }}</div><div>{{ $score->subject_code }} {{ $score->subject_name }}</div></flux:table.cell>
                        <flux:table.cell>{{ $score->score }} · {{ $score->exam_year }}</flux:table.cell>
                        <flux:table.cell><div class="flex flex-wrap gap-2"><flux:badge :color="$score->verified ? 'green' : 'zinc'">{{ $score->verified ? __('Verified') : __('Unverified') }}</flux:badge>@if ($reviewable && ! $score->verified)<flux:button size="sm" wire:click="confirm('verifyScore', {{ $score->id }})" :disabled="$stale" wire:loading.attr="disabled">{{ __('Verify score') }}</flux:button>@endif</div></flux:table.cell>
                    </flux:table.row>
                @empty<flux:table.row><flux:table.cell colspan="3">{{ __('No scores recorded. No score count or type is required in this phase.') }}</flux:table.cell></flux:table.row>@endforelse
            </flux:table.rows></flux:table>
        </div>
    </div>

    <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading size="lg">{{ __('Application documents') }}</flux:heading>
        @forelse ($application->documents as $document)
            <article wire:key="document-{{ $document->id }}" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="break-words font-medium">{{ $document->document_type }} · {{ $document->original_name }}</div>
                <flux:badge :color="match ($document->status) { App\Enums\DocumentStatus::Verified => 'green', App\Enums\DocumentStatus::Rejected => 'red', default => 'zinc' }">{{ __(ucfirst($document->status->value)) }}</flux:badge>
                @if ($document->rejection_reason)<p class="whitespace-pre-wrap break-words text-sm">{{ $document->rejection_reason }}</p>@endif
                @if (! $documentAvailability->get($document->id))<flux:callout variant="warning">{{ __('Private file unavailable. Verification is blocked; rejection can request a replacement.') }}</flux:callout>@endif
                <div class="flex flex-wrap gap-2">
                    @can('download', $document)<flux:button size="sm" :href="route('admission.documents.download', $document->id)">{{ __('Download private document') }}</flux:button>@endcan
                    @if ($reviewable && $document->status === App\Enums\DocumentStatus::Pending)
                        <flux:button size="sm" wire:click="confirm('verifyDocument', {{ $document->id }})" :disabled="$stale || ! $documentAvailability->get($document->id)" wire:loading.attr="disabled">{{ __('Verify document') }}</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="confirm('rejectDocument', {{ $document->id }})" :disabled="$stale" wire:loading.attr="disabled">{{ __('Reject document') }}</flux:button>
                    @endif
                </div>
            </article>
        @empty<flux:text>{{ __('No documents uploaded. No document count or type is required in this phase.') }}</flux:text>@endforelse
    </div>

    <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading size="lg">{{ __('Ranked admission wishes') }}</flux:heading>
        @forelse ($application->wishes->sortBy('priority') as $wish)
            <article wire:key="wish-{{ $wish->id }}" class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="font-medium">{{ $wish->priority }}. {{ $wish->admissionProgram?->major?->name ?? __('Missing major') }}</div>
                <flux:text>{{ $wish->admissionProgram?->major?->code }} · {{ $wish->admissionProgram?->admissionMethod?->name ?? __('Missing method') }} · {{ $wish->admissionProgram?->admissionMethod?->code }}</flux:text>
                @if ($wish->admissionProgram && ($wish->admissionProgram->status !== 'active' || ! $wish->admissionProgram->major?->is_active || ! $wish->admissionProgram->admissionMethod?->is_active || $wish->admissionProgram->quota <= 0))
                    <flux:badge color="amber">{{ __('Advisory: catalog inactive or configured capacity is zero') }}</flux:badge>
                @endif
                @if ($wish->result)<flux:badge color="amber">{{ __('Existing admission result: review blocked') }}</flux:badge>@endif
            </article>
        @empty<flux:text>{{ __('No wishes recorded.') }}</flux:text>@endforelse
        <flux:text>{{ __('Current catalog availability is advisory after submission. Review does not determine admission eligibility or allocate places.') }}</flux:text>
    </div>

    <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading size="lg">{{ __('Application verification checklist') }}</flux:heading>
        @if ($checklist === [])<flux:callout variant="success">{{ __('The current records meet the review checklist. All requirements will be checked again when you confirm.') }}</flux:callout>@else
            <ul class="list-inside list-disc space-y-2 text-sm">@foreach ($checklist as $key => $message)<li wire:key="check-{{ $key }}">{{ $message }}</li>@endforeach</ul>
        @endif
        <flux:text>{{ __('Verified means review complete, not admitted or accepted. Every existing document and score must be verified; zero documents and zero scores are allowed.') }}</flux:text>
        <div class="flex flex-wrap gap-3">
            @if ($canStart)<flux:button variant="primary" wire:click="confirm('start')" :disabled="$stale" wire:loading.attr="disabled">{{ __('Start review') }}</flux:button>@endif
            @if ($reviewable)
                <flux:button wire:click="confirm('revision')" :disabled="$stale" wire:loading.attr="disabled">{{ __('Request revision') }}</flux:button>
                <flux:button variant="primary" wire:click="confirm('verify')" :disabled="$stale || $checklist !== []" wire:loading.attr="disabled">{{ __('Verify application') }}</flux:button>
            @endif
            @if (! $canStart && ! $reviewable)<flux:badge>{{ __('Read-only application') }}</flux:badge>@endif
        </div>
    </div>

    <details class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <summary class="cursor-pointer font-medium">{{ __('Recent application review history') }}</summary>
        <div class="mt-4 space-y-3">
            @forelse ($history as $entry)<div wire:key="history-{{ $entry->id }}" class="text-sm"><div>{{ $entry->action }} · {{ $entry->user?->name ?? __('Reviewer unavailable') }} · {{ $entry->created_at->format('Y-m-d H:i:s') }}</div>@if ($entry->new_values['revision_reason'] ?? null)<p class="whitespace-pre-wrap break-words">{{ $entry->new_values['revision_reason'] }}</p>@endif</div>@empty<flux:text>{{ __('No Phase 6 review transitions recorded yet.') }}</flux:text>@endforelse
            <flux:text>{{ __('Showing up to 20 recent transitions. Earlier candidate submissions and content versions are not reconstructed.') }}</flux:text>
        </div>
    </details>

    <flux:modal wire:model="showConfirmation" class="w-full md:max-w-xl">
        <form wire:submit="perform" class="flex flex-col gap-5">
            <flux:heading>{{ match ($operation) { 'start' => __('Start review?'), 'revision' => __('Request revision?'), 'verify' => __('Mark application review complete?'), 'verifyDocument' => __('Verify this document?'), 'rejectDocument' => __('Reject this document?'), 'verifyScore' => __('Verify this shared score?'), default => __('Confirm review decision') } }}</flux:heading>
            <flux:text>{{ __('The current saved records and your permissions will be checked again before this decision is recorded.') }}</flux:text>
            @if ($operation === 'verifyScore')<flux:callout>{{ __('This verifies a profile-level score shared across applications. The candidate will no longer be able to edit or delete it.') }}</flux:callout>@endif
            @if ($operation === 'revision')
                <flux:textarea wire:model="form.revision_reason" :label="__('Revision reason')" maxlength="5000" required />
                <flux:callout>{{ __('Candidate wish changes and resubmission still require an open round within its date window. Requesting revision does not extend the deadline.') }}</flux:callout>
            @elseif ($operation === 'rejectDocument')<flux:textarea wire:model="form.rejection_reason" :label="__('Rejection reason')" maxlength="5000" required />@endif
            <flux:error name="form" /><flux:error name="form.revision_reason" /><flux:error name="form.rejection_reason" /><flux:error name="review" />
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="perform">{{ __('Confirm decision') }}</flux:button></div>
        </form>
    </flux:modal>
</section>
