<?php

namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\CandidateScore;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordAdmissionReviewActivity
{
    /** @return list<string> */
    public function fields(Model $subject): array
    {
        return match (true) {
            $subject instanceof Application => ['status', 'reviewed_by', 'reviewed_at', 'revision_reason'],
            $subject instanceof CandidateDocument => ['status', 'verified_by', 'verified_at', 'rejection_reason'],
            $subject instanceof CandidateScore => ['verified', 'verified_by'],
            default => throw new \LogicException('Unsupported review subject.'),
        };
    }

    /** @param array<string, mixed> $old */
    public function record(User $actor, Model $subject, string $action, array $old): void
    {
        $fields = array_flip($this->fields($subject));
        $agent = request()->userAgent();
        $log = new ActivityLog([
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'old_values' => array_intersect_key($old, $fields),
            'new_values' => array_intersect_key($subject->getAttributes(), $fields),
            'created_at' => now(config('app.timezone')),
            'ip_address' => request()->ip(),
            'user_agent' => $agent === null ? null : Str::substr($agent, 0, 1000),
        ]);
        if (! $log->save()) {
            throw ValidationException::withMessages(['review' => __('The review could not be recorded. No changes were saved.')]);
        }
    }
}
