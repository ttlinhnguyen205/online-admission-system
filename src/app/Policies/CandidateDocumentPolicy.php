<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class CandidateDocumentPolicy
{
    use RequiresActiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->canReviewAdmissions();
    }

    public function view(User $user, CandidateDocument $document): Response
    {
        return $user->canReviewAdmissions() || ($user->isCandidate()
            && CandidateDocument::query()->whereKey($document->getKey())
                ->whereHas('application.candidateProfile', fn ($query) => $query->whereBelongsTo($user))->exists())
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user, Application $application): bool
    {
        return Gate::forUser($user)->allows('update', $application);
    }

    public function update(User $user, CandidateDocument $document): bool
    {
        $application = CandidateDocument::query()->whereKey($document->getKey())->first()?->application()->first();

        return $application !== null && Gate::forUser($user)->allows('update', $application);
    }

    public function delete(User $user, CandidateDocument $document): bool
    {
        return $this->update($user, $document);
    }

    public function download(User $user, CandidateDocument $document): Response
    {
        return $this->view($user, $document);
    }

    public function verify(User $user, CandidateDocument $document): bool
    {
        return $user->canReviewAdmissions();
    }

    public function reject(User $user, CandidateDocument $document): bool
    {
        return $user->canReviewAdmissions();
    }
}
