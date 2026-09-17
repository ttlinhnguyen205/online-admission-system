<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\AdmissionRound;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AdmissionRoundPolicy
{
    use RequiresActiveAccount;

    public function process(User $user): bool
    {
        return $user->isAdmin() && $user->hasVerifiedEmail();
    }

    public function browseForCandidate(User $user, CandidateProfile $profile): bool
    {
        return $user->isCandidate() && $user->hasVerifiedEmail()
            && Gate::forUser($user)->allows('update', $profile);
    }

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
