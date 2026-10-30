<x-admin.page :title="__('Candidate Major Offerings')" :description="__('Configure the majors candidates may register for in each round.')" :singular="__('candidate major offering')" :total="$records->total()"
    :resource-model="$resourceModel" burgundy>
    <x-slot:filters>
        <flux:select wire:model.live="roundFilter" :label="__('Round')">
            <option value="">{{ __('All rounds') }}</option>
            @foreach ($rounds as $round)
                <option wire:key="offering-filter-round-{{ $round->id }}" value="{{ $round->id }}">{{ $round->code }} | {{ $round->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="statusFilter" :label="__('Đăng ký')">
            <option value="">{{ __('All offerings') }}</option>
            <option value="enabled">{{ __('Registration enabled') }}</option>
            <option value="disabled">{{ __('Registration disabled') }}</option>
        </flux:select>
    </x-slot:filters>
    <flux:table :paginate="$records" class="admin-records-table">
        <flux:table.columns>
            <flux:table.column class="table-stt">STT</flux:table.column>
            <flux:table.column>{{ __('Major') }}</flux:table.column>
            <flux:table.column>{{ __('Round') }}</flux:table.column>
            <flux:table.column>{{ __('Chương trình') }}</flux:table.column>
            <flux:table.column>Phương thức</flux:table.column>
            <flux:table.column>{{ __('Đăng ký') }}</flux:table.column>
            <flux:table.column>Tính điểm</flux:table.column>
            <flux:table.column>{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($records as $record)
                <flux:table.row :key="$record->id">
                    <flux:table.cell class="table-stt" data-label="STT">{{ $records->firstItem() + $loop->index }}</flux:table.cell>
                    <flux:table.cell data-label="{{ __('Major') }}">{{ $record->major->name }} | {{ $record->major->code }}</flux:table.cell>
                    <flux:table.cell data-label="{{ __('Round') }}">{{ $record->admissionRound->code }}</flux:table.cell>
                    <flux:table.cell data-label="{{ __('Chương trình') }}">
                        {{ $record->admissionProgram?->admissionMethod?->name ?? __('Not configured') }}
                        @if ($record->admissionProgram)
                            <div class="text-xs text-zinc-500">#{{ $record->admission_program_id }} | {{ $record->admissionProgram->admissionMethod->code }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell data-label="Phương thức">
                        <div class="flex flex-wrap gap-1">
                            @foreach ($catalogPrograms->get($record->admission_round_id.'-'.$record->major_id, collect()) as $catalogProgram)
                                <flux:badge wire:key="offering-method-{{ $record->id }}-{{ $catalogProgram->id }}" :color="$catalogProgram->status === 'active' && $catalogProgram->admissionMethod->is_active ? 'green' : 'zinc'">
                                    {{ $catalogProgram->admissionMethod->name }}
                                    @if ($catalogProgram->status !== 'active' || ! $catalogProgram->admissionMethod->is_active)
                                        · Tạm ngừng
                                    @endif
                                </flux:badge>
                            @endforeach
                        </div>
                        <flux:button size="sm" variant="ghost" :href="route('admin.admission-programs.index', ['roundFilter' => $record->admission_round_id, 'majorFilter' => $record->major_id])" wire:navigate>Cấu hình</flux:button>
                    </flux:table.cell>
                    <flux:table.cell data-label="{{ __('Đăng ký') }}">
                        <flux:badge :color="$record->is_selectable ? 'green' : 'zinc'">{{ $record->is_selectable ? __('Registration enabled') : __('Registration disabled') }}</flux:badge>
                        @if ($record->is_selectable && $record->admissionRound->nativeRegistrationState() === 'legacy')
                            <p class="mt-2 max-w-xs whitespace-normal text-xs text-zinc-500 dark:text-zinc-400">
                                {{ App\Actions\CandidateMajorOfferings::available($record, $record->admissionProgram, $record->admissionRound) ? __('Currently accepting registration') : ($record->admissionProgram ? App\Actions\CandidateWishes::unavailableReason($record->admissionProgram, $record->admissionRound) : __('Not configured')) }}
                            </p>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell data-label="Tính điểm">
                        @if ($record->admissionRound->nativeRegistrationState() === 'legacy')
                            <flux:badge color="zinc">Không áp dụng</flux:badge>
                        @else
                        <flux:badge :color="$nativeReadiness[$record->id]['status'] === 'READY' ? 'green' : 'amber'">{{ __($nativeReadiness[$record->id]['status']) }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell data-label="{{ __('Actions') }}">
                        <div class="flex flex-wrap justify-start gap-2">
                            <flux:button size="sm" wire:click="edit({{ $record->id }})">{{ __('Edit') }}</flux:button>
                            @if ($record->admissionRound->nativeRegistrationState() !== 'legacy')
                                <flux:button size="sm" wire:click="manageQuota({{ $record->id }})">Chỉ tiêu</flux:button>
                            @else
                                <flux:button size="sm" :href="route('admin.admission-programs.index', ['roundFilter' => $record->admission_round_id, 'majorFilter' => $record->major_id])" wire:navigate>Chỉ tiêu</flux:button>
                            @endif
                            <flux:button size="sm" variant="ghost" wire:click="confirmDeletion({{ $record->id }})">{{ __('Delete') }}</flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row><flux:table.cell colspan="8"><div class="py-10 text-center"><flux:heading>{{ __('No candidate major offerings found') }}</flux:heading><flux:text class="mt-2">{{ __('Configure a program first, then enable its major for candidate registration. Clear filters to view all configured offerings.') }}</flux:text></div></flux:table.cell></flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    @if ($quotaOfferingId !== null)
        <livewire:admin.offering-quotas :offering-id="$quotaOfferingId" :key="'offering-quotas-'.$quotaOfferingId" />
    @endif
    <x-slot:editor>
        @if ($this->relationshipsLocked)
            <div class="sm:col-span-2"><flux:callout>{{ __('This offering has wishes. Its round, major and compatibility program cannot change. Existing wishes retain their pinned program.') }}</flux:callout></div>
        @endif
        <flux:select wire:model.live="form.admission_round_id" :label="__('Admission round *')" :disabled="$this->relationshipsLocked" required>
            <option value="">{{ __('Select a round') }}</option>
            @foreach ($rounds as $round)
                <option value="{{ $round->id }}" wire:key="offering-round-{{ $round->id }}">{{ $round->code }} | {{ $round->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="form.major_id" :label="__('Major *')" :disabled="$this->relationshipsLocked" required>
            <option value="">{{ __('Select a major') }}</option>
            @foreach ($majors as $major)
                <option value="{{ $major->id }}" wire:key="offering-major-{{ $major->id }}">{{ $major->code }} | {{ $major->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model="form.admission_program_id" :label="__('Chương trình')" :disabled="$this->relationshipsLocked" wire:key="offering-program-options-{{ $this->form['admission_round_id'] ?? '' }}-{{ $this->form['major_id'] ?? '' }}">
            <option value="">{{ __('Not configured') }}</option>
            @foreach ($programs as $program)
                <option value="{{ $program->id }}" wire:key="offering-program-{{ $program->id }}">#{{ $program->id }} | {{ $program->admissionMethod->name }} ({{ $program->admissionMethod->code }})</option>
            @endforeach
        </flux:select>
        <flux:checkbox wire:model="form.is_selectable" :label="__('Enable candidate registration')" />
    </x-slot:editor>
</x-admin.page>
