<?php

namespace App\Concerns;

use App\Models\User;

/**
 * Policies authorize abilities, not query results. Candidate lists must be scoped
 * to their owner; staff/admin lists must authorize viewAny before querying.
 */
trait RequiresActiveAccount
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isActive() ? null : false;
    }
}
