<?php

namespace App\Actions;

use App\Models\AdmissionRound;
use App\Models\NativeAllocationPolicy;
use App\Models\NativeResultVersion;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class NativeAllocationRun
{
    public function __construct(private NativeDeferredAcceptance $engine, private NativeRoundScoringReport $reports, private NativeAllocationPolicyManagement $policies) {}

    /** @return array{decisions: list<array{application_id: int, binding_id: ?int, decision: string, reason: string}>, policy_reference: string} */
    public function preview(int $roundId): array
    {
        AdmissionEngineSnapshot::actor();

        return DB::transaction(function () use ($roundId): array {
            AdmissionCatalogLock::acquire();
            $this->ensure(Schema::hasTable('native_allocation_policies'), 'Chưa có bảng chính sách phối hợp.');
            $round = AdmissionRound::query()->lockForUpdate()->findOrFail($roundId);
            $this->ensure($round->nativeRegistrationState() === 'native_closed' && $round->getRawOriginal('status') === 'closed', 'Chỉ phân bổ đợt Native đã đóng.');
            $approved = NativeAllocationPolicy::query()->where('admission_round_id', $roundId)->where('status', 'approved')->lockForUpdate()->get();
            $this->ensure($approved->count() === 1, 'Đợt cần đúng một chính sách phối hợp approved.');
            $policy = $approved->sole();
            $payload = $policy->getAttribute('payload');
            $this->ensure($policy->approved_by !== null && $policy->approval_slot === 1 && $policy->approved_at !== null
                && $round->getRawOriginal('start_date') !== null && $policy->approved_at->lt($round->start_date)
                && hash_equals($policy->content_hash, NativeWishRegistration::hash([$roundId, $policy->version, $payload])), 'Chính sách chưa có phê duyệt hợp lệ trước kỳ tuyển sinh.');
            $this->policies->validatePayload($roundId, $payload);
            $report = $this->reports->build($roundId);
            $blockers = array_values(array_diff($report['blockers'], [NativeAllocationCertification::BLOCKER]));
            $this->ensure($blockers === [], implode(' ', $blockers));
            $quotas = DB::table('admission_quota_versions')->where('admission_round_id', $roundId)->where('status', 'approved')->get();
            $limits = DB::table('admission_quota_method_limits')->whereIn('admission_quota_version_id', $quotas->pluck('id'))->get();
            $programs = DB::table('admission_programs')->where('admission_round_id', $roundId)->get()->keyBy('id');
            $offerings = DB::table('candidate_major_offerings')->where('admission_round_id', $roundId)->pluck('major_id', 'id');
            $capacities = [];
            foreach ($quotas as $quota) {
                $quotaLimits = $limits->where('admission_quota_version_id', $quota->id)->sortBy('id')->map(fn ($l) => ['admission_program_id' => (int) $l->admission_program_id, 'quota' => $l->quota === null ? null : (int) $l->quota])->values()->all();
                $this->ensure($quota->approved_by !== null && $quota->approved_at !== null && hash_equals($quota->content_hash ?? '', NativeWishRegistration::hash(['total_quota' => (int) $quota->total_quota, 'catalog_fingerprint' => $quota->catalog_fingerprint, 'limits' => $quotaLimits])), 'Q/q không có phê duyệt/hash hợp lệ.');
                $offering = (int) $quota->candidate_major_offering_id;
                $this->ensure(! isset($capacities[$offering]), 'Offering có nhiều quota approved.');
                $methodLimits = [];
                foreach ($quotaLimits as $limit) {
                    $program = $programs->get($limit['admission_program_id']);
                    $this->ensure($program !== null && isset($offerings[$offering]) && $program->major_id === $offerings[$offering] && $limit['quota'] !== null, 'Chỉ tiêu chương trình sai phạm vi hoặc q chưa xác định.');
                    $method = (int) $program->admission_method_id;
                    $this->ensure(! isset($methodLimits[$method]), 'Một phương thức có nhiều pool chỉ tiêu.');
                    $methodLimits[$method] = $limit['quota'];
                }
                $capacities[$offering] = ['Q' => (int) $quota->total_quota, 'limits' => $methodLimits];
            }
            $bindingRows = DB::table('wish_method_bindings')->join('submission_wish_entries', 'submission_wish_entries.id', '=', 'wish_method_bindings.submission_wish_entry_id')
                ->whereIn('wish_method_bindings.id', array_column($report['rows'], 'binding_id'))
                ->select('wish_method_bindings.id', 'submission_wish_entries.candidate_major_offering_id')->get()->keyBy('id');
            $contracts = [];
            foreach ($report['rows'] as $row) {
                if ($row['status'] !== 'eligible') {
                    continue;
                }
                $binding = $bindingRows->get($row['binding_id']);
                $this->ensure($binding !== null && is_string($row['score']) && preg_match('/^\d+\.\d{3}$/D', $row['score']) === 1, 'Binding hoặc điểm không hợp lệ.');
                $contracts[] = ['application_id' => $row['application_id'], 'binding_id' => $row['binding_id'], 'offering_id' => (int) $binding->candidate_major_offering_id,
                    'method_id' => $row['method_id'], 'rule_id' => $row['rule_id'], 'priority' => $row['priority'], 'score' => (int) str_replace('.', '', $row['score'])];
            }
            try {
                $allocation = $this->engine->allocate($contracts, $capacities, $payload);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['allocation' => $exception->getMessage()]);
            }
            $applications = DB::table('applications')->where('admission_round_id', $roundId)->where('registration_mode', 'native')->whereNotNull('submitted_at')->pluck('id');
            $decisions = [];
            foreach ($applications as $id) {
                $bindingId = $allocation['bindings'][$id] ?? null;
                $decisions[] = ['application_id' => (int) $id, 'binding_id' => $bindingId, 'decision' => $bindingId === null ? 'not_admitted' : 'admitted', 'reason' => 'DA tối ưu nguyện vọng theo chính sách phối hợp đã phê duyệt; Q/q là trần cứng.'];
            }

            return ['decisions' => $decisions, 'policy_reference' => 'native-policy:'.$policy->id.':'.$policy->content_hash];
        }, 3);
    }

    public function run(int $roundId, NativeResultWorkflow $results): NativeResultVersion
    {
        AdmissionEngineSnapshot::actor();

        return DB::transaction(function () use ($roundId, $results): NativeResultVersion {
            AdmissionCatalogLock::acquire();
            $proposal = $this->preview($roundId);

            return $results->createDraft($roundId, $proposal['decisions'], NativeDeferredAcceptance::ALGORITHM, $proposal['policy_reference'], 'Native DA allocation v1');
        }, 3);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['allocation' => $message]);
        }
    }
}
