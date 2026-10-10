<?php

namespace App\Actions;

use App\Models\AdmissionRound;

class AdmissionCatalogLock
{
    /** Acquire inside a transaction before catalog row locks. */
    public static function acquire(): void
    {
        AdmissionRound::query()->orderBy('id')->lockForUpdate()->get(['id']);
    }
}
