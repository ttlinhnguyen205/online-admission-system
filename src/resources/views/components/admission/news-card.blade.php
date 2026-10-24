@props(['article'])

<article data-portal-search="{{ $article->title.' '.$article->summary }}" x-show="matches($el.dataset.portalSearch)" class="portal-news-panel flex min-w-0 flex-col gap-3 p-5">
    <p class="text-xs text-zinc-500">{{ App\Models\NewsArticle::CATEGORIES[$article->category] }} · {{ $article->published_at->format('d/m/Y H:i') }}</p>
    <h2 class="break-words text-lg font-semibold text-zinc-900 dark:text-zinc-100">
        <a href="{{ route('news.show', $article->id) }}" wire:navigate class="hover:underline">{{ $article->title }}</a>
    </h2>
    <p class="break-words text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ Illuminate\Support\Str::limit($article->summary ?: $article->content, 220) }}</p>
    <a href="{{ route('news.show', $article->id) }}" wire:navigate class="mt-auto text-sm font-medium text-admission-blue dark:text-rose-200">Đọc tiếp<span class="sr-only">: {{ $article->title }}</span> →</a>
</article>
