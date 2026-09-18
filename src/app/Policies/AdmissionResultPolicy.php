<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\AdmissionResult;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AdmissionResultPolicy
{
    use RequiresActiveAccount;

    public function confirm(User $user, AdmissionResult $result): Response
    {
        return $user->isCandidate() && $user->hasVerifiedEmail()
            && AdmissionResult::query()->visibleToCandidate($user)->whereKey($result->getKey())->where('decision', 'admitted')->exists()
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function viewAny(User $user): bool
    {
        return $user->canReviewAdmissions();
    }

    public function view(User $user, AdmissionResult $result): Response
    {
        return $user->canReviewAdmissions() || ($user->isCandidate()
            && AdmissionResult::query()->whereKey($result->getKey())
                ->whereHas('admissionWish.application.candidateProfile', fn ($query) => $query->whereBelongsTo($user))
                ->whereNotNull('published_at')->where('published_at', '<=', now())->exists())
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Administrative correction of a decision, score or rank, separate from
     * candidate editing. Future actions must validate and audit corrections.
     */
    public function update(User $user, AdmissionResult $result): bool
    {
        return $user->isAdmin();
    }

    public function publish(User $user, AdmissionResult $result): bool
    {
        return $user->isAdmin() && $user->hasVerifiedEmail();
    }
}
