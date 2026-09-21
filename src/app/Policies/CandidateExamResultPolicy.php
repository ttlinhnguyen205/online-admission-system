<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Enums\VerificationStatus;
use App\Models\CandidateExamResult;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CandidateExamResultPolicy
{
    use RequiresActiveAccount;

    public function view(User $user, CandidateExamResult $result): Response
    {
        return $this->owns($user, $result) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user, CandidateProfile $profile): bool
    {
        return $user->isCandidate() && $profile->user_id === $user->id;
    }

    public function update(User $user, CandidateExamResult $result): bool
    {
        return $this->owns($user, $result) && $result->getRawOriginal('status') !== VerificationStatus::Verified->value;
    }

    public function delete(User $user, CandidateExamResult $result): bool
    {
        return $this->update($user, $result);
    }

    private function owns(User $user, CandidateExamResult $result): bool
    {
        return $user->isCandidate() && CandidateExamResult::query()->whereKey($result->getKey())
            ->whereHas('candidateProfile', fn ($query) => $query->whereBelongsTo($user))->exists();
    }
}
