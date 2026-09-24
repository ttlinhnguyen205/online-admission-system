<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\CandidateMajorOffering;
use App\Models\User;

class CandidateMajorOfferingPolicy
{
    use RequiresActiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() && $user->hasVerifiedEmail();
    }

    public function view(User $user, CandidateMajorOffering $offering): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, CandidateMajorOffering $offering): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, CandidateMajorOffering $offering): bool
    {
        return $this->viewAny($user) && ! $offering->wishes()->exists();
    }
}
