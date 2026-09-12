<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class AdmissionWishPolicy
{
    use RequiresActiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->canReviewAdmissions();
    }

    public function view(User $user, AdmissionWish $wish): Response
    {
        return $user->canReviewAdmissions() || ($user->isCandidate()
            && AdmissionWish::query()->whereKey($wish->getKey())
                ->whereHas('application.candidateProfile', fn ($query) => $query->whereBelongsTo($user))->exists())
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user, Application $application): bool
    {
        return Gate::forUser($user)->allows('update', $application);
    }

    public function update(User $user, AdmissionWish $wish): bool
    {
        $application = AdmissionWish::query()->whereKey($wish->getKey())->first()?->application()->first();

        return $application !== null && Gate::forUser($user)->allows('update', $application);
    }

    public function delete(User $user, AdmissionWish $wish): bool
    {
        return $this->update($user, $wish) && ! $wish->result()->exists();
    }
}
