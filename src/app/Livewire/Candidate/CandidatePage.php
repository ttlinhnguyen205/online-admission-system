<?php

namespace App\Livewire\Candidate;

use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

abstract class CandidatePage extends Component
{
    public function boot(): void
    {
        $this->candidate();
    }

    protected function candidate(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $user->refresh();
        abort_unless($user->isActive() && $user->isCandidate() && $user->hasVerifiedEmail(), 403);

        return $user;
    }

    protected function profile(): CandidateProfile
    {
        $profile = $this->candidate()->candidateProfile()->firstOrFail();
        Gate::authorize('view', $profile);

        return $profile;
    }

    /** @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    protected function normalize(array $form): array
    {
        foreach ($form as $key => $value) {
            if (is_string($value)) {
                $form[$key] = trim($value) === '' ? null : trim($value);
            }
        }

        return $form;
    }
}
