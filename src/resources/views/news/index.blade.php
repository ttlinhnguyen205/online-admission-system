<x-layouts::portal title="Tin tức">
    <section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Tin tức</h1>
        <nav class="flex flex-wrap gap-3" aria-label="Nhóm tin tức">
            <flux:button :href="route('news')" :variant="$category === null ? 'primary' : 'outline'" wire:navigate>Tất cả</flux:button>
            @foreach (App\Models\NewsArticle::CATEGORIES as $value => $label)
                <flux:button :href="route('news', ['category' => $value])" :variant="$category === $value ? 'primary' : 'outline'" wire:navigate>{{ $label }}</flux:button>
            @endforeach
        </nav>
        <div class="grid gap-4 sm:grid-cols-2">
            @forelse ($articles as $article)
                <x-admission.news-card :article="$article" />
            @empty
                <p class="portal-news-panel p-8 text-center text-zinc-500 sm:col-span-2">Chưa có bài viết được xuất bản.</p>
            @endforelse
        </div>
        {{ $articles->links() }}
    </section>
</x-layouts::portal>
