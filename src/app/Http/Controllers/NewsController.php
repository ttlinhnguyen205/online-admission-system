<?php

namespace App\Http\Controllers;

use App\Models\NewsArticle;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NewsController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate(['category' => ['nullable', Rule::in(array_keys(NewsArticle::CATEGORIES))]]);
        $category = $validated['category'] ?? null;

        return view('news.index', [
            'category' => $category,
            'articles' => NewsArticle::query()->published()
                ->when($category, fn ($query) => $query->where('category', $category))
                ->latest('published_at')->latest('id')->paginate(12)->withQueryString(),
        ]);
    }

    public function show(int $article): View
    {
        return view('news.show', ['article' => NewsArticle::query()->published()->findOrFail($article)]);
    }
}
