<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CandidateProfilePolicy
{
    use RequiresActiveAccount;

    public function create(User $user): bool
    {
        return $user->isCandidate() && ! $user->candidateProfile()->exists();
    }

    public function viewAny(User $user): bool
    {
        return $user->canReviewAdmissions();
    }

    public function view(User $user, CandidateProfile $profile): Response
    {
        return $user->canReviewAdmissions() || $this->update($user, $profile)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, CandidateProfile $profile): bool
    {
        return $user->isCandidate()
            && CandidateProfile::query()->whereKey($profile->getKey())->whereBelongsTo($user)->exists();
    }
}
