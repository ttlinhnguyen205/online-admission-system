<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Enums\VerificationStatus;
use App\Models\CandidateAdmissionClaim;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CandidateAdmissionClaimPolicy
{
    use RequiresActiveAccount;

    public function view(User $user, CandidateAdmissionClaim $claim): Response
    {
        return $this->owns($user, $claim) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user, CandidateProfile $profile): bool
    {
        return $user->isCandidate() && $profile->user_id === $user->id;
    }

    public function update(User $user, CandidateAdmissionClaim $claim): bool
    {
        return $this->owns($user, $claim) && $claim->getRawOriginal('status') !== VerificationStatus::Verified->value;
    }

    public function delete(User $user, CandidateAdmissionClaim $claim): bool
    {
        return $this->update($user, $claim);
    }

    private function owns(User $user, CandidateAdmissionClaim $claim): bool
    {
        return $user->isCandidate() && CandidateAdmissionClaim::query()->whereKey($claim->getKey())
            ->whereHas('candidateProfile', fn ($query) => $query->whereBelongsTo($user))->exists();
    }
}
