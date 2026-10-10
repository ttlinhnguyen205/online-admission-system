<?php

namespace App\Policies;

use App\Models\NativeResultVersion;
use App\Models\User;

class NativeResultVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() && $user->isActive() && $user->hasVerifiedEmail();
    }

    public function approve(User $user, NativeResultVersion $version): bool
    {
        return $this->viewAny($user);
    }

    public function publish(User $user, NativeResultVersion $version): bool
    {
        return $this->viewAny($user);
    }

    public function reject(User $user, NativeResultVersion $version): bool
    {
        return $this->viewAny($user);
    }
}
