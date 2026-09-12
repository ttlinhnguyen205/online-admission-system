<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AnnouncementPolicy
{
    use RequiresActiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Announcement $announcement): Response
    {
        return $user->isAdmin() || Announcement::query()->whereKey($announcement->getKey())
            ->whereNotNull('published_at')->where('published_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query->whereNull('target_role')->orWhere('target_role', $user->role))->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $user->isAdmin();
    }

    public function publish(User $user, Announcement $announcement): bool
    {
        return $user->isAdmin();
    }
}
