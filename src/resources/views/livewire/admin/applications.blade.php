<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">

    <div>
        <flux:heading size="xl" level="1">{{ __('Application review') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Review submitted applications. Verification records review completion, not an admission decision.') }}</flux:text>
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
        <flux:input wire:model.live.debounce.300ms="search" :label="__('Search applications')" :placeholder="__('Application, candidate code, name or email')" maxlength="100" />
        <flux:select wire:model.live="roundFilter" :label="__('Admission round')">
            <option value="">{{ __('All rounds') }}</option>
            @foreach ($rounds as $round)<option wire:key="round-{{ $round->id }}" value="{{ $round->id }}">{{ $round->name }} ({{ $round->code }})</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="statusFilter" :label="__('Status')">
            <option value="">{{ __('Review queue: submitted / under review') }}</option>
            <option value="all">{{ __('All non-draft applications') }}</option>
            @foreach ($statuses as $status)<option wire:key="status-{{ $status->value }}" value="{{ $status->value }}">{{ __(ucwords(str_replace('_', ' ', $status->value))) }}</option>@endforeach
        </flux:select>
    </div>
    <div>
        <flux:button size="sm" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
    </div>
    <flux:error name="search" />
    <flux:error name="roundFilter" />
    <flux:error name="statusFilter" />
    <div role="status" wire:loading.delay>{{ __('Loading applications...') }}</div>
    <div class="overflow-x-auto rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:table :paginate="$records">
            <flux:table.columns>
                <flux:table.column>{{ __('Application / Candidate') }}</flux:table.column>
                <flux:table.column>{{ __('Round') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Submitted') }}</flux:table.column>
                <flux:table.column>{{ __('Latest reviewer') }}</flux:table.column>
                <flux:table.column>{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($records as $record)
                <flux:table.row :key="$record->id">
                    <flux:table.cell>
                        <div class="font-medium">{{ $record->application_code }}</div>
                        <div>{{ $record->candidateProfile?->user?->name ?? __('Unavailable candidate') }}</div>
                        <div class="text-xs">{{ $record->candidateProfile?->candidate_code }} · {{ $record->candidateProfile?->user?->email }}</div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $record->admissionRound?->name ?? __('Unavailable round') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge>{{ __(ucwords(str_replace('_', ' ', $record->status->value))) }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ $record->submitted_at?->format('Y-m-d H:i:s') ?? __('No recorded submission') }}</flux:table.cell>
                    <flux:table.cell>
                        <div>{{ $record->reviewer?->name ?? __('Not recorded / unavailable') }}</div>
                        <div class="text-xs">{{ $record->reviewed_at?->format('Y-m-d H:i:s') }}</div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:button size="sm" :href="route('admin.applications.show', $record->id)" wire:navigate>{{ __('Open review') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
                @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">
                        <div class="py-10 text-center">{{ __('No applications match this queue. Try changing or clearing the filters.') }}</div>
                    </flux:table.cell>
                </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
    <flux:text>{{ __('Times use') }} {{ config('app.timezone') }}. {{ __('Draft applications are not included in the review queue.') }}</flux:text>
</section>