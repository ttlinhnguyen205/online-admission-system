<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Enums\VerificationStatus;
use App\Models\CandidateProfile;
use App\Models\CandidateTranscript;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CandidateTranscriptPolicy
{
    use RequiresActiveAccount;

    public function view(User $user, CandidateTranscript $transcript): Response
    {
        return $this->owns($user, $transcript) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user, CandidateProfile $profile): bool
    {
        return $user->isCandidate() && $profile->user_id === $user->id;
    }

    public function update(User $user, CandidateTranscript $transcript): bool
    {
        return $this->owns($user, $transcript) && $transcript->getRawOriginal('status') !== VerificationStatus::Verified->value;
    }

    public function delete(User $user, CandidateTranscript $transcript): bool
    {
        return $this->update($user, $transcript);
    }

    private function owns(User $user, CandidateTranscript $transcript): bool
    {
        return $user->isCandidate() && CandidateTranscript::query()->whereKey($transcript->getKey())
            ->whereHas('candidateProfile', fn ($query) => $query->whereBelongsTo($user))->exists();
    }
}
