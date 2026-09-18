<?php

namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RecordAdmissionResultActivity
{
    /** @param array<string, mixed> $metadata */
    public function record(User $actor, Model $subject, string $action, array $metadata): void
    {
        $log = new ActivityLog([
            'user_id' => $actor->id, 'action' => $action,
            'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
            'old_values' => null, 'new_values' => $metadata,
            'ip_address' => request()->ip(), 'user_agent' => Str::substr(request()->userAgent() ?? '', 0, 1000),
            'created_at' => now(config('app.timezone')),
        ]);
        if (! $log->save()) {
            AdmissionEngineSnapshot::fail('The audit could not be saved. No changes were saved.');
        }
    }
}
