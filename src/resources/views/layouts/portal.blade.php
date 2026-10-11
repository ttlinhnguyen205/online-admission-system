<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body
        class="portal-shell admission-shell min-h-screen bg-admission-canvas text-zinc-800 antialiased dark:bg-zinc-950 dark:text-zinc-100"
        x-data="{
            menuOpen: false,
            desktopCollapsed: false,
            activeSection: window.location.hash,
            isHomePage: {{ request()->routeIs('home', 'dashboard') ? 'true' : 'false' }},
            searchesPageContent: {{ request()->routeIs('home', 'news', 'registration-guidance') ? 'true' : 'false' }},
            search: '',
            matches(value) {
                return value.toLocaleLowerCase().includes(this.search.trim().toLocaleLowerCase());
            },
            navigationMatches() {
                return Array.from(this.$refs.portalNavigation.querySelectorAll('a.portal-nav-link'))
                    .filter(link => this.matches(link.textContent))
                    .map(link => ({ href: link.href, label: link.textContent.trim() }));
            }
        }"
        x-on:keydown.escape.window="menuOpen = false"
        x-on:hashchange.window="activeSection = window.location.hash"
        x-on:resize.window.debounce.150ms="if (window.innerWidth >= 1024) { menuOpen = false }"
    >
        <header class="fixed inset-x-0 top-0 z-40 flex h-16 items-center gap-4 border-b border-zinc-200 bg-white px-4 text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 lg:ps-0">
            <a href="{{ route('home') }}" wire:navigate class="admission-brand -ms-4 flex h-16 shrink-0 items-center gap-3 px-4 text-white lg:ms-0 lg:w-60 lg:px-6" aria-label="{{ __('Trang chủ tuyển sinh') }}">
                <img src="{{ asset('Logo.png') }}" alt="UNICAMP — Since 1990" width="448" height="137" class="h-auto w-28 object-contain brightness-0 invert sm:w-36 lg:w-full" />
            </a>

            <button type="button" class="flex shrink-0 items-center gap-2 rounded p-1 text-sm font-semibold text-admission-blue hover:bg-admission-soft focus-visible:outline-2 focus-visible:outline-admission-blue" x-on:click="window.innerWidth >= 1024 ? desktopCollapsed = !desktopCollapsed : menuOpen = !menuOpen" x-bind:aria-expanded="window.innerWidth >= 1024 ? !desktopCollapsed : menuOpen" aria-controls="portal-navigation">
                <flux:icon.bars-3 class="size-6" />
                <span class="hidden sm:inline">{{ __('Menu') }}</span>
                <span class="sr-only sm:hidden">{{ __('Mở menu') }}</span>
            </button>

            <div class="relative hidden min-w-0 w-full max-w-[394px] sm:block">
                <input type="search" x-model.debounce.150ms="search" aria-label="{{ __('Tìm kiếm thông tin trên trang') }}" placeholder="{{ __('Tìm kiếm thông tin') }}" class="h-10 w-full rounded-xl border border-transparent bg-zinc-100 ps-10 pe-4 text-sm text-zinc-800 placeholder:text-zinc-400 focus:outline-none focus:ring-2 focus:ring-admission-blue dark:bg-zinc-800 dark:text-zinc-100" />
                <flux:icon.magnifying-glass class="pointer-events-none absolute top-2.5 left-3 size-5 text-zinc-400" />
            </div>

            <div class="ms-auto flex shrink-0 items-center gap-3 sm:gap-6">
                <a href="{{ auth()->user()?->isCandidate() ? route('candidate.notifications.index') : route('news') }}" class="rounded p-2 text-admission-blue transition hover:bg-admission-soft" aria-label="{{ __('Thông báo') }}">
                    <flux:icon.bell class="size-6" />
                </a>

                @auth
                    <flux:button
                        x-data
                        x-on:click="$flux.dark = ! $flux.dark"
                        icon="moon"
                        variant="subtle"
                        class="text-admission-blue! dark:text-rose-200!"
                        aria-label="Chuyển chế độ sáng hoặc tối"
                    />

                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="flex max-w-52 items-center gap-2 rounded px-1 py-2 text-sm font-semibold hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-admission-blue dark:hover:bg-zinc-800">
                            <flux:icon.user-circle class="size-8 shrink-0 text-admission-blue" />
                            <span class="hidden truncate md:block">{{ auth()->user()->name }}</span>
                            <span class="sr-only md:hidden">{{ __('Tài khoản') }}</span>
                        </button>
                        <flux:menu>
                            <flux:menu.item :href="route('profile.edit')" icon="cog-6-tooth" wire:navigate>{{ __('Cài đặt') }}</flux:menu.item>
                            <flux:menu.separator />
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" data-test="logout-button">{{ __('Đăng xuất') }}</flux:menu.item>
                            </form>
                        </flux:menu>
                    </flux:dropdown>
                @else
                    <a href="{{ route('login') }}" wire:navigate class="flex items-center gap-2 rounded px-1 py-2 text-sm font-semibold hover:bg-admission-soft">
                        <flux:icon.user-circle class="size-8 text-admission-blue" />
                        <span class="hidden sm:inline">{{ __('Đăng nhập') }}</span>
                        <span class="sr-only sm:hidden">{{ __('Đăng nhập') }}</span>
                    </a>
                @endauth
            </div>
        </header>

        <button type="button" x-show="menuOpen" x-cloak x-on:click="menuOpen = false" class="fixed inset-0 top-16 z-20 bg-slate-950/50 lg:hidden" aria-label="{{ __('Đóng menu') }}"></button>

        <aside id="portal-navigation" class="fixed inset-y-0 top-16 left-0 z-30 w-60 overflow-y-auto border-e border-zinc-200 bg-[#f8f9fc] px-3 py-4 transition-transform dark:border-zinc-700 dark:bg-zinc-900" x-bind:class="[menuOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0', desktopCollapsed ? 'lg:hidden' : '']" x-trap.inert.noscroll="menuOpen">
            <nav x-ref="portalNavigation" aria-label="{{ __('Điều hướng chính') }}" class="flex flex-col gap-1">
                <a href="{{ auth()->check() ? route('dashboard') : route('home') }}" wire:navigate class="portal-nav-link" x-bind:aria-current="isHomePage && activeSection === '' ? 'page' : null">
                    <flux:icon.home class="size-5" />
                    <span>{{ __('Trang chủ') }}</span>
                </a>
                <a href="{{ route('news') }}" wire:navigate x-on:click="menuOpen = false" @if (request()->routeIs('news')) aria-current="page" @endif class="portal-nav-link">
                    <flux:icon.newspaper class="size-5" />
                    <span>{{ __('Tin tức') }}</span>
                </a>

                @if (auth()->user()?->isActive() && auth()->user()?->isCandidate())
                    <p class="mt-5 mb-2 px-3 text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ __('Tuyển sinh thí sinh') }}</p>
                    @foreach ([
                        ['candidate.counseling.index', 'academic-cap', 'Tư vấn tuyển sinh'],
                        ['candidate.profile.edit', 'user-circle', 'Hồ sơ cá nhân'],
                        ['candidate.admission-information.index', 'book-open', 'Thông tin tuyển sinh'],
                        ['candidate.scores.index', 'document-text', 'Điểm & minh chứng'],
                        ['candidate.applications.index', 'clipboard-document-list', 'Đăng ký nguyện vọng'],
                        ['candidate.results.index', 'academic-cap', 'Kết quả xét tuyển'],
                        ['candidate.notifications.index', 'bell', 'Thông báo'],
                    ] as [$destination, $icon, $label])
                        <a href="{{ route($destination) }}" wire:navigate class="portal-nav-link" @if (request()->routeIs(Illuminate\Support\Str::beforeLast($destination, '.').'.*')) aria-current="page" @endif>
                            <flux:icon :name="$icon" class="size-5" />
                            <span class="flex-1">{{ __($label) }}</span>
                            <flux:icon.chevron-right class="size-3.5" />
                        </a>
                    @endforeach
                @endif

                @can('viewAny', App\Models\Application::class)
                    <p class="mt-5 mb-2 px-3 text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ __('Quản lý hồ sơ') }}</p>
                    <a href="{{ route('admin.applications.index') }}" wire:navigate class="portal-nav-link" @if (request()->routeIs('admin.applications.*')) aria-current="page" @endif>
                        <flux:icon.clipboard-document-list class="size-5" />
                        <span class="flex-1">{{ __('Duyệt hồ sơ') }}</span>
                        <flux:icon.chevron-right class="size-3.5" />
                    </a>
                    <a href="{{ route('admin.review-history.index') }}" wire:navigate class="portal-nav-link" @if (request()->routeIs('admin.review-history.*')) aria-current="page" @endif>
                        <flux:icon.document-text class="size-5" />
                        <span>{{ __('Lịch sử xử lý') }}</span>
                    </a>
                @endcan

                @can('viewAny', App\Models\AdmissionRound::class)
                    <p class="mt-5 mb-2 px-3 text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ __('Quản lý tuyển sinh') }}</p>
                    <a href="{{ route('admin.home') }}" wire:navigate class="portal-nav-link" @if (request()->routeIs('admin.home')) aria-current="page" @endif>
                        <flux:icon.cog-6-tooth class="size-5" />
                        <span class="flex-1">{{ __('Cấu hình tuyển sinh') }}</span>
                        <flux:icon.chevron-right class="size-3.5" />
                    </a>
                    @foreach ([
                        ['admission-rounds', 'calendar-days', 'Đợt tuyển sinh', App\Models\AdmissionRound::class],
                        ['majors', 'academic-cap', 'Ngành đào tạo', App\Models\Major::class],
                        ['admission-methods', 'book-open', 'Phương thức xét tuyển', App\Models\AdmissionMethod::class],
                        ['admission-programs', 'document-text', 'Chương trình tuyển sinh', App\Models\AdmissionProgram::class],
                        ['candidate-major-offerings', 'clipboard-document-list', 'Ngành nhận đăng ký', App\Models\CandidateMajorOffering::class],
                    ] as [$path, $icon, $label, $model])
                        @can('viewAny', $model)
                            <a href="{{ route('admin.'.$path.'.index') }}" wire:navigate class="portal-nav-link" @if (request()->routeIs('admin.'.$path.'.*')) aria-current="page" @endif>
                                <flux:icon :name="$icon" class="size-5" />
                                <span>{{ __($label) }}</span>
                            </a>
                        @endcan
                    @endforeach
                @endcan

                @can('process', App\Models\AdmissionRound::class)
                    <a href="{{ route('admin.admission-engine') }}" wire:navigate class="portal-nav-link" @if (request()->routeIs('admin.admission-engine')) aria-current="page" @endif>
                        <flux:icon.academic-cap class="size-5" />
                        <span>{{ __('Công cụ xét tuyển') }}</span>
                    </a>
                @endcan
                @can('publishResults', App\Models\AdmissionRound::class)
                    <a href="{{ route('admin.results.index') }}" wire:navigate class="portal-nav-link" @if (request()->routeIs('admin.results.*')) aria-current="page" @endif>
                        <flux:icon.document-text class="size-5" />
                        <span>{{ __('Công bố kết quả') }}</span>
                    </a>
                @endcan

                @guest
                    <a href="{{ route('register') }}" wire:navigate class="portal-nav-link">
                        <flux:icon.clipboard-document-list class="size-5" />
                        <span class="flex-1">{{ __('Đăng ký trực tuyến') }}</span>
                        <flux:icon.chevron-right class="size-3.5" />
                    </a>
                    <a href="{{ route('login') }}" wire:navigate class="portal-nav-link">
                        <flux:icon.document-text class="size-5" />
                        <span>{{ __('Tra cứu hồ sơ') }}</span>
                    </a>
                @endguest

                <a href="{{ route('registration-guidance') }}" wire:navigate x-on:click="menuOpen = false" @if (request()->routeIs('registration-guidance')) aria-current="page" @endif class="portal-nav-link">
                    <flux:icon.book-open class="size-5" />
                    <span>{{ __('Hướng dẫn đăng ký') }}</span>
                </a>
            </nav>
        </aside>

        <main x-ref="portalContent" class="min-h-screen min-w-0 pt-16 transition-[margin]" x-bind:class="desktopCollapsed ? 'lg:ms-0' : 'lg:ms-60'">
            <div class="mx-auto min-h-[calc(100svh-4rem)] max-w-[1440px] bg-admission-canvas p-4 sm:p-5 lg:p-6 dark:bg-zinc-950">
                <div class="mb-4 sm:hidden">
                    <input type="search" x-model.debounce.150ms="search" aria-label="{{ __('Tìm kiếm thông tin trên trang') }}" placeholder="{{ __('Tìm kiếm thông tin') }}" class="h-10 w-full rounded-lg border border-zinc-200 bg-white px-3 text-sm text-zinc-800 focus:outline-none focus:ring-2 focus:ring-admission-blue dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                </div>

                <section x-show="!searchesPageContent && search.trim()" x-cloak class="portal-news-panel mb-5 p-4" aria-label="{{ __('Kết quả tìm kiếm thông tin') }}">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold">{{ __('Các mục phù hợp') }}</h2>
                        <button type="button" x-on:click="search = ''" class="rounded px-2 py-1 text-sm text-admission-blue hover:bg-admission-soft focus-visible:outline-2 focus-visible:outline-admission-blue dark:text-rose-200">{{ __('Đóng') }}</button>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <template x-for="result in navigationMatches()" x-bind:key="result.href">
                            <a x-bind:href="result.href" x-text="result.label" class="rounded-lg border border-zinc-200 px-3 py-2 text-sm transition hover:border-admission-blue hover:bg-admission-soft hover:text-admission-blue focus-visible:outline-2 focus-visible:outline-admission-blue dark:border-zinc-700 dark:hover:bg-rose-400/10 dark:hover:text-rose-200"></a>
                        </template>
                    </div>
                    <p x-show="navigationMatches().length === 0" role="status" class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Không tìm thấy mục phù hợp. Hãy thử từ khóa khác.') }}</p>
                </section>

                {{ $slot }}

                <p x-show="searchesPageContent && search.trim() && !Array.from($refs.portalContent.querySelectorAll('[data-portal-search]')).some(item => matches(item.dataset.portalSearch))" x-cloak role="status" class="portal-news-panel mt-5 p-6 text-center text-sm text-slate-500">{{ __('Không tìm thấy mục phù hợp. Hãy thử từ khóa khác.') }}</p>
            </div>
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @livewireScripts
        @fluxScripts
    </body>
</html>
