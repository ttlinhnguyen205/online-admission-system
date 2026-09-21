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
            @php($reason = App\Actions\CandidateWishes::unavailableReason($wish->admissionProgram, $round))
            <article wire:key="wish-{{ $wish->id }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 sm:flex-row sm:justify-between">
                <div class="min-w-0 space-y-2">
                    <flux:heading>{{ $wish->priority }}. {{ $wish->admissionProgram->major->name }}</flux:heading>
                    <flux:text>{{ $wish->admissionProgram->major->code }} · {{ $wish->admissionProgram->admissionMethod->name }} ({{ $wish->admissionProgram->admissionMethod->code }})</flux:text>
                    <flux:text>{{ __('Đợt tuyển sinh') }}: {{ $wish->admissionProgram->admissionRound->name }}</flux:text>
                    @if ($reason)<flux:badge color="amber">{{ $reason }}</flux:badge>@endif
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
            <flux:callout>{{ __('Chưa có nguyện vọng. Hãy chọn chương trình bên dưới để thêm nguyện vọng đầu tiên.') }}</flux:callout>
        @endforelse
    </div>
    @if ($editable)
        <form wire:submit="addWish" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading>{{ __('Thêm nguyện vọng xét tuyển') }}</flux:heading>
            <flux:error name="form" />
            <flux:select wire:model="form.admission_program_id" :label="__('Chương trình / Ngành / Phương thức xét tuyển')" required>
                <flux:select.option value="">{{ __('Chọn chương trình') }}</flux:select.option>
                @foreach ($programs as $program)
                    @php($unavailable = $reasons->get($program->id))
                    @php($alreadySelected = in_array($program->id, $selected, true))
                    <flux:select.option :value="$program->id" :disabled="$unavailable !== null || $alreadySelected" wire:key="program-{{ $program->id }}">{{ $program->major->name }} · {{ $program->admissionMethod->name }}{{ $alreadySelected ? ' — '.__('Đã chọn') : ($unavailable ? ' — '.$unavailable : '') }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text>{{ __('Chỉ có thể chọn chương trình, ngành và phương thức đang hoạt động với chỉ tiêu lớn hơn 0. Chỉ tiêu cấu hình không phải số chỗ còn lại.') }}</flux:text>
            @if ($programs->isEmpty())<flux:callout>{{ __('Chưa có chương trình nào được cấu hình cho đợt tuyển sinh này.') }}</flux:callout>@endif
            <div><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="addWish">{{ __('Thêm nguyện vọng cuối danh sách') }}</flux:button></div>
        </form>
    @endif
    <details class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <summary class="cursor-pointer font-medium">{{ __('Thông tin chương trình trong đợt tuyển sinh') }}</summary>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            @forelse ($programs as $program)
                <div wire:key="catalog-{{ $program->id }}" class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="font-medium">{{ $program->major->name }} · {{ $program->admissionMethod->name }}</div>
                    <flux:text>{{ __('Chỉ tiêu') }}: {{ $program->quota }} · {{ __('Điểm tối thiểu') }}: {{ $program->minimum_score ?? __('Chưa xác định') }} · {{ __('Điểm chuẩn trước đây') }}: {{ $program->previous_cutoff_score ?? __('Chưa xác định') }}</flux:text>
                    <flux:text>{{ __('Học phí') }}: {{ $program->tuition_fee ?? __('Chưa xác định') }}</flux:text>
                    @if ($reasons->get($program->id))<flux:badge color="amber">{{ $reasons->get($program->id) }}</flux:badge>@endif
                </div>
            @empty
                <flux:text>{{ __('Chưa có chương trình cho đợt tuyển sinh này.') }}</flux:text>
            @endforelse
        </div>
    </details>
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
</section>
