<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $application->application_code }}</flux:heading>
            <flux:text class="mt-2">{{ $round->name }} · {{ $round->code }}</flux:text>
        </div>
        <div class="flex flex-wrap gap-3">
            <flux:button :href="route('candidate.applications.index')" wire:navigate>{{ __('Danh sách hồ sơ') }}</flux:button>
            <flux:button :href="route('candidate.applications.documents.index', $application->id)" wire:navigate>{{ __('Tài liệu hồ sơ') }}</flux:button>
        </div>
    </div>
    <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap gap-3">
            <flux:badge>{{ App\Support\CandidateStatusLabels::application($application->status) }}</flux:badge>
            <flux:badge>{{ __('Đợt tuyển sinh') }}: {{ App\Support\CandidateStatusLabels::round($round->status) }}</flux:badge>
        </div>
        <flux:text>{{ __('Thời gian nhận hồ sơ') }}: {{ $round->start_date->format('Y-m-d H:i:s') }} – {{ $round->end_date->format('Y-m-d H:i:s') }} ({{ config('app.timezone') }})</flux:text>
        <flux:text>{{ __('Thời điểm nộp gần nhất') }}: {{ $application->submitted_at?->format('Y-m-d H:i:s') ?? __('Chưa nộp') }} ({{ config('app.timezone') }})</flux:text>
        @if ($application->revision_reason)
            <flux:callout><div class="font-medium">{{ __('Lý do yêu cầu bổ sung') }}</div><p class="whitespace-pre-wrap break-words">{{ $application->revision_reason }}</p></flux:callout>
        @endif
        @if (! $editable)
            <flux:callout>{{ __('Chỉ có thể sửa nguyện vọng khi hồ sơ ở trạng thái bản nháp hoặc cần bổ sung và đợt tuyển sinh còn nhận hồ sơ.') }}</flux:callout>
        @endif
    </div>
    <flux:error name="round" />
    <flux:error name="wishes" />
    <flux:error name="order" />
    <flux:error name="order.*" />
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><flux:heading>{{ __('Nguyện vọng xét tuyển') }}</flux:heading><flux:text>{{ __('Nguyện vọng 1 là ưu tiên cao nhất. Dùng các nút di chuyển để thay đổi thứ tự.') }}</flux:text></div>
        <flux:button size="sm" wire:click="reloadWishes" wire:loading.attr="disabled">{{ __('Tải lại nguyện vọng') }}</flux:button>
    </div>
    <div role="status" wire:loading.delay>{{ __('Đang cập nhật hồ sơ...') }}</div>
    <div class="flex flex-col gap-3">
        @forelse ($wishes as $wish)
            <article wire:key="wish-{{ $wish->id }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 sm:flex-row sm:justify-between">
                <div class="min-w-0 space-y-2">
                    <flux:text>{{ __('Nguyện vọng :priority', ['priority' => $wish->priority]) }}</flux:text>
                    <flux:heading>{{ $wish->admissionProgram->major->name }}</flux:heading>
                    <flux:text>{{ $wish->admissionProgram->major->code }}</flux:text>
                    @if ($wish->result_exists)<flux:text>{{ __('Nguyện vọng đã có kết quả xét tuyển nên không thể xóa hoặc thay đổi thứ tự.') }}</flux:text>@endif
                </div>
                @if ($editable)
                    <div class="flex flex-wrap items-start gap-2">
                        <flux:button size="sm" wire:click="moveWish({{ $wish->id }}, 'up')" :disabled="$loop->first || $wish->result_exists" wire:loading.attr="disabled">{{ __('Chuyển lên') }}</flux:button>
                        <flux:button size="sm" wire:click="moveWish({{ $wish->id }}, 'down')" :disabled="$loop->last || $wish->result_exists" wire:loading.attr="disabled">{{ __('Chuyển xuống') }}</flux:button>
                        @can('delete', $wish)<flux:button size="sm" variant="ghost" wire:click="confirmDeletion({{ $wish->id }})" wire:loading.attr="disabled">{{ __('Xóa') }}</flux:button>@endcan
                    </div>
                @endif
            </article>
        @empty
            <flux:callout>{{ __('Chưa có nguyện vọng. Hãy chọn ngành xét tuyển để thêm nguyện vọng đầu tiên.') }}</flux:callout>
        @endforelse
    </div>
    @if ($editable)
        <form wire:submit="addWish" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading>{{ __('Thêm nguyện vọng xét tuyển') }}</flux:heading>
            <flux:error name="form" />
            <flux:select wire:model="form.candidate_major_offering_id" :label="__('Ngành xét tuyển')" required>
                <flux:select.option value="">{{ __('Chọn ngành xét tuyển') }}</flux:select.option>
                @foreach ($offerings as $offering)
                    <flux:select.option :value="$offering->id" wire:key="offering-{{ $offering->id }}">{{ $offering->major->name }} · {{ $offering->major->code }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text>{{ __('Chọn ngành muốn học rồi sắp xếp theo thứ tự ưu tiên. Mỗi ngành chỉ đăng ký một lần.') }}</flux:text>
            @if ($offerings->isEmpty())<flux:callout>{{ __('Không còn ngành nào có thể thêm trong đợt tuyển sinh này.') }}</flux:callout>@endif
            <div><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="addWish">{{ __('Thêm nguyện vọng cuối danh sách') }}</flux:button></div>
        </form>
    @endif
    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading>{{ __('Điều kiện nộp hồ sơ') }}</flux:heading>
        <flux:error name="profile" />
        <flux:error name="submission" />
        @if ($checklist === [])
            <flux:callout variant="success">{{ __('Hồ sơ cá nhân, nguyện vọng và đợt tuyển sinh đã sẵn sàng. Hệ thống sẽ kiểm tra lại khi bạn nộp.') }}</flux:callout>
        @else
            <ul class="list-inside list-disc space-y-2 text-sm">
                @foreach ($checklist as $key => $message)<li wire:key="check-{{ $key }}">{{ $message }}</li>@endforeach
            </ul>
        @endif
        <flux:text>{{ __('Hiện không bắt buộc số lượng hoặc loại tài liệu khi nộp hồ sơ. Bạn có thể quản lý tài liệu trong mục Tài liệu hồ sơ của hồ sơ này.') }}</flux:text>
        <div class="flex flex-wrap gap-3">
            <flux:button :href="route('candidate.profile.edit')" wire:navigate>{{ __('Mở hồ sơ cá nhân') }}</flux:button>
            @if ($editable)
                <flux:button variant="primary" wire:click="confirmSubmission" :disabled="$checklist !== []" wire:loading.attr="disabled">{{ $application->status === App\Enums\ApplicationStatus::NeedsRevision ? __('Nộp lại hồ sơ') : __('Nộp hồ sơ') }}</flux:button>
            @endif
        </div>
    </div>
    <flux:modal wire:model="showDeletion" class="md:w-96">
        <form wire:submit="deleteWish" class="flex flex-col gap-5">
            <flux:heading>{{ __('Xóa nguyện vọng này?') }}</flux:heading>
            <flux:text>{{ __('Nguyện vọng sẽ bị xóa. Thứ tự ưu tiên còn lại sẽ được cập nhật.') }}</flux:text>
            <flux:error name="deletion" /><flux:error name="round" /><flux:error name="order" />
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="deleteWish">{{ __('Xóa nguyện vọng') }}</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model="showSubmission" class="w-full md:max-w-xl">
        <form wire:submit="submit" class="flex flex-col gap-5">
            <flux:heading>{{ __('Nộp hồ sơ này?') }}</flux:heading>
            <flux:text>{{ __('Sau khi nộp, bạn không thể sửa nguyện vọng hoặc tài liệu hồ sơ trừ khi được yêu cầu bổ sung. Hệ thống sẽ kiểm tra lại thông tin đã lưu.') }}</flux:text>
            <flux:error name="submission" /><flux:error name="profile" /><flux:error name="round" /><flux:error name="wishes" />
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="submit">{{ __('Xác nhận nộp hồ sơ') }}</flux:button></div>
        </form>
    </flux:modal>
    <div class="flex justify-end">
        <flux:button :href="route('candidate.results.index')" wire:navigate>{{ __('Tiếp theo: Kết quả xét tuyển') }}</flux:button>
    </div>
</section>
