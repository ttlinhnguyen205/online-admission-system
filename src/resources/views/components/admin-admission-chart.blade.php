@props(['title', 'rows', 'total', 'unit', 'colors' => []])
<figure {{ $attributes->class('min-w-0 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6') }}>
    <figcaption class="text-base font-semibold text-zinc-900 dark:text-white">{{ $title }}</figcaption>
    <p class="mt-1 text-xs leading-5 text-zinc-500 dark:text-zinc-400">
        Tỷ lệ trên {{ number_format($total) }} {{ $unit }} theo bộ lọc. Làm tròn đến 2 chữ số thập phân.
    </p>
    @if ($total === 0)
        <p class="mt-5 rounded-lg bg-zinc-50 p-4 text-sm text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">Chưa có dữ liệu phù hợp.</p>
    @endif
    <table class="mt-5 w-full table-fixed text-left text-sm">
        <caption class="sr-only">{{ $title }} — số lượng và tỷ lệ trên tổng {{ number_format($total) }} {{ $unit }} theo bộ lọc</caption>
        <thead class="text-xs text-zinc-500 dark:text-zinc-400">
            <tr><th scope="col" class="pb-2 font-medium">Nhóm</th><th scope="col" class="w-24 pb-2 text-right font-medium sm:w-28">Số lượng | Tỷ lệ</th></tr>
        </thead>
        <tbody>
            @foreach ($rows as $index => $row)
                @php
                    $percentage = $total > 0 ? $row['count'] / $total * 100 : 0;
                    $color = $colors[$row['label']] ?? 'indigo';
                    $barClass = match ($color) {
                        'sky' => 'bg-rose-500 dark:bg-rose-400',
                        'amber' => 'bg-rose-500 dark:bg-rose-400',
                        'orange' => 'bg-rose-500 dark:bg-rose-400',
                        'emerald' => 'bg-rose-500 dark:bg-rose-400',
                        'rose' => 'bg-rose-500 dark:bg-rose-400',
                        'violet' => 'bg-rose-500 dark:bg-rose-400',
                        'teal' => 'bg-rose-500 dark:bg-rose-400',
                        default => 'bg-rose-500 dark:bg-rose-400',
                    };
                @endphp
                <tr wire:key="admin-chart-{{ $title }}-{{ $index }}">
                    <th scope="row" class="py-3 pr-4 align-top font-normal">
                        <details class="group min-w-0">
                            <summary aria-label="{{ $row['label'] }}" class="flex cursor-pointer list-none items-center gap-1 rounded text-zinc-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-rose-500 dark:text-zinc-200">
                                <span class="min-w-0 truncate" title="{{ $row['label'] }}">{{ Illuminate\Support\Str::limit($row['label'], 52) }}</span>
                                <flux:icon.chevron-down variant="micro" class="size-3 shrink-0 text-zinc-400 transition-transform group-open:rotate-180" aria-hidden="true" />
                            </summary>
                            <p class="mt-2 break-words rounded-lg bg-zinc-50 p-3 text-xs leading-5 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $row['label'] }}</p>
                        </details>
                        <div aria-hidden="true" class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-full rounded-full {{ $barClass }}" style="width: {{ round($percentage, 3) }}%"></div>
                        </div>
                    </th>
                    <td class="py-3 text-right align-top tabular-nums">
                        <span class="block font-semibold text-zinc-900 dark:text-white">{{ number_format($row['count']) }}</span>
                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">{{ App\Support\DisplayNumber::decimal($percentage) }}%</span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</figure>
