@props(['title', 'rows'])
@php($maximum = max(1, collect($rows)->max('count') ?? 0))
<figure class="min-w-0 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
    <figcaption class="mb-4 text-base font-semibold">{{ $title }}</figcaption>
    @if (collect($rows)->sum('count') === 0)
        <p class="text-sm text-zinc-500 dark:text-zinc-400">Chưa có dữ liệu phù hợp.</p>
    @endif
    <div class="max-h-96 overflow-y-auto">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">{{ $title }} — số lượng thực tế theo bộ lọc</caption>
            <thead><tr><th scope="col" class="pb-3">Nhóm</th><th scope="col" class="pb-3 text-right">Số lượng</th></tr></thead>
            <tbody>
                @foreach ($rows as $index => $row)
                    <tr wire:key="chart-{{ $title }}-{{ $index }}">
                        <th scope="row" class="w-full py-2 pr-4 font-normal">
                            <span class="break-words">{{ $row['label'] }}</span>
                            <div aria-hidden="true" class="mt-1 h-2 overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-full rounded bg-emerald-600 dark:bg-emerald-400" style="width: {{ round($row['count'] / $maximum * 100, 2) }}%"></div>
                            </div>
                        </th>
                        <td class="py-2 text-right align-top tabular-nums">{{ number_format($row['count']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</figure>
