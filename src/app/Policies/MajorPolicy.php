<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\Major;
use App\Models\User;

class MajorPolicy
{
    use RequiresActiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->canReviewAdmissions();
    }

    public function view(User $user, Major $record): bool
    {
        return $user->canReviewAdmissions();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Major $record): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Major $record): bool
    {
        return $user->isAdmin() && Major::query()->whereKey($record->getKey())
            ->doesntHave('programs')->doesntHave('candidateMajorOfferings')->exists();
    }
}
