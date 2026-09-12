<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\AdmissionMethod;
use App\Models\User;

class AdmissionMethodPolicy
{
    use RequiresActiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->canReviewAdmissions();
    }

    public function view(User $user, AdmissionMethod $record): bool
    {
        return $user->canReviewAdmissions();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, AdmissionMethod $record): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, AdmissionMethod $record): bool
    {
        return $user->isAdmin() && AdmissionMethod::query()->whereKey($record->getKey())
            ->doesntHave('programs')->exists();
    }
}
