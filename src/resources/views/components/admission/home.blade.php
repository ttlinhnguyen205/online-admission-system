@php
    $portalNow = now(config('app.timezone'));
    $portalGreeting = match (true) {
        $portalNow->hour < 12 => 'Chào buổi sáng,',
        $portalNow->hour < 18 => 'Chào buổi chiều,',
        default => 'Chào buổi tối,',
    };
    $portalRole = match (auth()->user()?->role?->value) {
        'candidate' => 'Thí sinh',
        'staff' => 'Nhân viên tuyển sinh',
        'admin' => 'Quản trị viên',
        default => 'Cổng tuyển sinh trực tuyến',
    };
    $portalWeekdays = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
    $portalCards = auth()->user()?->isCandidate() ? [
        ['candidate.profile.edit', 'user-circle', 'HỒ SƠ', 'Hồ sơ cá nhân', 'bg-blue-600', null, null],
        ['candidate.admission-information.index', 'book-open', 'TUYỂN SINH', 'Thông tin xét tuyển', 'bg-amber-500', null, null],
        ['candidate.applications.index', 'clipboard-document-list', 'ĐĂNG KÝ', 'Đăng ký nguyện vọng', 'bg-emerald-500', null, null],
        ['candidate.results.index', 'academic-cap', 'KẾT QUẢ', 'Kết quả xét tuyển', 'bg-red-500', null, null],
    ] : (auth()->check() ? [
        ['admin.applications.index', 'clipboard-document-list', 'HỒ SƠ', 'Duyệt hồ sơ', 'bg-blue-600', 'viewAny', App\Models\Application::class],
        ['admin.home', 'cog-6-tooth', 'CẤU HÌNH', 'Cấu hình tuyển sinh', 'bg-amber-500', 'viewAny', App\Models\AdmissionRound::class],
        ['admin.admission-engine', 'academic-cap', 'XÉT TUYỂN', 'Công cụ xét tuyển', 'bg-emerald-500', 'process', App\Models\AdmissionRound::class],
        ['admin.results.index', 'document-text', 'KẾT QUẢ', 'Công bố kết quả', 'bg-red-500', 'publishResults', App\Models\AdmissionRound::class],
    ] : [
        ['register', 'clipboard-document-list', 'ĐĂNG KÝ', 'Tạo tài khoản', 'bg-blue-600', null, null],
        ['login', 'user-circle', 'HỒ SƠ', 'Tra cứu hồ sơ', 'bg-amber-500', null, null],
        ['login', 'academic-cap', 'KẾT QUẢ', 'Kết quả xét tuyển', 'bg-emerald-500', null, null],
        ['login', 'bell', 'THÔNG TIN', 'Thông báo tuyển sinh', 'bg-red-500', null, null],
    ]);
@endphp

<section {{ $attributes->class('flex flex-col gap-5') }}>
    <div class="flex items-center justify-between gap-6 py-2">
        <div class="flex min-w-0 flex-col gap-2">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __($portalGreeting) }}</p>
            <h1 class="text-2xl font-bold leading-tight text-zinc-900 dark:text-zinc-50">{{ __($portalRole) }}</h1>
            <div class="mt-1 flex items-center gap-2 text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">
                <flux:icon.calendar-days class="size-4 shrink-0" />
                <time datetime="{{ $portalNow->toDateString() }}">{{ __($portalWeekdays[$portalNow->dayOfWeek]) }}, {{ __('ngày') }} {{ $portalNow->format('d/m/Y') }}</time>
            </div>
        </div>
        <span class="hidden size-14 shrink-0 items-center justify-center rounded-xl bg-admission-soft sm:flex dark:bg-rose-400/10">
            <flux:icon.academic-cap class="size-8 text-admission-blue dark:text-rose-200" />
        </span>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($portalCards as [$destination, $icon, $category, $label, $color, $ability, $model])
            @if ($ability === null || Illuminate\Support\Facades\Gate::allows($ability, $model))
                <a href="{{ route($destination) }}" wire:navigate data-portal-search="{{ __($category).' '.__($label) }}" x-show="matches($el.dataset.portalSearch)" class="portal-news-panel group flex min-h-24 items-center gap-4 px-5 py-4 transition hover:border-rose-200 hover:shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-admission-blue">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-admission-soft text-admission-blue dark:bg-rose-400/10 dark:text-rose-200">
                        <flux:icon :name="$icon" class="size-6" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-xs text-slate-500">{{ __($category) }}</span>
                        <span class="mt-1 block text-sm font-semibold leading-5 text-zinc-800 dark:text-zinc-100">{{ __($label) }}</span>
                    </span>
                </a>
            @endif
        @endforeach
    </div>

    @foreach ([
        ['portal-news', 'TIN NHÀ TRƯỜNG'],
        ['portal-admissions', 'TIN TUYỂN SINH'],
    ] as [$sectionId, $sectionTitle])
        <section id="{{ $sectionId }}" data-portal-search="{{ __($sectionTitle) }}" x-show="matches($el.dataset.portalSearch)" class="portal-news-panel scroll-mt-20 overflow-hidden" aria-labelledby="{{ $sectionId }}-title">
            <div class="flex items-center gap-3 border-b border-zinc-100 px-5 py-4 dark:border-zinc-700">
                <span class="h-4 w-1 rounded-full bg-admission-blue" aria-hidden="true"></span>
                <h2 id="{{ $sectionId }}-title" class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __($sectionTitle) }}</h2>
            </div>
            <div class="flex min-h-24 items-center justify-center gap-3 px-5 py-8">
                <flux:icon.inbox class="size-6 text-slate-300" />
                <p class="text-sm italic text-slate-400">{{ __('Thông tin sẽ được cập nhật tại đây.') }}</p>
            </div>
        </section>
    @endforeach

    <section id="portal-guidance" data-portal-search="{{ __('Hướng dẫn đăng ký hồ sơ nguyện vọng hỗ trợ') }}" x-show="matches($el.dataset.portalSearch)" class="portal-news-panel scroll-mt-20 overflow-hidden" aria-labelledby="portal-guidance-title">
        <div class="flex items-center gap-3 border-b border-zinc-100 px-5 py-4 dark:border-zinc-700">
            <span class="h-4 w-1 rounded-full bg-admission-blue" aria-hidden="true"></span>
            <h2 id="portal-guidance-title" class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __('HƯỚNG DẪN ĐĂNG KÝ') }}</h2>
        </div>
        <ol class="grid gap-5 px-5 py-6 text-sm text-slate-600 md:grid-cols-3">
            @foreach (['Hoàn thiện hồ sơ cá nhân', 'Khai báo thông tin và minh chứng', 'Sắp xếp nguyện vọng và nộp hồ sơ'] as $step)
                <li class="flex items-center gap-3">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-admission-soft text-xs font-bold text-admission-blue dark:bg-rose-400/10 dark:text-rose-200">{{ $loop->iteration }}</span>
                    <span>{{ __($step) }}</span>
                </li>
            @endforeach
        </ol>
    </section>
</section>
