<section class="admission-workspace mx-auto flex min-w-0 w-full max-w-7xl flex-col gap-6">
    <header class="admission-panel border-t-4 border-t-admission-blue p-5 sm:p-6">
        <flux:heading size="xl" level="1">Công bố kết quả</flux:heading>
        <p class="mt-2 text-base leading-7 text-zinc-600 dark:text-zinc-300">Kiểm tra kết quả đã lưu và tình trạng xác nhận nhập học. Công bố không tính lại điểm hoặc thay đổi kết quả xét tuyển.</p>
    </header>
    <div class="admission-panel p-5">
        <flux:select wire:model.live="roundSelection" label="Đợt tuyển sinh">
            <option value="">Chọn đợt tuyển sinh</option>
            @foreach ($rounds as $round)
                <option wire:key="round-{{ $round->id }}" value="{{ $round->id }}">{{ $round->year }} · {{ $round->name }} ({{ App\Support\CandidateStatusLabels::round($round->status, $round->end_date) }})</option>
            @endforeach
        </flux:select>
        <flux:error name="roundSelection" /><flux:error name="engine" />
    </div>
    @if ($selectedRound && $selectedRound->nativeRegistrationState() !== 'legacy')
        <div class="admission-panel p-5"><livewire:admin.native-results :round-id="$selectedRound->id" :key="'native-results-'.$selectedRound->id" /></div>
    @elseif ($selectedRound)
        <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-label="Thống kê toàn bộ đợt tuyển sinh">
            @foreach (['Total results' => 'Tổng số kết quả', 'Admitted' => 'Trúng tuyển', 'Not admitted' => 'Không trúng tuyển', 'Published' => 'Đã công bố', 'Unpublished' => 'Chưa công bố', 'Confirmed admitted' => 'Đã xác nhận trúng tuyển'] as $key => $label)
                <div wire:key="count-{{ $loop->index }}" class="admission-panel p-5"><dt class="text-sm text-zinc-600 dark:text-zinc-300">{{ $label }}</dt><dd class="mt-2 text-2xl font-semibold tabular-nums">{{ number_format($counts[$key]) }}</dd></div>
            @endforeach
        </dl>
        @if ($selectedRound->status->value === 'published')
            <flux:callout variant="success">Kết quả đã được công bố. Thời gian công bố và xác nhận nhập học được giữ nguyên.</flux:callout>
        @elseif ($selectedRound->status->value === 'processing')
            <div class="admission-panel flex flex-wrap items-center justify-between gap-4 p-5">
                <p class="text-sm text-zinc-600 dark:text-zinc-300">Kiểm tra kết quả trước khi công bố cho thí sinh.</p>
                <flux:button variant="primary" wire:click="publish" wire:confirm="{{ __('Publish these saved results and notify candidates?') }}" wire:loading.attr="disabled">{{ __('Publish results') }}</flux:button>
            </div>
        @else
            <flux:callout>{{ __('Only a processing round with a completed admission-engine run can be published.') }}</flux:callout>
        @endif
        <div class="admission-panel grid min-w-0 gap-4 p-5 sm:grid-cols-2 xl:grid-cols-4">
            <flux:input type="search" wire:model.live.debounce.400ms="search" label="Tìm kiếm" placeholder="Tên thí sinh hoặc mã hồ sơ" />
            <flux:select wire:model.live="decisionFilter" label="Kết quả xét tuyển"><option value="">Tất cả kết quả</option><option value="admitted">Trúng tuyển</option><option value="not_admitted">Không trúng tuyển</option></flux:select>
            <flux:select wire:model.live="publicationFilter" label="Trạng thái công bố"><option value="">Tất cả trạng thái</option><option value="published">Đã công bố</option><option value="unpublished">Chưa công bố</option></flux:select>
            <flux:select wire:model.live="confirmationFilter" label="Xác nhận nhập học"><option value="">Tất cả kết quả</option><option value="confirmed">Đã xác nhận</option><option value="unconfirmed">Trúng tuyển, chưa xác nhận</option></flux:select>
            <div class="flex flex-wrap items-center justify-between gap-3 sm:col-span-2 xl:col-span-4">
                <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ number_format($results->total()) }} kết quả phù hợp · Thống kê phía trên tính toàn bộ đợt</p>
                <flux:button wire:click="clearFilters">Xóa bộ lọc</flux:button>
            </div>
        </div>
        <div class="admission-panel min-w-0 overflow-hidden">
            <div class="overflow-x-auto" tabindex="0" aria-label="Bảng công bố kết quả">
                <table class="workspace-table results-table w-full text-left text-sm leading-6">
                    <caption class="sr-only">Kết quả tuyển sinh, 25 kết quả mỗi trang</caption>
                    <thead><tr><th scope="col" class="table-stt">STT</th>@foreach (['Hồ sơ / Thí sinh', 'Thứ tự nguyện vọng', 'Ngành / Phương thức', 'Điểm xét tuyển', 'Thứ hạng', 'Kết quả xét tuyển', 'Trạng thái công bố', 'Xác nhận nhập học'] as $label)<th scope="col" @class(['text-right' => in_array($label, ['Điểm xét tuyển', 'Thứ hạng'])])>{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                        @forelse ($results as $result)
                            @php($wish = $result->admissionWish)
                            <tr wire:key="result-{{ $result->id }}">
                                <td class="table-stt" data-label="STT">{{ $results->firstItem() + $loop->index }}</td>
                                <td data-label="Hồ sơ / Thí sinh"><p class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $wish->application->candidateProfile?->user?->name ?? 'Không xác định' }}</p><a class="text-admission-blue hover:underline dark:text-rose-300" href="{{ route('admin.applications.show', $wish->application->id) }}" wire:navigate>{{ $wish->application->application_code }}</a></td>
                                <td data-label="Nguyện vọng"><span class="font-semibold">NV{{ $wish->priority }}</span></td>
                                <td data-label="Ngành / Phương thức"><p class="font-medium">{{ $wish->admissionProgram->major->name }}</p><p class="text-zinc-600 dark:text-zinc-300">{{ $wish->admissionProgram->admissionMethod->name }}</p></td>
                                <td data-label="Điểm xét tuyển" class="text-right font-semibold tabular-nums">{{ App\Support\DisplayNumber::decimal($result->final_score) }}</td>
                                <td data-label="Thứ hạng" class="text-right tabular-nums">{{ $result->rank ?? '—' }}</td>
                                <td data-label="Kết quả"><flux:badge :color="$result->decision->value === 'admitted' ? 'green' : 'zinc'">{{ App\Support\CandidateStatusLabels::result($result->decision) }}</flux:badge></td>
                                <td data-label="Công bố"><flux:badge :color="$result->published_at ? 'green' : 'amber'">{{ $result->published_at ? 'Đã công bố' : 'Chưa công bố' }}</flux:badge>@if ($result->published_at)<p class="mt-1 text-zinc-600 dark:text-zinc-300">{{ $result->published_at->format('d/m/Y H:i') }}</p>@endif</td>
                                <td data-label="Xác nhận nhập học">
                                    @if ($result->confirmed_at)<flux:badge color="green">Đã xác nhận nhập học</flux:badge><p class="mt-1 text-zinc-600 dark:text-zinc-300">{{ $result->confirmed_at->format('d/m/Y H:i') }}</p>
                                    @elseif ($result->decision->value === 'admitted')<flux:badge color="zinc">Chưa xác nhận</flux:badge>
                                    @else<span class="text-zinc-500">Không áp dụng</span>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9"><p role="status" class="py-8 text-center">Không tìm thấy kết quả phù hợp.</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">{{ $results->links() }}</div>
        </div>
    @else
        <div class="admission-panel p-6 text-zinc-600 dark:text-zinc-300">Chọn đợt tuyển sinh để xem thống kê và kết quả.</div>
    @endif
    <div role="status" wire:loading.delay>{{ __('Loading…') }}</div>
</section>
