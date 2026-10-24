<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\NewsArticle;
use App\Models\User;

class NewsArticlePolicy
{
    use RequiresActiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() && $user->hasVerifiedEmail();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, NewsArticle $newsArticle): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, NewsArticle $newsArticle): bool
    {
        return $this->viewAny($user);
    }
}
