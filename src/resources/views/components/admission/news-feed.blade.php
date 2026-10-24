@foreach (App\Models\NewsArticle::CATEGORIES as $category => $label)
    <section id="{{ $category === 'school' ? 'portal-news' : 'portal-admissions' }}" class="portal-news-panel scroll-mt-20 overflow-hidden" aria-labelledby="news-heading-{{ $category }}">
        <div class="flex items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-zinc-700">
            <h2 id="news-heading-{{ $category }}" class="text-sm font-semibold uppercase text-zinc-800 dark:text-zinc-100">{{ $label }}</h2>
            <a href="{{ route('news', ['category' => $category]) }}" wire:navigate class="text-sm text-admission-blue dark:text-rose-200">Xem tất cả</a>
        </div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            @forelse ($groups[$category] as $article)
                <x-admission.news-card :article="$article" />
            @empty
                <p data-portal-search="{{ $label }}" x-show="matches($el.dataset.portalSearch)" class="py-6 text-center text-sm italic text-zinc-400 sm:col-span-2">Thông tin sẽ được cập nhật tại đây.</p>
            @endforelse
        </div>
    </section>
@endforeach
