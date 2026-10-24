<x-layouts::portal :title="$article->title">
    <article class="portal-news-panel mx-auto flex w-full max-w-4xl flex-col gap-5 p-5 sm:p-8">
        <a href="{{ route('news') }}" wire:navigate class="text-sm font-medium text-admission-blue dark:text-rose-200">← Quay lại Tin tức</a>
        <p class="text-sm text-zinc-500">{{ App\Models\NewsArticle::CATEGORIES[$article->category] }} | {{ $article->published_at->format('d/m/Y H:i') }}</p>
        <h1 class="break-words text-2xl font-bold text-zinc-900 sm:text-3xl dark:text-zinc-100">{{ $article->title }}</h1>
        @if ($article->summary)
            <p class="break-words font-medium leading-7 text-zinc-700 dark:text-zinc-200">{{ $article->summary }}</p>
        @endif
        <div class="whitespace-pre-line break-words leading-8 text-zinc-700 dark:text-zinc-200">{{ $article->content }}</div>
    </article>
</x-layouts::portal>
