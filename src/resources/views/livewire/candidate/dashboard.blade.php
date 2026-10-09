@php
    $primaryMetrics = ['Hồ sơ của tôi' => 'document-text', 'Nguyện vọng' => 'academic-cap', 'Kết quả đã công bố' => 'check-circle', 'Thông báo chưa đọc' => 'bell'];
    $statusColors = ['draft' => 'zinc', 'submitted' => 'sky', 'under_review' => 'amber', 'needs_revision' => 'orange', 'verified' => 'emerald', 'rejected' => 'rose', 'processing' => 'violet', 'completed' => 'teal'];
@endphp
<section class="mx-auto flex w-full max-w-7xl flex-col gap-6 text-zinc-900 dark:text-zinc-100 sm:gap-8">
    <div>
        <p class="mb-2 text-xs font-semibold tracking-widest text-indigo-600 uppercase dark:text-indigo-400">Đồng hành cùng thí sinh</p>
        <div class="flex flex-wrap items-center justify-between gap-4"><flux:heading size="xl" level="1">Tổng quan hồ sơ của tôi</flux:heading><flux:button x-data x-on:click="$flux.dark = ! $flux.dark" icon="moon" variant="subtle" aria-label="Chuyển chế độ sáng hoặc tối" /></div>
        <flux:text class="mt-2 break-words">Xin chào {{ $candidate->name }}! Theo dõi hồ sơ, nguyện vọng và những việc cần thực hiện.</flux:text>
    </div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Thống kê cá nhân">
        @foreach ($primaryMetrics as $label => $icon)
            <article wire:key="candidate-metric-{{ $icon }}" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"><div class="flex items-center justify-between gap-3"><h2 class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $label }}</h2><flux:icon :name="$icon" variant="mini" class="size-5 text-indigo-500" aria-hidden="true" /></div><p class="mt-3 text-3xl font-semibold tracking-tight tabular-nums">{{ number_format($metrics[$label]) }}</p></article>
        @endforeach
    </div>
    @if ($metrics['Hồ sơ cần bổ sung'] > 0)
        <section aria-labelledby="revision-heading" class="rounded-2xl border border-orange-200 bg-orange-50 p-5 dark:border-orange-800 dark:bg-orange-950/30 sm:p-6">
            <div class="flex items-start gap-3"><flux:icon.exclamation-circle class="size-6 shrink-0 text-orange-600 dark:text-orange-400" aria-hidden="true" /><div><flux:heading id="revision-heading" level="2">Hồ sơ cần bổ sung ({{ number_format($metrics['Hồ sơ cần bổ sung']) }})</flux:heading><p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Xem yêu cầu của từng hồ sơ. Việc chỉnh sửa và nộp lại tuân theo thời hạn và trạng thái hiện tại.</p></div></div>
            <div class="mt-4 space-y-3">
                @foreach ($revisionApplications as $application)
                    <article wire:key="revision-application-{{ $application->id }}" class="rounded-xl border border-orange-200 bg-white p-4 dark:border-orange-900 dark:bg-zinc-900">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div class="min-w-0"><h3 class="font-semibold break-words">{{ $application->admissionRound->name }}</h3><p class="mt-1 text-xs break-all text-zinc-500 dark:text-zinc-400">{{ $application->application_code }}</p></div><flux:button size="sm" :href="route('candidate.applications.show', $application->id)" icon:trailing="arrow-right" wire:navigate>Xem yêu cầu bổ sung</flux:button></div>
                        <p class="mt-3 text-sm whitespace-pre-wrap break-words">{{ $application->revision_reason ?: 'Mở hồ sơ để xem thông tin cần bổ sung.' }}</p>
                    </article>
                @endforeach
            </div>
            <div class="mt-4">{{ $revisionApplications->links() }}</div>
        </section>
    @endif
    <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
        <div><flux:heading level="2">Hồ sơ cá nhân</flux:heading><flux:text class="mt-2">{{ $profile === null ? 'Bạn chưa tạo hồ sơ cá nhân.' : App\Support\CandidateStatusLabels::profile($profile->profile_status) }}</flux:text>@if ($profile)<p class="mt-2 text-sm">Mã thí sinh: {{ $profile->candidate_code }}</p>@endif</div>
        <p class="w-full text-sm text-zinc-600 dark:text-zinc-300">{{ $profileComplete ? 'Đã có đủ thông tin bắt buộc theo yêu cầu hồ sơ.' : 'Hồ sơ chưa đáp ứng đủ thông tin bắt buộc. Hãy kiểm tra và hoàn thiện trước khi nộp.' }}</p>
        <flux:button :href="route('candidate.profile.edit')" icon:trailing="arrow-right" wire:navigate>Hoàn thiện hồ sơ cá nhân</flux:button>
    </div>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3"><flux:heading level="2">Hồ sơ và nguyện vọng</flux:heading><flux:button :href="route('candidate.applications.index')" wire:navigate>Đăng ký nguyện vọng</flux:button></div>
        @forelse ($applications as $application)
            <article wire:key="own-application-{{ $application->id }}" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
                <div class="flex flex-col items-start justify-between gap-3 sm:flex-row"><div class="min-w-0"><h3 class="font-semibold break-words">{{ $application->admissionRound->name }}</h3><p class="mt-1 text-xs break-all text-zinc-500 dark:text-zinc-400">Mã hồ sơ: {{ $application->application_code }}</p></div><flux:badge :color="$statusColors[$application->status->value] ?? 'zinc'">{{ App\Support\CandidateStatusLabels::application($application->status) }}</flux:badge></div>
                @if ($application->revision_reason)<p class="mt-3 whitespace-pre-wrap break-words text-sm">Cần bổ sung: {{ $application->revision_reason }}</p>@endif
                <ol class="mt-4 space-y-3 border-t border-zinc-100 pt-4 text-sm dark:border-zinc-800">
                    @forelse ($application->wishes as $wish)<li wire:key="own-wish-{{ $wish->id }}" class="flex items-start gap-3"><span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-indigo-50 font-semibold text-indigo-700 tabular-nums dark:bg-indigo-500/10 dark:text-indigo-300" aria-label="Nguyện vọng {{ $wish->priority }}">{{ $wish->priority }}</span><div class="min-w-0"><p class="font-medium break-words">{{ $wish->admissionProgram->major->name }}</p><p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Mã ngành: {{ $wish->admissionProgram->major->code }}</p></div></li>@empty<li class="text-zinc-500 dark:text-zinc-400">Chưa có nguyện vọng.</li>@endforelse
                </ol>
                @foreach ($application->wishes as $wish)
                    @if (isset($programMethods[$wish->admission_program_id]))
                        <p wire:key="own-program-{{ $wish->id }}" class="mt-2 text-xs break-words text-zinc-500 dark:text-zinc-400">Chương trình nguyện vọng {{ $wish->priority }}: {{ $wish->admissionProgram->major->name }} · {{ $programMethods[$wish->admission_program_id] }}</p>
                    @endif
                @endforeach
                <flux:button class="mt-4" size="sm" variant="subtle" :href="route('candidate.applications.show', $application->id)" icon:trailing="arrow-right" wire:navigate>Xem chi tiết hồ sơ</flux:button>
            </article>
        @empty<flux:callout>Bạn chưa có hồ sơ đăng ký. Hãy hoàn thiện hồ sơ cá nhân và chọn đợt đang nhận đăng ký.</flux:callout>@endforelse
        {{ $applications->links() }}
    </div>
    <div class="grid items-start gap-5 lg:grid-cols-2">
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
            <flux:heading level="2">Kết quả đã công bố</flux:heading>
            <ul class="mt-3 space-y-3">
                @forelse ($results as $result)<li wire:key="own-result-{{ $result->id }}" class="text-sm">{{ $result->admissionWish->admissionProgram->major->name }} · {{ $result->admissionWish->application->admissionRound->name }}<p class="mt-1 font-medium">{{ App\Support\CandidateStatusLabels::result($result->decision) }}</p></li>@empty<li class="text-sm text-zinc-500">Chưa có kết quả được phép công bố.</li>@endforelse
            </ul>
            <flux:button class="mt-4" :href="route('candidate.results.index')" wire:navigate>Xem kết quả và xác nhận nhập học</flux:button>
        </div>
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
            <flux:heading level="2">Thông báo gần đây</flux:heading>
            <ul class="mt-3 space-y-3">
                @forelse ($notifications as $notification)
                    @php($detail = $notificationDetails[$notification->id])
                    <li wire:key="own-notification-{{ $notification->id }}" @class(['rounded-xl border p-4 text-sm', 'border-indigo-200 bg-indigo-50/60 dark:border-indigo-800 dark:bg-indigo-950/30' => $notification->read_at === null, 'border-zinc-100 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-800/60' => $notification->read_at !== null])><div class="flex flex-wrap items-center gap-2"><h3 class="font-semibold break-words">{{ $detail['title'] }}</h3>@if ($notification->read_at === null)<flux:badge color="indigo" size="sm">Chưa đọc</flux:badge>@endif</div>@if ($notification->created_at)<time datetime="{{ $notification->created_at->toIso8601String() }}" class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400">{{ $notification->created_at->copy()->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</time>@endif @if ($detail['message'])<p class="mt-2 whitespace-pre-wrap break-words">{{ $detail['message'] }}</p>@endif @if ($detail['url'])<a href="{{ $detail['url'] }}" wire:navigate class="mt-3 inline-block font-medium text-indigo-700 underline underline-offset-4 dark:text-indigo-300">{{ $detail['action'] }}</a>@endif</li>
                @empty<li class="text-sm text-zinc-500">Bạn chưa có thông báo.</li>@endforelse
            </ul>
            <flux:button class="mt-4" :href="route('candidate.notifications.index')" wire:navigate>Xem tất cả thông báo</flux:button>
        </div>
    </div>
    <p wire:loading.delay role="status" class="text-sm">Đang tải tổng quan...</p>
</section>
