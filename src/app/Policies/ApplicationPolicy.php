<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class ApplicationPolicy
{
    use RequiresActiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->canReviewAdmissions();
    }

    public function view(User $user, Application $application): Response
    {
        return $user->canReviewAdmissions() || ($user->isCandidate()
            && Application::query()->whereKey($application->getKey())
                ->whereHas('candidateProfile', fn ($query) => $query->whereBelongsTo($user))->exists())
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user, CandidateProfile $profile): bool
    {
        return Gate::forUser($user)->allows('update', $profile);
    }

    public function update(User $user, Application $application): bool
    {
        return $user->isCandidate()
            && Application::query()->whereKey($application->getKey())
                ->whereHas('candidateProfile', fn ($query) => $query->whereBelongsTo($user))
                ->whereIn('status', [ApplicationStatus::Draft, ApplicationStatus::NeedsRevision])->exists();
    }

    public function review(User $user, Application $application): bool
    {
        return $user->canReviewAdmissions();
    }

    public function delete(User $user, Application $application): bool
    {
        return false;
    }
}
