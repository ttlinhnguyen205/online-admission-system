<?php

namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\AdmissionQuotaVersion;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\ApplicationSubmissionSnapshot;
use App\Models\NativeAllocationPolicy;
use App\Models\NativeMethodEvaluation;
use App\Models\NativeResultVersion;
use App\Notifications\AdmissionResultsPublished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class NativeResultWorkflow
{
    public function __construct(private NativeRoundScoringReport $reports, private NativeAllocationCertification $certification, private NativeAllocationConstraints $constraints) {}

    /**
     * Store a proposal, never a certified admission decision. Not exposed as a manual
     * winner-entry form; the future verified allocator supplies this artifact.
     *
     * @param  list<array{application_id: int, binding_id: ?int, decision: string, reason: string}>  $decisions
     */
    public function createDraft(int $roundId, array $decisions, string $algorithm, string $policy, string $reason): NativeResultVersion
    {
        $actor = AdmissionEngineSnapshot::actor();
        $this->ensure(Schema::hasTable('native_result_versions'), 'Migration kết quả Native chưa được áp dụng.');
        Validator::make(compact('decisions', 'algorithm', 'policy', 'reason'), [
            'algorithm' => ['required', 'string', 'max:80'], 'policy' => ['required', 'string', 'max:2000'], 'reason' => ['required', 'string', 'max:2000'],
            'decisions' => ['required', 'array', 'list'], 'decisions.*' => ['required', 'array:application_id,binding_id,decision,reason'],
            'decisions.*.application_id' => ['required', 'integer', 'distinct'], 'decisions.*.binding_id' => ['nullable', 'integer'],
            'decisions.*.decision' => ['required', 'in:admitted,not_admitted'], 'decisions.*.reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($roundId, $decisions, $algorithm, $policy, $reason, $actor): NativeResultVersion {
            AdmissionCatalogLock::acquire();
            $round = AdmissionRound::query()->lockForUpdate()->findOrFail($roundId);
            $this->ensure($round->nativeRegistrationState() !== 'legacy' && $round->getRawOriginal('status') !== 'published', 'Không tạo kết quả Native cho đợt legacy/đã công bố.');
            $input = $this->inputs($roundId);
            $snapshots = ApplicationSubmissionSnapshot::query()->whereIn('application_id', array_column($input['snapshots'], 'application_id'))
                ->orderByDesc('submission_version')->get()->unique('application_id')->keyBy('application_id');
            $ids = array_column($decisions, 'application_id');
            sort($ids);
            $expected = $snapshots->keys()->sort()->values()->all();
            $this->ensure($ids !== [] && $ids === $expected, 'Đề xuất phải có đúng một quyết định cho mỗi hồ sơ Native đã nộp trong đợt.');
            $entries = [];
            foreach (collect($decisions)->sortBy('application_id') as $decision) {
                $snapshot = $snapshots->get($decision['application_id']);
                $this->ensure($snapshot !== null && $snapshot->sealed_at !== null, 'Cần snapshot đã niêm phong.');
                $row = null;
                foreach ($input['report']['rows'] as $candidateRow) {
                    if ($candidateRow['binding_id'] === $decision['binding_id'] && $candidateRow['application_id'] === $decision['application_id']) {
                        $row = $candidateRow;
                        break;
                    }
                }
                if ($decision['decision'] === 'admitted') {
                    $this->ensure($row !== null && $row['status'] === 'eligible', 'Binding được đề xuất trúng tuyển phải thuộc đúng hồ sơ và đủ điều kiện.');
                } else {
                    $this->ensure($decision['binding_id'] === null, 'Không gắn phương thức trúng tuyển cho quyết định không trúng tuyển.');
                }
                $entries[] = ['application_id' => $decision['application_id'], 'submission_snapshot_id' => $snapshot->id,
                    'wish_method_binding_id' => $decision['binding_id'], 'decision' => $decision['decision'], 'score' => $row['score'] ?? null,
                    'payload' => $row === null ? [] : $snapshot->entries()->whereHas('bindings', fn ($q) => $q->whereKey($row['binding_id']))->firstOrFail()->getAttribute('payload'),
                    'reason' => $decision['reason']];
            }
            $inputHash = NativeWishRegistration::hash($input);
            $hash = NativeWishRegistration::hash([$algorithm, $policy, $reason, $inputHash, $entries]);
            $existing = NativeResultVersion::query()->where('admission_round_id', $roundId)->where('content_hash', $hash)->first();
            if ($existing !== null) {
                return $existing;
            }
            $version = NativeResultVersion::query()->create(['admission_round_id' => $roundId,
                'version' => (NativeResultVersion::query()->where('admission_round_id', $roundId)->max('version') ?? 0) + 1,
                'status' => 'draft', 'algorithm_version' => $algorithm, 'policy_reference' => $policy, 'reason' => $reason,
                'input_manifest' => $input, 'input_hash' => $inputHash, 'content_hash' => $hash, 'created_by' => $actor->id]);
            foreach ($entries as $entry) {
                $version->entries()->create($entry);
            }
            $version->update(['sealed_at' => now()]);
            $this->audit($version, 'native_results.draft_created');

            return $version;
        }, 3);
    }

    /** @return list<string> */
    public function check(int $id): array
    {
        AdmissionEngineSnapshot::actor();
        $this->ensure(Schema::hasTable('native_result_versions'), 'Migration kết quả Native chưa được áp dụng.');

        return DB::transaction(function () use ($id): array {
            AdmissionCatalogLock::acquire();
            $version = NativeResultVersion::query()->lockForUpdate()->findOrFail($id);
            if ($version->status === 'published') {
                $this->integrity($version);

                return [];
            }

            return $this->validate($version);
        }, 3);
    }

    public function approve(int $id, string $expectedHash): void
    {
        $this->transition($id, $expectedHash, 'approved');
    }

    public function publish(int $id, string $expectedHash): void
    {
        $this->transition($id, $expectedHash, 'published');
    }

    public function reject(int $id, string $expectedHash, string $rejectionReason): void
    {
        AdmissionEngineSnapshot::actor();
        $rejectionReason = trim($rejectionReason);
        Validator::make(compact('rejectionReason'), ['rejectionReason' => ['required', 'string', 'max:2000']])->validate();
        $this->transition($id, $expectedHash, 'rejected', $rejectionReason);
    }

    private function transition(int $id, string $expectedHash, string $target, ?string $rejectionReason = null): void
    {
        $actor = AdmissionEngineSnapshot::actor();
        $this->ensure(Schema::hasTable('native_result_versions'), 'Migration kết quả Native chưa được áp dụng.');
        DB::transaction(function () use ($id, $expectedHash, $target, $actor, $rejectionReason): void {
            AdmissionCatalogLock::acquire();
            $version = NativeResultVersion::query()->lockForUpdate()->findOrFail($id);
            $this->ensure(hash_equals($version->content_hash, $expectedHash), 'Phiên bản đã thay đổi; kiểm tra lại.');
            $this->integrity($version);
            if ($version->status === $target) {
                return;
            }
            $this->ensure($version->status === ($target === 'published' ? 'approved' : 'draft'), 'Trạng thái phiên bản không cho phép thao tác này.');
            if ($target !== 'rejected') {
                $this->ensure($target !== 'published' || ($version->getAttribute('approved_by') !== null && $version->getAttribute('approved_at') !== null), 'Cần phê duyệt đúng phiên bản trước công bố.');
                $blockers = $this->validate($version);
                $this->ensure($blockers === [], implode(' ', $blockers));
            }
            if ($target === 'published') {
                $this->ensure(! NativeResultVersion::query()->where('admission_round_id', $version->admission_round_id)->where('status', 'published')->exists(), 'Đợt đã có kết quả công bố; không tự thay thế lịch sử.');
            }
            $extra = match ($target) {
                'rejected' => ['rejection_reason' => $rejectionReason], 'published' => ['publication_slot' => 1], default => []
            };
            $version->update(['status' => $target, $target.'_by' => $actor->id, $target.'_at' => now(), ...$extra]);
            if ($target === 'published') {
                $round = $version->admissionRound()->firstOrFail();
                $round->update(['status' => 'published']);
                foreach ($version->entries()->orderBy('application_id')->get() as $entry) {
                    $application = Application::query()->findOrFail($entry->application_id);
                    $application->update(['status' => 'completed']);
                    $recipient = $application->candidateProfile->user;
                    $recipient->notify(new AdmissionResultsPublished($round->id, $round->name, $application->id));
                }
            }
            $this->audit($version, 'native_results.'.$target);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function inputs(int $roundId): array
    {
        $report = $this->reports->build($roundId);
        $applications = DB::table('applications')->where('admission_round_id', $roundId)->where('registration_mode', 'native')->whereNotNull('submitted_at')->pluck('id');
        $snapshots = ApplicationSubmissionSnapshot::query()->whereIn('application_id', $applications)->orderBy('id')->get()
            ->map->only(['id', 'application_id', 'submission_version', 'content_hash', 'catalog_fingerprint'])->all();
        $evaluations = NativeMethodEvaluation::query()->whereIn('wish_method_binding_id', array_column($report['rows'], 'binding_id'))->orderBy('id')->get()
            ->map->only(['wish_method_binding_id', 'evaluation_rule_version_id', 'algorithm_version', 'input_fingerprint', 'status', 'score'])->all();
        $quotas = AdmissionQuotaVersion::query()->where('admission_round_id', $roundId)->where('status', 'approved')->with('limits')->orderBy('id')->get()
            ->map(fn ($quota) => ['id' => $quota->id, 'offering_id' => $quota->candidate_major_offering_id, 'total_quota' => $quota->total_quota,
                'approved_by' => $quota->approved_by, 'approved_at' => $quota->getRawOriginal('approved_at'), 'catalog_fingerprint' => $quota->catalog_fingerprint,
                'content_hash' => $quota->content_hash, 'limits' => $quota->limits->sortBy('id')->map->only(['admission_program_id', 'quota'])->values()->all()])->all();

        $allocationPolicies = Schema::hasTable('native_allocation_policies') ? NativeAllocationPolicy::query()->where('admission_round_id', $roundId)->where('status', 'approved')->orderBy('id')->get()->map->only(['id', 'version', 'payload', 'content_hash', 'approved_by', 'approved_at'])->all() : [];

        return [...compact('report', 'snapshots', 'evaluations', 'quotas'), 'allocation_policies' => $allocationPolicies];
    }

    /** @return list<string> */
    private function validate(NativeResultVersion $version): array
    {
        $this->integrity($version);
        $current = $this->inputs($version->admission_round_id);
        $blockers = array_values(array_diff($current['report']['blockers'], [NativeAllocationCertification::BLOCKER]));
        if (! hash_equals($version->input_hash, NativeWishRegistration::hash($current))) {
            $blockers[] = 'Đầu vào đã stale; cần phiên bản kết quả mới.';
        }
        $winners = $version->entries()->where('decision', 'admitted')->get();
        $applicationIds = $version->entries()->orderBy('application_id')->pluck('application_id')->all();
        $expected = array_values(array_unique(array_column($current['snapshots'], 'application_id')));
        sort($expected);
        if ($applicationIds !== $expected) {
            $blockers[] = 'Kết quả không bao phủ đúng tập hồ sơ Native đã nộp.';
        }
        foreach ($current['quotas'] as $quota) {
            $hash = hash('sha256', json_encode(['total_quota' => $quota['total_quota'], 'catalog_fingerprint' => $quota['catalog_fingerprint'], 'limits' => $quota['limits']], JSON_THROW_ON_ERROR));
            if ($quota['approved_by'] === null || $quota['approved_at'] === null || ! is_string($quota['content_hash']) || ! hash_equals($quota['content_hash'], $hash)) {
                $blockers[] = 'Phiên bản Q/q chưa có phê duyệt hợp lệ hoặc hash không toàn vẹn.';
            }
        }
        $bindings = DB::table('wish_method_bindings')->whereIn('id', $winners->pluck('wish_method_binding_id'))->pluck('admission_program_id', 'id');
        $assignments = [];
        foreach ($winners as $winner) {
            $programId = $bindings->get($winner->wish_method_binding_id);
            if ($programId === null) {
                $blockers[] = 'Binding trúng tuyển không tồn tại.';

                continue;
            }
            $assignments[] = ['application_id' => $winner->application_id, 'admission_program_id' => (int) $programId];
        }
        $blockers = [...$blockers, ...$this->constraints->blockers($assignments, $current['quotas'])];

        return array_values(array_unique([...$blockers, ...$this->certification->blockers($version)]));
    }

    private function integrity(NativeResultVersion $version): void
    {
        $entries = $version->entries()->orderBy('application_id')->get()->map->only(['application_id', 'submission_snapshot_id', 'wish_method_binding_id', 'decision', 'score', 'payload', 'reason'])->all();
        $this->ensure($version->sealed_at !== null && hash_equals($version->input_hash, NativeWishRegistration::hash($version->getAttribute('input_manifest')))
            && hash_equals($version->content_hash, NativeWishRegistration::hash([$version->algorithm_version, $version->policy_reference, $version->reason, $version->input_hash, $entries])), 'Phiên bản kết quả không toàn vẹn.');
    }

    private function audit(NativeResultVersion $version, string $action): void
    {
        ActivityLog::query()->create(['user_id' => auth()->id(), 'action' => $action, 'subject_type' => $version->getMorphClass(), 'subject_id' => $version->id,
            'new_values' => $version->only(['admission_round_id', 'version', 'content_hash', 'input_hash', 'status', 'rejection_reason']), 'created_at' => now()]);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['nativeResults' => $message]);
        }
    }
}
