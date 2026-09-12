<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    use RequiresActiveAccount;

    public function changeRole(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $user->is($target);
    }

    public function delete(User $user, User $target): Response
    {
        if (! $user->is($target) || ! $user->hasVerifiedEmail()) {
            return Response::deny('Only a verified account owner may delete their account.');
        }

        if ($target->candidateProfile()->exists() || $target->createdAnnouncements()->exists()) {
            return Response::deny('Your account cannot be deleted because it has protected admission records or authored announcements.');
        }

        return Response::allow();
    }
}
