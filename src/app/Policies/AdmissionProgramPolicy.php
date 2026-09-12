<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\AdmissionProgram;
use App\Models\User;

class AdmissionProgramPolicy
{
    use RequiresActiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->canReviewAdmissions();
    }

    public function view(User $user, AdmissionProgram $record): bool
    {
        return $user->canReviewAdmissions();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, AdmissionProgram $record): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, AdmissionProgram $record): bool
    {
        return $user->isAdmin() && AdmissionProgram::query()->whereKey($record->getKey())
            ->doesntHave('wishes')->exists();
    }
}
