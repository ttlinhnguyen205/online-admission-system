<?php

namespace App\Policies;

use App\Concerns\RequiresActiveAccount;
use App\Models\EvaluationRuleVersion;
use App\Models\User;

class EvaluationRuleVersionPolicy
{
    use RequiresActiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() && $user->hasVerifiedEmail();
    }

    public function update(User $user, EvaluationRuleVersion $rule): bool
    {
        return $this->viewAny($user);
    }

    public function approve(User $user, EvaluationRuleVersion $rule): bool
    {
        return $this->viewAny($user);
    }
}
