<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @include('partials.head')
</head>

<body class="admission-shell min-h-screen bg-admission-canvas text-zinc-800 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <flux:sidebar sticky collapsible="mobile" class="admission-navigation w-60! gap-5! border-e border-zinc-200 bg-[#f8f9fc] px-3! dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.header class="admission-brand -mx-3 -mt-4 min-h-20 px-5 py-4 text-white">
            <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        @if (auth()->user()->isAdmin())
        <flux:sidebar.nav>
            <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>{{ __('Trang chủ') }}</flux:sidebar.item>
        </flux:sidebar.nav>
        @can('viewAny', App\Models\Application::class)
        <flux:sidebar.nav>
            <flux:sidebar.group :heading="__('QUẢN LÝ HỒ SƠ')">
                <flux:sidebar.item :href="route('admin.applications.index')" :current="request()->routeIs('admin.applications.*')" wire:navigate>{{ __('Hồ sơ xét tuyển') }}</flux:sidebar.item>
                <flux:sidebar.item :href="route('admin.review-history.index')" :current="request()->routeIs('admin.review-history.*')" wire:navigate>{{ __('Lịch sử xử lý') }}</flux:sidebar.item>
            </flux:sidebar.group>
            @canany(['process', 'publishResults'], App\Models\AdmissionRound::class)
            <flux:sidebar.group :heading="__('QUẢN LÝ XÉT TUYỂN')">
                @can('process', App\Models\AdmissionRound::class)
                <flux:sidebar.item :href="route('admin.admission-engine')" :current="request()->routeIs('admin.admission-engine')" wire:navigate>{{ __('Công cụ xét tuyển') }}</flux:sidebar.item>
                @endcan
                @can('publishResults', App\Models\AdmissionRound::class)
                <flux:sidebar.item :href="route('admin.results.index')" :current="request()->routeIs('admin.results.*')" wire:navigate>{{ __('Kết quả xét tuyển') }}</flux:sidebar.item>
                @endcan
            </flux:sidebar.group>
            @endcanany
        </flux:sidebar.nav>
        @endcan

        @can('viewAny', App\Models\AdmissionRound::class)
        <flux:sidebar.nav>
            <flux:sidebar.group :heading="__('CẤU HÌNH TUYỂN SINH')">
                @foreach ([['admission-rounds', 'Đợt tuyển sinh', App\Models\AdmissionRound::class], ['majors', 'Ngành đào tạo', App\Models\Major::class], ['admission-methods', 'Phương thức xét tuyển', App\Models\AdmissionMethod::class], ['admission-programs', 'Chương trình tuyển sinh', App\Models\AdmissionProgram::class], ['candidate-major-offerings', 'Ngành mở xét tuyển', App\Models\CandidateMajorOffering::class]] as [$path, $label, $model])
                @can('viewAny', $model)
                <flux:sidebar.item :href="route('admin.'.$path.'.index')" :current="request()->routeIs('admin.'.$path.'.*')" wire:navigate>{{ __($label) }}</flux:sidebar.item>
                @endcan
                @endforeach
            </flux:sidebar.group>
        </flux:sidebar.nav>
        @endcan
        @else
        <flux:sidebar.nav>
            <flux:sidebar.group :heading="__('Hệ thống')" class="grid">
                <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Bảng điều khiển') }}
                </flux:sidebar.item>
            </flux:sidebar.group>
        </flux:sidebar.nav>

        @can('viewAny', App\Models\Application::class)
        @can('publishResults', App\Models\AdmissionRound::class)
        <flux:sidebar.nav>
            <flux:sidebar.item :href="route('admin.results.index')" :current="request()->routeIs('admin.results.*')" wire:navigate>{{ __('Admission results') }}</flux:sidebar.item>
        </flux:sidebar.nav>
        @endcan
        @can('process', App\Models\AdmissionRound::class)
        <flux:sidebar.nav>
            <flux:sidebar.item :href="route('admin.admission-engine')" :current="request()->routeIs('admin.admission-engine')" wire:navigate>{{ __('Công cụ xét tuyển') }}</flux:sidebar.item>
        </flux:sidebar.nav>
        @endcan
        <flux:sidebar.nav>
            <flux:sidebar.group :heading="__('Duyệt hồ sơ')">
                <flux:sidebar.item :href="route('admin.applications.index')" :current="request()->routeIs('admin.applications.*')" wire:navigate>{{ __('Duyệt hồ sơ xét tuyển') }}</flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('admin.review-history.index')"
                    :current="request()->routeIs('admin.review-history.*')"
                    wire:navigate>
                    {{ __('Lịch sử xử lý') }}
                </flux:sidebar.item>
            </flux:sidebar.group>
        </flux:sidebar.nav>
        @endcan

        @can('viewAny', App\Models\AdmissionRound::class)
        <flux:sidebar.nav>
            <flux:sidebar.group :heading="__('Cấu hình tuyển sinh')">
                <flux:sidebar.item :href="route('admin.home')" :current="request()->routeIs('admin.home')" wire:navigate>{{ __('Trang cấu hình') }}</flux:sidebar.item>
                @foreach ([['admission-rounds', 'Đợt tuyển sinh', App\Models\AdmissionRound::class], ['majors', 'Ngành đào tạo', App\Models\Major::class], ['admission-methods', 'Phương thức xét tuyển', App\Models\AdmissionMethod::class], ['admission-programs', 'Chương trình tuyển sinh', App\Models\AdmissionProgram::class], ['candidate-major-offerings', 'Ngành nhận đăng ký', App\Models\CandidateMajorOffering::class]] as [$path, $label, $model])
                @can('viewAny', $model)
                <flux:sidebar.item :href="route('admin.'.$path.'.index')" :current="request()->routeIs('admin.'.$path.'.*')" wire:navigate>{{ __($label) }}</flux:sidebar.item>
                @endcan
                @endforeach
            </flux:sidebar.group>
        </flux:sidebar.nav>
        @endcan

        @endif

        @if (auth()->user()->isActive() && auth()->user()->isCandidate())
        <flux:sidebar.nav>
            <flux:sidebar.group :heading="__('Tuyển sinh thí sinh')">
                <flux:sidebar.item :href="route('candidate.counseling.index')" :current="request()->routeIs('candidate.counseling.*')" wire:navigate>{{ __('Tư vấn tuyển sinh') }}</flux:sidebar.item>
                <flux:sidebar.item :href="route('candidate.profile.edit')" :current="request()->routeIs('candidate.profile.*')" wire:navigate>{{ __('Hồ sơ cá nhân') }}</flux:sidebar.item>
                <flux:sidebar.item :href="route('candidate.admission-information.index')" :current="request()->routeIs('candidate.admission-information.*')" wire:navigate>{{ __('Thông tin tuyển sinh') }}</flux:sidebar.item>
                <flux:sidebar.item :href="route('candidate.scores.index')" :current="request()->routeIs('candidate.scores.*')" wire:navigate>{{ __('Điểm & minh chứng') }}</flux:sidebar.item>
                <flux:sidebar.item :href="route('candidate.applications.index')" :current="request()->routeIs('candidate.applications.*')" wire:navigate>{{ __('Đăng ký nguyện vọng') }}</flux:sidebar.item>
                <flux:sidebar.item :href="route('candidate.results.index')" :current="request()->routeIs('candidate.results.*')" wire:navigate>{{ __('Kết quả xét tuyển') }}</flux:sidebar.item>
                <flux:sidebar.item :href="route('candidate.notifications.index')" :current="request()->routeIs('candidate.notifications.*')" wire:navigate>{{ __('Thông báo') }}</flux:sidebar.item>
            </flux:sidebar.group>
        </flux:sidebar.nav>
        @endif

        <flux:spacer />

        <flux:sidebar.nav>
            <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                {{ __('Kho mã nguồn') }}
            </flux:sidebar.item>

            <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                {{ __('Tài liệu hướng dẫn') }}
            </flux:sidebar.item>
        </flux:sidebar.nav>

        <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
    </flux:sidebar>

    <!-- Mobile User Menu -->
    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:dropdown position="top" align="end">
            <flux:profile
                :initials="auth()->user()->initials()"
                icon-trailing="chevron-down" />

            <flux:menu>
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                            <flux:avatar
                                :name="auth()->user()->name"
                                :initials="auth()->user()->initials()" />

                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                            </div>
                        </div>
                    </div>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                        {{ __('Cài đặt') }}
                    </flux:menu.item>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item
                        as="button"
                        type="submit"
                        icon="arrow-right-start-on-rectangle"
                        class="w-full cursor-pointer"
                        data-test="logout-button">
                        {{ __('Đăng xuất') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    {{ $slot }}

    @persist('toast')
    <flux:toast.group>
        <flux:toast />
    </flux:toast.group>
    @endpersist

    @fluxScripts
</body>

</html>
