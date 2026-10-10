<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\AdmissionQuotaVersion;
use App\Models\User;

class AdmissionQuotaVersionPolicy
{
    use RequiresActiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() && $user->hasVerifiedEmail();
    }

    public function update(User $user, AdmissionQuotaVersion $version): bool
    {
        return $this->viewAny($user);
    }

    public function approve(User $user, AdmissionQuotaVersion $version): bool
    {
        return $this->viewAny($user);
    }
}
