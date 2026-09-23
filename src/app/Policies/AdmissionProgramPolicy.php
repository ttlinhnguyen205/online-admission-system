<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\AdmissionProgram;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AdmissionProgramPolicy
{
    use RequiresActiveAccount;

    public function browseForApplication(User $user, Application $application): bool
    {
        return $user->isCandidate() && $user->hasVerifiedEmail()
            && Gate::forUser($user)->allows('view', $application);
    }

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
            ->doesntHave('wishes')->doesntHave('candidateMajorOfferings')->exists();
    }
}
