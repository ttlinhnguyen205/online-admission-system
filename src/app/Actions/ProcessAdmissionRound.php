<?php

namespace App\Actions;

use App\Enums\AdmissionDecision;
use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Enums\WishStatus;
use App\Models\AdmissionResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ProcessAdmissionRound
{
    public function __construct(
        private AdmissionEngineSnapshot $snapshots,
        private CalculateAdmissionWishScore $calculator,
        private AllocateAdmissionWishes $allocator,
        private RecordAdmissionEngineActivity $audit,
    ) {}

    /** @return array<string, mixed> */
    public function preview(int $roundId): array
    {
        $data = $this->snapshots->load($roundId);

        return $this->inspect($data)['preview'];
    }

    /** @return array<string, mixed> */
    public function process(int $roundId, string $expected): array
    {
        return DB::transaction(function () use ($roundId, $expected): array {
            $data = $this->snapshots->load($roundId, true);
            $analysis = $this->inspect($data);
            $preview = $analysis['preview'];
            if ($preview['blockers'] !== []) {
                AdmissionEngineSnapshot::fail(implode(' ', $preview['blockers']));
            }
            if ($preview['completed']) {
                return $preview['summary'];
            }
            if (! hash_equals($preview['fingerprint'], $expected)) {
                AdmissionEngineSnapshot::fail('The processing inputs changed. Preview the round again before confirming.');
            }
            Gate::authorize('create', AdmissionResult::class);
            $time = now(config('app.timezone'));
            $summary = [
                'run_id' => (string) Str::ulid(), 'round_id' => $roundId,
                'engine_version' => config('admission_engine.version'),
                'algorithm_version' => config('admission_engine.algorithm_version'),
                'scoring_version' => config('admission_engine.scoring_version'),
                'input_fingerprint' => $preview['fingerprint'],
                'included' => $preview['verified'], 'excluded_drafts' => $preview['drafts'],
                'wishes' => count($analysis['decisions']), 'results' => count($analysis['decisions']),
                'admitted' => count($analysis['allocation']['admitted']),
                'not_admitted' => count($analysis['decisions']) - count($analysis['allocation']['admitted']),
                'programs' => $preview['programs'], 'decided_at' => $time->format('Y-m-d H:i:s'),
            ];
            $this->audit->record($data['actor'], $data['round'], 'admission_engine.started', $summary);
            $this->save($data['round'], ['status' => AdmissionRoundStatus::Processing]);
            $applications = $data['applications']->filter(fn ($a) => $a->getRawOriginal('status') === 'verified');
            foreach ($applications as $application) {
                $this->save($application, ['status' => ApplicationStatus::Processing]);
            }
            $admitted = array_fill_keys($analysis['allocation']['admitted'], true);
            foreach ($analysis['decisions'] as $decision) {
                $selected = isset($admitted[$decision['id']]);
                $score = CalculateAdmissionWishScore::format($decision['score']);
                $wish = $data['wishes']->get($decision['id']);
                $this->save($wish, [
                    'calculated_score' => $score,
                    'status' => $selected ? WishStatus::Admitted : ($decision['eligible'] ? WishStatus::Rejected : WishStatus::Ineligible),
                ]);
                $this->save(new AdmissionResult, [
                    'admission_wish_id' => $decision['id'], 'final_score' => $score,
                    'rank' => $analysis['allocation']['ranks'][$decision['id']] ?? null,
                    'decision' => $selected ? AdmissionDecision::Admitted : AdmissionDecision::NotAdmitted,
                    'decided_at' => $time, 'published_at' => null, 'confirmed_at' => null,
                ]);
            }
            foreach ($applications as $application) {
                $this->save($application, ['status' => ApplicationStatus::Completed]);
            }
            $summary['outcome_fingerprint'] = $this->snapshots->outcomeFingerprint($this->snapshots->load($roundId, true));
            $this->audit->record($data['actor'], $data['round'], 'admission_engine.completed', $summary);

            return $summary;
        }, 3);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function inspect(array $data): array
    {
        $round = $data['round'];
        $counts = $data['applications']->countBy(fn ($a) => $a->getRawOriginal('status'));
        $verified = $data['applications']->filter(fn ($a) => $a->getRawOriginal('status') === 'verified');
        $wishes = $data['wishes']->whereIn('application_id', $verified->modelKeys());
        $preview = [
            'round_id' => $round->getKey(), 'round_status' => $round->getRawOriginal('status'),
            'verified' => $verified->count(), 'drafts' => $counts->get('draft', 0),
            'submitted' => $counts->get('submitted', 0), 'under_review' => $counts->get('under_review', 0),
            'needs_revision' => $counts->get('needs_revision', 0), 'wishes' => $wishes->count(),
            'existing_results' => $data['results']->count(), 'existing_events' => $data['logs']->count(),
            'programs' => [], 'blockers' => [], 'completed' => false, 'summary' => null,
            'fingerprint' => $this->snapshots->fingerprint($data),
        ];
        $blockers = [];
        $decisions = [];
        $allocation = ['admitted' => [], 'ranks' => []];
        $result = function () use (&$preview, &$blockers, &$decisions, &$allocation): array {
            $preview['blockers'] = array_values(array_unique($blockers));

            return compact('preview', 'decisions', 'allocation');
        };
        if ($data['results']->contains(fn ($r) => $r->getAttribute('published_at') !== null || $r->getAttribute('confirmed_at') !== null)) {
            $blockers[] = 'Published or confirmed historical results cannot be processed.';
        }
        if ($data['logs']->isNotEmpty() || $data['results']->isNotEmpty() || in_array($preview['round_status'], ['processing', 'published'], true)) {
            $started = $data['logs']->where('action', 'admission_engine.started');
            $completed = $data['logs']->where('action', 'admission_engine.completed');
            $summary = $completed->first()?->getAttribute('new_values');
            $start = $started->first()?->getAttribute('new_values');
            $completedInput = is_array($summary) ? $summary : [];
            unset($completedInput['outcome_fingerprint']);
            $consistent = $preview['round_status'] === 'processing' && $started->count() === 1 && $completed->count() === 1
                && is_array($summary) && is_array($start) && is_string($summary['run_id'] ?? null)
                && $start === $completedInput && is_array($summary['programs'] ?? null)
                && ($start['run_id'] ?? null) === $summary['run_id'] && ($summary['round_id'] ?? null) === $round->getKey()
                && ($summary['results'] ?? 0) > 0 && $summary['results'] === $data['results']->count()
                && is_string($summary['outcome_fingerprint'] ?? null)
                && hash_equals($summary['outcome_fingerprint'], $this->snapshots->outcomeFingerprint($data));
            if (! $consistent) {
                $blockers[] = 'Inconsistent or historical processing state. Existing decisions cannot be replaced.';
            } elseif ($blockers === []) {
                $preview['completed'] = true;
                $preview['summary'] = $summary;
                $preview['programs'] = $summary['programs'];
            }

            return $result();
        }
        if ($preview['round_status'] !== 'closed') {
            $blockers[] = 'Only a closed round can begin processing. Dates never automatically close a round.';
        }
        foreach (['submitted', 'under_review', 'needs_revision'] as $state) {
            if ($preview[$state] > 0) {
                $blockers[] = 'Unresolved '.$state.' applications: '.$preview[$state].'.';
            }
        }
        if ($verified->isEmpty()) {
            $blockers[] = 'No verified applications are ready for processing.';
        }
        if ($data['applications']->contains(fn ($a) => ! in_array($a->getRawOriginal('status'), ['draft', 'submitted', 'under_review', 'needs_revision', 'verified'], true))) {
            $blockers[] = 'Inconsistent application processing state.';
        }
        $byApplication = $wishes->groupBy('application_id');
        $scoreIndex = $this->calculator->index($data['scores']->map->only([
            'candidate_profile_id', 'score_type', 'subject_code', 'exam_year', 'verified', 'score',
        ]), config('admission_engine.score_type_aliases'));
        $seenProfiles = [];
        foreach ($verified as $application) {
            $profileId = $application->getAttribute('candidate_profile_id');
            if (! $data['profiles']->has($profileId) || isset($seenProfiles[$profileId]) || $application->getAttribute('admission_round_id') !== $round->getKey()) {
                $blockers[] = 'Application #'.$application->getKey().' has inconsistent profile or round relationships.';
            }
            $seenProfiles[$profileId] = true;
            $own = $byApplication->get($application->getKey(), collect());
            if ($own->isEmpty() || $own->sortBy('priority')->pluck('priority')->values()->all() !== range(1, $own->count())
                || $own->pluck('admission_program_id')->unique()->count() !== $own->count()) {
                $blockers[] = 'Application #'.$application->getKey().' requires nonempty, unique, contiguous wishes.';
            }
        }
        $quotas = $calculated = [];
        foreach ($wishes as $wish) {
            try {
                $program = $data['programs']->get($wish->getAttribute('admission_program_id'));
                $method = $program === null ? null : $data['methods']->get($program->getAttribute('admission_method_id'));
                if ($program === null || $method === null || ! $data['majors']->has($program->getAttribute('major_id'))
                    || $program->getAttribute('admission_round_id') !== $round->getKey()) {
                    throw new \DomainException('Missing or cross-round program, major or method relationship.');
                }
                if ($wish->getRawOriginal('status') !== 'pending' || $wish->getAttribute('calculated_score') !== null) {
                    throw new \DomainException('Inconsistent preexisting wish processing state.');
                }
                $descriptor = config('admission_engine.methods')[$method->getAttribute('code')] ?? ['type' => 'unsupported'];
                $quota = $program->getAttribute('quota');
                if (! is_int($quota) || $quota < 0 || $quota > 4294967295) {
                    throw new \DomainException('Invalid configured quota.');
                }
                $preview['programs'][$program->getKey()] = [
                    'id' => $program->getKey(), 'method' => $method->getAttribute('code'),
                    'type' => $descriptor['type'] ?? 'unsupported', 'quota' => $quota,
                    'minimum_score' => $program->getAttribute('minimum_score'),
                ];
                $application = $verified->get($wish->getAttribute('application_id'));
                $profile = $application->getAttribute('candidate_profile_id');
                $key = $profile.':'.$method->getKey();
                $score = $calculated[$key] ??= $this->calculator->calculate($descriptor, $method->getAttribute('score_config'),
                    $scoreIndex[$profile][$descriptor['score_type'] ?? ''][$round->getAttribute('year')] ?? []);
                $minimum = $program->getAttribute('minimum_score');
                $decisions[] = ['id' => $wish->getKey(), 'application_id' => $application->getKey(),
                    'program_id' => $program->getKey(), 'priority' => $wish->getAttribute('priority'),
                    'score' => $score, 'eligible' => $minimum === null || $score >= CalculateAdmissionWishScore::decimal($minimum)];
                $quotas[$program->getKey()] = $quota;
            } catch (\DomainException $exception) {
                $blockers[] = 'Wish #'.$wish->getKey().': '.$exception->getMessage();
            }
        }
        if ($blockers === []) {
            try {
                $allocation = $this->allocator->allocate($decisions, $quotas);
            } catch (\DomainException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }

        return $result();
    }

    /** @param array<string, mixed> $attributes */
    private function save(Model $model, array $attributes): void
    {
        $model->fill($attributes);
        if (! $model->save()) {
            AdmissionEngineSnapshot::fail('A processing record could not be saved. No decisions were saved.');
        }
    }
}
