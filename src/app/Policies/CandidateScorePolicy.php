<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\CandidateProfile;
use App\Models\CandidateScore;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CandidateScorePolicy
{
    use RequiresActiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->canReviewAdmissions();
    }

    public function view(User $user, CandidateScore $score): Response
    {
        return $user->canReviewAdmissions() || ($user->isCandidate()
            && CandidateScore::query()->whereKey($score->getKey())
                ->whereHas('candidateProfile', fn ($query) => $query->whereBelongsTo($user))->exists())
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Pass the proposed candidate attributes; verification and ownership fields
     * are never candidate-writable. Future actions must persist only these
     * authorized, validated attributes, derive ownership from the parent and
     * retain the database's initially unverified default.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, CandidateProfile $profile, array $attributes = []): bool
    {
        return $this->hasOnlyCandidateAttributes($attributes) && $this->ownsProfile($user, $profile);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $user, CandidateScore $score, array $attributes = []): bool
    {
        $persistedScore = CandidateScore::query()->whereKey($score->getKey())->where('verified', false)->first();
        $profile = $persistedScore?->candidateProfile()->first();

        return $profile !== null && $this->hasOnlyCandidateAttributes($attributes)
            && $this->ownsProfile($user, $profile);
    }

    public function delete(User $user, CandidateScore $score): bool
    {
        return $this->update($user, $score);
    }

    public function verify(User $user, CandidateScore $score): bool
    {
        return $user->canReviewAdmissions();
    }

    /**
     * Scores belong to a profile, not an application or admission round.
     * Application-specific score snapshots and stronger historical locking
     * belong in the later application/admission-engine design.
     */
    private function ownsProfile(User $user, CandidateProfile $profile): bool
    {
        return $user->isCandidate()
            && CandidateProfile::query()->whereKey($profile->getKey())->whereBelongsTo($user)->exists();
    }

    /** @param array<string, mixed> $attributes */
    private function hasOnlyCandidateAttributes(array $attributes): bool
    {
        return array_diff(array_keys($attributes), [
            'score_type', 'subject_code', 'subject_name', 'score', 'exam_year',
        ]) === [];
    }
}
