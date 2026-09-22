<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Enums\VerificationStatus;
use App\Models\CandidateCertificate;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CandidateCertificatePolicy
{
    use RequiresActiveAccount;

    public function view(User $user, CandidateCertificate $certificate): Response
    {
        return $this->owns($user, $certificate) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user, CandidateProfile $profile): bool
    {
        return $user->isCandidate() && $profile->user_id === $user->id;
    }

    public function update(User $user, CandidateCertificate $certificate): bool
    {
        return $this->owns($user, $certificate) && $certificate->getRawOriginal('status') !== VerificationStatus::Verified->value;
    }

    public function delete(User $user, CandidateCertificate $certificate): bool
    {
        return $this->update($user, $certificate);
    }

    private function owns(User $user, CandidateCertificate $certificate): bool
    {
        return $user->isCandidate() && CandidateCertificate::query()->whereKey($certificate->getKey())
            ->whereHas('candidateProfile', fn ($query) => $query->whereBelongsTo($user))->exists();
    }
}
