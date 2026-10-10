<?php

namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\EvaluationRuleVersion;
use App\Models\NativeAllocationPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class NativeAllocationPolicyManagement
{
    /** @param array{algorithm: string, method_priority: list<int>, rule_equivalences: list<list<int>>, ties: string, policy_reference: string} $payload */
    public function createDraft(int $roundId, array $payload): NativeAllocationPolicy
    {
        $actor = AdmissionEngineSnapshot::actor();
        $this->ensure(Schema::hasTable('native_allocation_policies'), 'Chưa chạy migration chính sách phối hợp.');

        return DB::transaction(function () use ($roundId, $payload, $actor): NativeAllocationPolicy {
            AdmissionCatalogLock::acquire();
            AdmissionRound::query()->lockForUpdate()->findOrFail($roundId);
            $this->validatePayload($roundId, $payload);
            $existing = NativeAllocationPolicy::query()->where('admission_round_id', $roundId)->get()->first(fn ($p) => $p->getAttribute('payload') === $payload);
            if ($existing !== null) {
                return $existing;
            }
            $version = (NativeAllocationPolicy::query()->where('admission_round_id', $roundId)->max('version') ?? 0) + 1;
            $policy = NativeAllocationPolicy::query()->create(['admission_round_id' => $roundId, 'version' => $version, 'payload' => $payload,
                'content_hash' => NativeWishRegistration::hash([$roundId, $version, $payload]), 'created_by' => $actor->id, 'status' => 'draft']);
            $this->audit($policy, 'native_allocation.policy_drafted');

            return $policy;
        }, 3);
    }

    public function approve(int $id, string $expectedHash): void
    {
        $actor = AdmissionEngineSnapshot::actor();
        DB::transaction(function () use ($id, $expectedHash, $actor): void {
            AdmissionCatalogLock::acquire();
            $policy = NativeAllocationPolicy::query()->lockForUpdate()->findOrFail($id);
            $this->ensure(hash_equals($policy->content_hash, $expectedHash), 'Chính sách đã thay đổi.');
            $round = AdmissionRound::query()->lockForUpdate()->findOrFail($policy->admission_round_id);
            $this->validatePayload($round->id, $policy->getAttribute('payload'));
            $this->ensure($round->getRawOriginal('start_date') !== null && now()->lt($round->start_date) && ! Application::query()->where('admission_round_id', $round->id)->exists(), 'Phải phê duyệt trước kỳ tuyển sinh và trước khi có hồ sơ.');
            $this->ensure(! NativeAllocationPolicy::query()->where('admission_round_id', $round->id)->where('status', 'approved')->whereKeyNot($id)->exists(), 'Đợt đã có chính sách được phê duyệt.');
            if ($policy->status === 'approved') {
                return;
            }
            $this->ensure($policy->status === 'draft', 'Chỉ phê duyệt draft.');
            $policy->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'approval_slot' => 1]);
            $this->audit($policy, 'native_allocation.policy_approved');
        }, 3);
    }

    /** @param array{algorithm: string, method_priority: list<int>, rule_equivalences: list<list<int>>, ties: string, policy_reference: string} $payload */
    public function validatePayload(int $roundId, array $payload): void
    {
        Validator::make(['payload' => $payload], ['payload' => ['required', 'array:algorithm,method_priority,rule_equivalences,policy_reference,ties'],
            'payload.algorithm' => ['required', 'in:student-da-matroid-v1'], 'payload.ties' => ['required', 'in:block'],
            'payload.policy_reference' => ['required', 'string', 'max:1000'], 'payload.method_priority' => ['required', 'array', 'list', 'min:1'],
            'payload.method_priority.*' => ['required', 'integer', 'distinct'], 'payload.rule_equivalences' => ['present', 'array', 'list'],
            'payload.rule_equivalences.*' => ['array', 'list', 'min:2'], 'payload.rule_equivalences.*.*' => ['integer', 'distinct']])->validate();
        $methods = AdmissionProgram::query()->where('admission_round_id', $roundId)->where('status', 'active')->pluck('admission_method_id')->unique()->sort()->values()->all();
        $declared = $payload['method_priority'];
        sort($declared);
        $this->ensure($declared === $methods, 'Thứ tự phương thức phải bao phủ đúng catalog active của đợt.');
        $seen = [];
        foreach ($payload['rule_equivalences'] as $group) {
            $signature = null;
            foreach ($group as $id) {
                $rule = EvaluationRuleVersion::query()->findOrFail($id);
                $this->ensure(! isset($seen[$id]) && in_array($rule->admission_method_id, $methods, true) && in_array($rule->status, ['approved', 'retired'], true) && $rule->approved_by !== null && $rule->approved_at !== null, 'Rule tương đương phải có phê duyệt hợp lệ, đúng phương thức và không trùng nhóm.');
                $this->ensure(hash_equals($rule->content_hash, NativeWishRegistration::hash($rule->getAttribute('payload'))), 'Rule tương đương không toàn vẹn.');
                $seen[$id] = true;
                $value = NativeWishRegistration::hash($rule->only(['admission_method_id', 'template_identifier', 'template_version', 'payload', 'ranking_contract', 'ranking_contract_version']));
                $this->ensure($signature === null || $signature === $value, 'V1 chỉ hỗ trợ tương đương các rule có cùng nội dung và ranking contract; không quy đổi điểm.');
                $signature = $value;
            }
        }
    }

    private function audit(NativeAllocationPolicy $policy, string $action): void
    {
        ActivityLog::query()->create(['user_id' => auth()->id(), 'action' => $action, 'subject_type' => $policy->getMorphClass(), 'subject_id' => $policy->id, 'new_values' => $policy->only(['admission_round_id', 'version', 'content_hash', 'status']), 'created_at' => now()]);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['allocation' => $message]);
        }
    }
}
