@props(['title', 'description' => null])

<x-admission.workspace-heading :title="$title" :description="$description" {{ $attributes }}>
    {{ $slot }}
</x-admission.workspace-heading>
