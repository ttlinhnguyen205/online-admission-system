<section class="mx-auto w-full max-w-7xl">
    <flux:callout class="mb-4">Đánh giá phương thức được xử lý nội bộ sau khi nộp. Điểm và điều kiện xét tuyển chưa được công bố; chưa có quyết định trúng tuyển.</flux:callout>
    <flux:text class="mb-4">{{ $evaluationProcessed ? 'Đã đánh giá nội bộ các phương thức.' : 'Chờ đánh giá nội bộ các phương thức.' }}</flux:text>
    <livewire:candidate.native-wishes :application-id="$application->id" :key="'native-'.$application->id" />
</section>
