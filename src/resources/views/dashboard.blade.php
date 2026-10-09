<x-layouts::app :title="__('Bảng điều khiển')">
    @if (auth()->user()->isCandidate())
        <livewire:candidate.dashboard />
    @else
        <livewire:admin.dashboard />
    @endif
</x-layouts::app>
