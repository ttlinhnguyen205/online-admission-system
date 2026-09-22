<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Đăng ký nguyện vọng') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Tạo một hồ sơ cho mỗi đợt tuyển sinh, sắp xếp nguyện vọng rồi nộp khi đã sẵn sàng.') }}</flux:text>
        </div>
        @if ($profile && $rounds->isNotEmpty())
            <flux:button variant="primary" wire:click="create">{{ __('Tạo hồ sơ') }}</flux:button>
        @endif
    </div>
    @if (! $profile)
        <flux:callout>{{ __('Hãy tạo hồ sơ cá nhân trước khi đăng ký xét tuyển.') }} <a class="underline" href="{{ route('candidate.profile.edit') }}" wire:navigate>{{ __('Mở hồ sơ') }}</a></flux:callout>
    @else
        @if ($rounds->isEmpty())
            <flux:callout>{{ __('Hiện không có đợt tuyển sinh mới. Đợt tuyển sinh phải đang mở trong thời hạn nhận hồ sơ và bạn chưa có hồ sơ trong đợt đó.') }}</flux:callout>
        @endif
        <div role="status" wire:loading.delay>{{ __('Đang cập nhật hồ sơ...') }}</div>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:table :paginate="$records">
                <flux:table.columns>
                    <flux:table.column>{{ __('Mã hồ sơ') }}</flux:table.column>
                    <flux:table.column>{{ __('Đợt tuyển sinh') }}</flux:table.column>
                    <flux:table.column>{{ __('Trạng thái') }}</flux:table.column>
                    <flux:table.column>{{ __('Thời điểm nộp') }} ({{ config('app.timezone') }})</flux:table.column>
                    <flux:table.column>{{ __('Thao tác') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($records as $record)
                        <flux:table.row :key="$record->id">
                            <flux:table.cell>{{ $record->application_code }}</flux:table.cell>
                            <flux:table.cell><div>{{ $record->admissionRound->name }}</div><div class="text-xs">{{ $record->admissionRound->code }}</div></flux:table.cell>
                            <flux:table.cell><flux:badge>{{ App\Support\CandidateStatusLabels::application($record->status) }}</flux:badge></flux:table.cell>
                            <flux:table.cell>{{ $record->submitted_at?->format('Y-m-d H:i:s') ?? __('Chưa nộp') }}</flux:table.cell>
                            <flux:table.cell><flux:button size="sm" :href="route('candidate.applications.show', $record->id)" wire:navigate>{{ __('Mở hồ sơ') }}</flux:button></flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="5"><div class="py-10 text-center">{{ __('Chưa có hồ sơ nào. Hãy tạo bản nháp cho một đợt tuyển sinh đang mở.') }}</div></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
    <flux:modal wire:model="showEditor" class="w-full md:max-w-xl">
        <form wire:submit="save" class="flex flex-col gap-5">
            <flux:heading>{{ __('Tạo hồ sơ bản nháp') }}</flux:heading>
            <flux:text>{{ __('Bạn có thể hoàn thiện hồ sơ và tài liệu sau khi tạo bản nháp. Không thể đổi đợt tuyển sinh sau đó.') }}</flux:text>
            <flux:error name="form" />
            <flux:select wire:model="form.admission_round_id" :label="__('Đợt tuyển sinh')" required>
                <flux:select.option value="">{{ __('Chọn đợt tuyển sinh') }}</flux:select.option>
                @foreach ($rounds as $round)
                    <flux:select.option :value="$round->id" wire:key="round-{{ $round->id }}">{{ $round->name }} · {{ $round->code }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text>{{ __('Tất cả mốc thời gian của đợt tuyển sinh dùng múi giờ') }} {{ config('app.timezone') }}.</flux:text>
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">{{ __('Tạo bản nháp') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    <div class="flex justify-end">
        <flux:button :href="route('candidate.results.index')" wire:navigate>{{ __('Tiếp theo: Kết quả xét tuyển') }}</flux:button>
    </div>
</section>
