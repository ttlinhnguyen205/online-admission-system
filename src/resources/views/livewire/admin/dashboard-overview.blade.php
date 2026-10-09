@php
    $statusColors = ['Đã nộp' => 'sky', 'Đang xét duyệt' => 'amber', 'Cần bổ sung' => 'orange', 'Đã xác minh' => 'emerald', 'Không hợp lệ' => 'rose', 'Đang xét tuyển' => 'violet', 'Hoàn tất xét tuyển' => 'teal'];
@endphp
<section class="mx-auto flex w-full max-w-7xl flex-col gap-6 text-zinc-900 dark:text-zinc-100 sm:gap-8">
    <header class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="mb-2 flex items-center gap-2 text-xs font-semibold tracking-widest text-indigo-600 uppercase dark:text-indigo-400">
                <flux:icon.academic-cap variant="mini" class="size-4" aria-hidden="true" /> Quản trị tuyển sinh
            </p>
            <flux:heading size="xl" level="1" class="tracking-tight">Tổng quan tuyển sinh</flux:heading>
            <flux:text class="mt-2 max-w-xl">Theo dõi tiến độ hồ sơ và phân bố nguyện vọng trong cùng một bộ lọc.</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <flux:button x-data x-on:click="$flux.dark = ! $flux.dark" icon="moon" variant="subtle" aria-label="Chuyển chế độ sáng hoặc tối" />
            <flux:button :href="route('admin.applications.index', ['roundFilter' => $roundFilter, 'statusFilter' => $statusFilter ?: 'all', 'search' => $search])" icon:trailing="arrow-right" wire:navigate>Mở danh sách xét duyệt</flux:button>
        </div>
    </header>

    <section aria-label="Bộ lọc thống kê" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
        <div class="mb-5 flex items-center gap-2 text-sm font-semibold">
            <flux:icon.adjustments-horizontal variant="mini" class="size-5 text-zinc-500" aria-hidden="true" /> Bộ lọc thống kê
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <flux:select label="Năm tuyển sinh" wire:model.change.live="yearFilter">
                <option value="">Tất cả năm</option>
                @foreach ($years as $year)
                    <option wire:key="admin-year-{{ $year }}" value="{{ $year }}">{{ $year }}</option>
                @endforeach
            </flux:select>
            <flux:select label="Đợt tuyển sinh" wire:key="admin-dashboard-rounds-{{ $yearFilter }}" wire:model.change.live="roundFilter">
                <option value="">Tất cả đợt</option>
                @foreach ($rounds as $round)
                    <option wire:key="admin-round-{{ $round->id }}" value="{{ $round->id }}">{{ $round->name }} ({{ $round->code }})</option>
                @endforeach
            </flux:select>
            <flux:select label="Trạng thái hồ sơ" wire:model.change.live="statusFilter">
                <option value="">Tất cả hồ sơ đã nộp</option>
                @foreach ($statuses as $status)
                    <option wire:key="admin-status-{{ $status->value }}" value="{{ $status->value }}">{{ App\Support\CandidateStatusLabels::application($status) }}</option>
                @endforeach
            </flux:select>
            <div class="sm:col-span-2 xl:col-span-3">
                <flux:input label="Tìm kiếm" placeholder="Mã hồ sơ, mã thí sinh, họ tên hoặc email" icon="magnifying-glass" wire:model.live.debounce.400ms="search" maxlength="100" />
            </div>
        </div>
        <div class="mt-5 flex flex-col gap-4 border-t border-zinc-100 pt-4 dark:border-zinc-800 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <flux:button variant="subtle" size="sm" icon="arrow-path" wire:click="clearFilters" wire:loading.attr="disabled">Xóa bộ lọc</flux:button>
                <p wire:loading.delay role="status" class="text-xs text-zinc-500 dark:text-zinc-400">Đang cập nhật thống kê...</p>
            </div>
            @if ($summary !== null && $filters !== null)
                <div class="flex flex-wrap items-center gap-2" aria-label="Xuất báo cáo theo bộ lọc">
                    <span class="mr-1 text-xs text-zinc-500 dark:text-zinc-400">Báo cáo theo bộ lọc</span>
                    <flux:button size="sm" icon="arrow-down-tray" :href="route('admin.reports.download', ['format' => 'xlsx', ...$filters->parameters()])">Xuất Excel</flux:button>
                    <flux:button size="sm" icon="document-text" :href="route('admin.reports.download', ['format' => 'pdf', ...$filters->parameters()])">Xuất PDF</flux:button>
                </div>
            @endif
        </div>
        @if ($errors->any())
            <div role="alert" class="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif
        @if (session('errors'))<flux:callout class="mt-4">{{ session('errors')->first() }}</flux:callout>@endif
    </section>

    @if ($summary !== null)
        @php
            $metrics = $summary['metrics'];
            $primary = [
                ['label' => 'Hồ sơ đã nộp', 'count' => $metrics['Hồ sơ đã nộp'], 'icon' => 'document-text', 'hint' => 'Không bao gồm bản nháp', 'tone' => 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300'],
                ['label' => 'Thí sinh có hồ sơ', 'count' => $metrics['Thí sinh có hồ sơ'], 'icon' => 'users', 'hint' => 'Thí sinh duy nhất trong bộ lọc', 'tone' => 'bg-teal-50 text-teal-600 dark:bg-teal-500/15 dark:text-teal-300'],
                ['label' => 'Chờ xử lý', 'count' => $metrics['Chờ bắt đầu xét duyệt'] + $metrics['Đang xét duyệt'], 'icon' => 'clock', 'hint' => 'Đã nộp và đang xét duyệt', 'tone' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'],
                ['label' => 'Cần bổ sung', 'count' => $metrics['Cần bổ sung'], 'icon' => 'exclamation-circle', 'hint' => 'Hồ sơ cần thí sinh bổ sung', 'tone' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300'],
            ];
        @endphp
        <div aria-label="Chỉ số chính" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" wire:loading.class="opacity-60">
            @foreach ($primary as $metric)
                <article wire:key="admin-primary-{{ $metric['label'] }}" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $metric['label'] }}</h2>
                        <span class="rounded-xl p-2.5 {{ $metric['tone'] }}"><flux:icon :name="$metric['icon']" class="size-5" aria-hidden="true" /></span>
                    </div>
                    <p class="mt-4 text-4xl font-semibold tracking-tight tabular-nums">{{ number_format($metric['count']) }}</p>
                    <p class="mt-2 text-xs leading-5 text-zinc-500 dark:text-zinc-400">{{ $metric['hint'] }}</p>
                </article>
            @endforeach
        </div>

        <details wire:key="admin-statistics-details" class="group rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-2xl p-5 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-indigo-500 sm:p-6">
                <div><h2 class="font-semibold">Thống kê chi tiết</h2><p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Nguyện vọng, tiến độ xét duyệt và kết quả tuyển sinh</p></div>
                <flux:icon.chevron-down class="size-5 shrink-0 text-zinc-500 transition-transform group-open:rotate-180" aria-hidden="true" />
            </summary>
            <div class="border-t border-zinc-100 p-5 dark:border-zinc-800 sm:p-6">
                <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($metrics as $label => $count)
                        @if (! in_array($label, ['Hồ sơ đã nộp', 'Thí sinh có hồ sơ', 'Cần bổ sung'], true))
                            <div wire:key="admin-detail-{{ $label }}" class="flex items-start justify-between gap-4">
                                <dt class="text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $label }}</dt><dd class="text-lg font-semibold tabular-nums">{{ number_format($count) }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
                <p class="mt-6 border-t border-zinc-100 pt-4 text-xs leading-6 text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">Kết quả gồm cả nội bộ chưa công bố. Bản nháp chỉ được đếm riêng, không xuất danh sách. Đã xác minh hồ sơ không đồng nghĩa trúng tuyển. Hồ sơ, thí sinh, nguyện vọng và kết quả là các số lượng riêng biệt.</p>
            </div>
        </details>

        <section aria-label="Biểu đồ tuyển sinh" class="space-y-4">
            <div><flux:heading level="2">Phân bố tuyển sinh</flux:heading><flux:text class="mt-1 text-sm">Hồ sơ được đếm theo trạng thái; ngành, phương thức và chương trình được đếm theo nguyện vọng.</flux:text></div>
            <div class="grid items-start gap-5 lg:grid-cols-2">
                <x-admin-admission-chart title="Hồ sơ theo trạng thái" :rows="$summary['statuses']" :total="$metrics['Hồ sơ đã nộp']" unit="hồ sơ đã nộp" :colors="$statusColors" />
                @foreach ($summary['charts'] as $title => $rows)
                    <x-admin-admission-chart :title="$title" :rows="$rows" :total="$metrics['Nguyện vọng']" unit="nguyện vọng" wire:key="admin-figure-{{ $title }}" />
                @endforeach
            </div>
        </section>

        <section aria-label="Hàng đợi xét duyệt" class="overflow-hidden rounded-2xl border border-amber-200 bg-white shadow-sm dark:border-amber-500/30 dark:bg-zinc-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-100 bg-amber-50/60 px-5 py-4 dark:border-amber-500/20 dark:bg-amber-500/5 sm:px-6">
                <div><flux:heading level="2">Hồ sơ cần xử lý — nộp sớm nhất</flux:heading><p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Ưu tiên tối đa 5 hồ sơ đã nộp hoặc đang xét duyệt theo bộ lọc.</p></div>
                <flux:icon.clock class="size-5 text-amber-600 dark:text-amber-400" aria-hidden="true" />
            </div>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($pending as $application)
                    @php($label = App\Support\CandidateStatusLabels::application($application->status))
                    <li wire:key="admin-pending-{{ $application->id }}" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="min-w-0"><a href="{{ route('admin.applications.show', $application->id) }}" wire:navigate class="font-semibold text-indigo-700 underline-offset-4 hover:underline dark:text-indigo-300">{{ $application->application_code }}</a><p class="mt-1 break-words text-xs text-zinc-500 dark:text-zinc-400">{{ $application->admissionRound->name }}</p></div>
                        <div class="flex items-center gap-3"><flux:badge size="sm" :color="$statusColors[$label] ?? 'zinc'">{{ $label }}</flux:badge><a href="{{ route('admin.applications.show', $application->id) }}" wire:navigate class="rounded p-1 text-zinc-400 hover:text-indigo-600 focus-visible:outline-2 focus-visible:outline-indigo-500" aria-label="Xét duyệt hồ sơ {{ $application->application_code }}"><flux:icon.arrow-right class="size-4" aria-hidden="true" /></a></div>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-zinc-500 dark:text-zinc-400 sm:px-6">Không có hồ sơ chờ xử lý phù hợp.</li>
                @endforelse
            </ul>
        </section>

        <section aria-label="Danh sách hồ sơ" class="min-w-0 overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-5 sm:px-6">
                <div><flux:heading level="2">Hồ sơ theo bộ lọc</flux:heading><p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ number_format($records->total()) }} hồ sơ · Sắp xếp theo ngày nộp sớm nhất</p></div>
                <flux:badge size="sm" color="zinc">Không bao gồm bản nháp</flux:badge>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Danh sách hồ sơ theo bộ lọc đợt tuyển sinh, trạng thái và tìm kiếm</caption>
                    <thead class="border-y border-zinc-100 bg-zinc-50 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">
                        <tr>@foreach (['Mã hồ sơ', 'Mã thí sinh', 'Họ tên', 'Đợt tuyển sinh', 'Trạng thái'] as $column)<th scope="col" class="whitespace-nowrap px-5 py-3 font-medium sm:px-6">{{ $column }}</th>@endforeach</tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($records as $application)
                            @php($label = App\Support\CandidateStatusLabels::application($application->status))
                            <tr wire:key="admin-application-{{ $application->id }}" class="transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                <th scope="row" class="whitespace-nowrap px-5 py-4 font-semibold sm:px-6"><a href="{{ route('admin.applications.show', $application->id) }}" wire:navigate class="text-indigo-700 underline-offset-4 hover:underline dark:text-indigo-300">{{ $application->application_code }}</a></th>
                                <td class="whitespace-nowrap px-5 py-4 text-zinc-500 dark:text-zinc-400 sm:px-6">{{ $application->candidateProfile->candidate_code }}</td>
                                <td class="min-w-40 px-5 py-4 font-medium sm:px-6">{{ $application->candidateProfile->user->name }}</td>
                                <td class="min-w-40 px-5 py-4 text-zinc-600 dark:text-zinc-300 sm:px-6">{{ $application->admissionRound->name }}</td>
                                <td class="whitespace-nowrap px-5 py-4 sm:px-6"><flux:badge size="sm" :color="$statusColors[$label] ?? 'zinc'">{{ $label }}</flux:badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-10 text-center text-zinc-500 dark:text-zinc-400">Chưa có hồ sơ phù hợp với bộ lọc.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($records->hasPages())<div class="border-t border-zinc-100 px-5 py-4 dark:border-zinc-800 sm:px-6">{{ $records->links() }}</div>@endif
        </section>
    @else
        <flux:callout>Không thể hiển thị thống kê với bộ lọc này. Vui lòng kiểm tra hoặc xóa bộ lọc.</flux:callout>
    @endif
</section>
