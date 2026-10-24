<?php

namespace App\View\Components\Admission;

use App\Models\NewsArticle;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class NewsFeed extends Component
{
    public function render(): View
    {
        $groups = [];
        foreach (NewsArticle::CATEGORIES as $category => $label) {
            $groups[$category] = NewsArticle::query()->published()->where('category', $category)
                ->latest('published_at')->latest('id')->limit(5)->get();
        }

        return view('components.admission.news-feed', ['groups' => $groups]);
    }
}
