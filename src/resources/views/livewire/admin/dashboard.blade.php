<div>
    @if ($actor->isAdmin())
        @include('livewire.admin.dashboard-overview')
    @else
        @include('livewire.admin.dashboard-review')
    @endif
</div>
