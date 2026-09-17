<?php

namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\AdmissionRound;
use App\Models\User;
use Illuminate\Support\Str;

class RecordAdmissionEngineActivity
{
    /** @param array<string, mixed> $metadata */
    public function record(User $actor, AdmissionRound $round, string $action, array $metadata): void
    {
        if (! in_array($action, ['admission_engine.started', 'admission_engine.completed'], true)) {
            throw new \LogicException('Unsupported engine audit action.');
        }
        $safe = array_intersect_key($metadata, array_flip([
            'run_id', 'round_id', 'engine_version', 'algorithm_version', 'scoring_version', 'input_fingerprint',
            'outcome_fingerprint', 'included', 'excluded_drafts', 'wishes', 'results', 'admitted', 'not_admitted',
            'programs', 'decided_at',
        ]));
        $log = new ActivityLog([
            'user_id' => $actor->id, 'subject_type' => $round->getMorphClass(), 'subject_id' => $round->id,
            'action' => $action, 'old_values' => null, 'new_values' => $safe,
            'ip_address' => request()->ip(), 'user_agent' => Str::substr(request()->userAgent() ?? '', 0, 1000),
            'created_at' => now(config('app.timezone')),
        ]);
        if (! $log->save()) {
            AdmissionEngineSnapshot::fail('The engine audit could not be saved. No decisions were saved.');
        }
    }
}
