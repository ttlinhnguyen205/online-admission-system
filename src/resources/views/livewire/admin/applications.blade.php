<section class="admission-workspace mx-auto flex min-w-0 w-full max-w-7xl flex-col gap-6">

    <x-admin.workspace-heading :title="__('Application review')" :description="__('Review submitted applications. Verification records review completion, not an admission decision.')" />
    <div class="admission-panel grid min-w-0 gap-4 p-5 sm:grid-cols-2 xl:grid-cols-3">
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
        <div class="flex flex-wrap items-center justify-between gap-3 sm:col-span-2 xl:col-span-3">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ number_format($records->total()) }} hồ sơ phù hợp</p>
            <flux:button wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
        </div>
        <flux:error name="search" />
        <flux:error name="roundFilter" />
        <flux:error name="statusFilter" />
        <div role="status" wire:loading.delay class="text-sm text-zinc-600 dark:text-zinc-300 sm:col-span-2 xl:col-span-3">{{ __('Loading applications...') }}</div>
    </div>
    <div class="admission-panel min-w-0 overflow-hidden">
        <flux:table :paginate="$records" class="admin-records-table">
            <flux:table.columns>
                <flux:table.column class="table-stt">STT</flux:table.column>
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
                    <flux:table.cell class="table-stt" data-label="STT">{{ $records->firstItem() + $loop->index }}</flux:table.cell>
                    <flux:table.cell data-label="{{ __('Application / Candidate') }}">
                        <div class="font-medium">{{ $record->application_code }}</div>
                        <div>{{ $record->candidateProfile?->user?->name ?? __('Unavailable candidate') }}</div>
                        <div class="text-xs">{{ $record->candidateProfile?->candidate_code }} | {{ $record->candidateProfile?->user?->email }}</div>
                    </flux:table.cell>
                    <flux:table.cell data-label="{{ __('Round') }}">{{ $record->admissionRound?->name ?? __('Unavailable round') }}</flux:table.cell>
                    <flux:table.cell data-label="{{ __('Status') }}">
                        <flux:badge :color="match ($record->status->value) { 'verified', 'completed' => 'green', 'needs_revision' => 'amber', 'rejected' => 'red', default => 'zinc' }">{{ __(ucwords(str_replace('_', ' ', $record->status->value))) }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell data-label="{{ __('Submitted') }}">{{ $record->submitted_at?->format('Y-m-d H:i:s') ?? __('No recorded submission') }}</flux:table.cell>
                    <flux:table.cell data-label="{{ __('Latest reviewer') }}">
                        <div>{{ $record->reviewer?->name ?? __('Not recorded / unavailable') }}</div>
                        <div class="text-xs">{{ $record->reviewed_at?->format('Y-m-d H:i:s') }}</div>
                    </flux:table.cell>
                    <flux:table.cell data-label="{{ __('Actions') }}">
                        <flux:button size="sm" :href="route('admin.applications.show', $record->id)" wire:navigate>{{ __('Open review') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
                @empty
                <flux:table.row>
                    <flux:table.cell colspan="7">
                        <div class="py-10 text-center">{{ __('No applications match this queue. Try changing or clearing the filters.') }}</div>
                    </flux:table.cell>
                </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
