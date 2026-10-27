<section class="admission-workspace mx-auto flex min-w-0 w-full max-w-7xl flex-col gap-6">
    <flux:callout >Đánh giá phương thức được xử lý nội bộ sau khi nộp. Điểm và điều kiện xét tuyển chưa được công bố; chưa có quyết định trúng tuyển.</flux:callout>
    <flux:text >{{ $evaluationProcessed ? 'Đã đánh giá nội bộ các phương thức.' : 'Chờ đánh giá nội bộ các phương thức.' }}</flux:text>
    <livewire:candidate.native-wishes :application-id="$application->id" :key="'native-'.$application->id" />
</section>
