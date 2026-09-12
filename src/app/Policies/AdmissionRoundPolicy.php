<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\AdmissionRound;
use App\Models\User;

class AdmissionRoundPolicy
{
    use RequiresActiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->canReviewAdmissions();
    }

    public function view(User $user, AdmissionRound $record): bool
    {
        return $user->canReviewAdmissions();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, AdmissionRound $record): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, AdmissionRound $record): bool
    {
        return $user->isAdmin() && AdmissionRound::query()->whereKey($record->getKey())
            ->doesntHave('programs')->doesntHave('applications')->exists();
    }
}
