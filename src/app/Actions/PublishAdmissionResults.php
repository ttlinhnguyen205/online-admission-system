<?php

namespace App\Actions;

use App\Enums\AdmissionDecision;
use App\Enums\ApplicationStatus;
use App\Models\ActivityLog;
use App\Models\AdmissionResult;
use App\Models\User;
use App\Notifications\AdmissionResultsPublished;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PublishAdmissionResults
{
    public function __construct(private AdmissionEngineSnapshot $snapshots, private RecordAdmissionResultActivity $audit) {}

    /** @return array<string, mixed> */
    public function publish(int $roundId): array
    {
        return DB::transaction(function () use ($roundId): array {
            $data = $this->snapshots->load($roundId, true);
            Gate::authorize('publishResults', $data['round']);
            $summary = $this->validate($data);
            if ($data['round']->getRawOriginal('status') === 'published') {
                return $summary;
            }
            $timestamp = now(config('app.timezone'))->startOfSecond();
            $summary['published_at'] = $timestamp->format('Y-m-d H:i:s');
            foreach ($data['results'] as $result) {
                $result->setAttribute('published_at', $timestamp);
                if (! $result->save()) {
                    AdmissionEngineSnapshot::fail('A result could not be published. No changes were saved.');
                }
            }
            $round = $data['round'];
            $round->setAttribute('status', 'published');
            if (! $round->save()) {
                AdmissionEngineSnapshot::fail('The round could not be published.');
            }
            foreach ($data['applications']->where('status', ApplicationStatus::Completed) as $application) {
                $recipient = User::query()->whereKey($data['profiles']->get($application->candidate_profile_id)->user_id)->firstOrFail();
                $recipient->notify(new AdmissionResultsPublished($roundId, $round->name, $application->id));
                if ($recipient->notifications()->where('type', AdmissionResultsPublished::class)
                    ->where('data->round_id', $roundId)->where('data->application_id', $application->id)->count() !== 1) {
                    AdmissionEngineSnapshot::fail('The notification could not be saved. No changes were saved.');
                }
            }
            $this->audit->record($data['actor'], $round, 'admission_results.published', $summary);

            return $summary;
        }, 3);
    }

    /**
     * Compare persisted outcomes only; lifecycle timestamps are cleared on
     * clones to reconstruct Phase 7 output without rewriting saved decisions.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        $round = $data['round'];
        if ($data['applications']->contains(fn ($a) => ! in_array($a->getRawOriginal('status'), ['draft', 'completed'], true))
            || $data['wishes']->contains(fn ($w) => ! in_array($w->getRawOriginal('status'), ['pending', 'admitted', 'rejected', 'ineligible'], true))
            || $data['results']->contains(fn ($r) => ! in_array($r->getRawOriginal('decision'), ['admitted', 'not_admitted'], true))) {
            AdmissionEngineSnapshot::fail('Publication blocked: invalid persisted outcome values.');
        }
        $started = $data['logs']->where('action', 'admission_engine.started');
        $completed = $data['logs']->where('action', 'admission_engine.completed');
        $summary = $completed->first()?->new_values;
        $start = $started->first()?->new_values;
        $comparison = is_array($summary) ? $summary : [];
        unset($comparison['outcome_fingerprint']);
        $normalized = $data;
        $normalized['results'] = $data['results']->map(function (AdmissionResult $result): AdmissionResult {
            $copy = clone $result;
            $copy->published_at = null;
            $copy->confirmed_at = null;

            return $copy;
        });
        $valid = in_array($round->getRawOriginal('status'), ['processing', 'published'], true)
            && $started->count() === 1 && $completed->count() === 1
            && is_array($summary) && $start === $comparison
            && is_string($summary['run_id'] ?? null) && ($summary['round_id'] ?? null) === $round->id
            && $summary['run_id'] !== ''
            && ($summary['engine_version'] ?? null) === config('admission_engine.version')
            && ($summary['algorithm_version'] ?? null) === config('admission_engine.algorithm_version')
            && ($summary['scoring_version'] ?? null) === config('admission_engine.scoring_version')
            && is_string($summary['outcome_fingerprint'] ?? null)
            && ($summary['results'] ?? 0) > 0 && $summary['results'] === $data['results']->count()
            && hash_equals($summary['outcome_fingerprint'], $this->snapshots->outcomeFingerprint($normalized));
        if (! $valid) {
            AdmissionEngineSnapshot::fail('Publication blocked: no consistent completed admission-engine run.');
        }
        $applications = $data['applications']->filter(fn ($a) => $a->getRawOriginal('status') !== 'draft');
        $wishes = $data['wishes']->whereIn('application_id', $applications->modelKeys());
        if ($applications->contains(fn ($a) => $a->getRawOriginal('status') !== 'completed')
            || $wishes->count() !== $data['results']->count()
            || $wishes->count() !== ($summary['wishes'] ?? null)
            || $data['results']->pluck('admission_wish_id')->sort()->values()->all() !== $wishes->keys()->sort()->values()->all()
            || $applications->count() !== ($summary['included'] ?? null)
            || $data['results']->where('decision', AdmissionDecision::Admitted)->count() !== ($summary['admitted'] ?? null)
            || $data['results']->where('decision', AdmissionDecision::NotAdmitted)->count() !== ($summary['not_admitted'] ?? null)) {
            AdmissionEngineSnapshot::fail('Publication blocked: incomplete result cohort.');
        }
        foreach ($data['results'] as $result) {
            $wish = $wishes->get($result->admission_wish_id);
            $application = $applications->get($wish->application_id);
            $program = $data['programs']->get($wish->admission_program_id);
            $profile = $data['profiles']->get($application->candidate_profile_id);
            if ($program === null || $program->admission_round_id !== $round->id || $profile === null
                || ! User::query()->whereKey($profile->user_id)->exists()
                || ! $data['majors']->has($program->major_id) || ! $data['methods']->has($program->admission_method_id)
                || $result->final_score !== $wish->calculated_score
                || ! in_array($result->getRawOriginal('decision'), ['admitted', 'not_admitted'], true)
                || ($result->getRawOriginal('decision') === 'admitted' && $wish->getRawOriginal('status') !== 'admitted')
                || ($result->getRawOriginal('decision') === 'not_admitted' && ! in_array($wish->getRawOriginal('status'), ['rejected', 'ineligible'], true))) {
                AdmissionEngineSnapshot::fail('Publication blocked: invalid result relationships.');
            }
        }
        $events = ActivityLog::query()->where('subject_type', $round->getMorphClass())->where('subject_id', $round->id)
            ->where('action', 'admission_results.published')->lockForUpdate()->get();
        $notifications = DatabaseNotification::query()->where('type', AdmissionResultsPublished::class)
            ->where('data->round_id', $round->id)->lockForUpdate()->get();
        if ($round->getRawOriginal('status') === 'processing') {
            if ($events->isNotEmpty() || $notifications->isNotEmpty() || $data['results']->contains(fn ($r) => $r->published_at !== null || $r->confirmed_at !== null)) {
                AdmissionEngineSnapshot::fail('Publication blocked: partially published state.');
            }
        } else {
            $event = $events->first()?->getAttribute('new_values');
            if ($events->count() !== 1 || ! is_array($event) || ($event['run_id'] ?? null) !== $summary['run_id']
                || ($event['round_id'] ?? null) !== $round->id
                || ($event['outcome_fingerprint'] ?? null) !== $summary['outcome_fingerprint']
                || ($event['results'] ?? null) !== $summary['results']
                || ($event['admitted'] ?? null) !== $summary['admitted']
                || ($event['not_admitted'] ?? null) !== $summary['not_admitted']
                || ! is_string($event['published_at'] ?? null)
                || $data['results']->contains(fn ($r) => $r->published_at?->format('Y-m-d H:i:s') !== $event['published_at']
                    || ($r->confirmed_at !== null && ($r->getRawOriginal('decision') !== 'admitted' || $r->confirmed_at->lt($r->published_at))))) {
                AdmissionEngineSnapshot::fail('Publication blocked: inconsistent publication history.');
            }
            if ($notifications->count() !== $applications->count()) {
                AdmissionEngineSnapshot::fail('Publication blocked: inconsistent notification history.');
            }
            foreach ($applications as $application) {
                $recipientId = $data['profiles']->get($application->candidate_profile_id)->user_id;
                if ($notifications->filter(fn ($notification) => ($notification->data['application_id'] ?? null) === $application->id
                    && (string) $notification->notifiable_id === (string) $recipientId
                    && $notification->notifiable_type === (new User)->getMorphClass())->count() !== 1) {
                    AdmissionEngineSnapshot::fail('Publication blocked: inconsistent notification recipients.');
                }
            }

            return $event;
        }

        return array_intersect_key($summary, array_flip(['round_id', 'run_id', 'outcome_fingerprint', 'results', 'admitted', 'not_admitted']));
    }
}
