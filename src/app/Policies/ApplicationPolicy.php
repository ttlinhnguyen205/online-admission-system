<?php

namespace App\Policies;

use App\Actions\NativeWishRegistration;
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

    public function export(User $user): bool
    {
        return $user->isActive() && $user->hasVerifiedEmail() && $user->canReviewAdmissions();
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
            && ($application->registration_mode === 'native'
                ? NativeWishRegistration::openForRound($application->admissionRound()->firstOrFail())
                : $application->admissionRound()->firstOrFail()->nativeRegistrationState() === 'legacy')
            && Application::query()->whereKey($application->getKey())
                ->whereHas('candidateProfile', fn ($query) => $query->whereBelongsTo($user))
                ->whereIn('status', [ApplicationStatus::Draft, ApplicationStatus::NeedsRevision])->exists();
    }

    public function review(User $user, Application $application): bool
    {
        return $user->canReviewAdmissions();
    }

    public function submit(User $user, Application $application): bool
    {
        return $this->update($user, $application);
    }

    public function scoreNative(User $user, Application $application): bool
    {
        return $user->canReviewAdmissions() && $user->isActive() && $user->hasVerifiedEmail()
            && $application->registration_mode === 'native' && $application->submitted_at !== null
            && in_array($application->getAttribute('status'), [ApplicationStatus::Submitted, ApplicationStatus::UnderReview, ApplicationStatus::Verified], true);
    }

    public function delete(User $user, Application $application): bool
    {
        return false;
    }
}
