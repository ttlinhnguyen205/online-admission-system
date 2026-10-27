@can('use-admission-counseling')
    @unless (request()->routeIs('candidate.counseling.index'))
        <livewire:candidate.admission-counseling :floating="true" :screen="request()->route()?->getName() ?? ''" :key="'floating-counseling-'.request()->route()?->getName()" />
    @endunless
@endcan
