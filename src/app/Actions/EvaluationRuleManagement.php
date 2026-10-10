<?php

namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\EvaluationRuleVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EvaluationRuleManagement
{
    public function __construct(private EvaluationTemplateRegistry $templates) {}

    /** @param array<string, mixed> $payload */
    public function createDraft(int $methodId, string $template, int $templateVersion, array $payload, string $reason, ?int $previousId = null): EvaluationRuleVersion
    {
        $this->authorize();
        $this->reason($reason);
        $definition = $this->templates->supported($template, $templateVersion);
        $payload = $this->templates->validate($template, $templateVersion, $payload);

        return DB::transaction(function () use ($methodId, $template, $templateVersion, $payload, $reason, $definition, $previousId): EvaluationRuleVersion {
            AdmissionCatalogLock::acquire();
            $method = AdmissionMethod::query()->lockForUpdate()->findOrFail($methodId);
            abort_unless($method->is_active, 422);
            if ($previousId !== null) {
                $previous = EvaluationRuleVersion::query()->where('admission_method_id', $methodId)->lockForUpdate()->findOrFail($previousId);
                if ($previous->status === 'draft') {
                    throw ValidationException::withMessages(['rules' => 'Chỉ tạo phiên bản mới từ lịch sử đã phê duyệt.']);
                }
            }
            $last = EvaluationRuleVersion::query()->where('admission_method_id', $methodId)->orderByDesc('version')->lockForUpdate()->first();
            $rule = EvaluationRuleVersion::query()->create([
                'admission_method_id' => $methodId, 'template_identifier' => $template, 'template_version' => $templateVersion,
                'version' => ($last->version ?? 0) + 1, 'payload' => $payload, 'status' => 'draft',
                'ranking_contract' => $definition['ranking_contract'], 'ranking_contract_version' => 1,
                'content_hash' => NativeWishRegistration::hash($payload),
            ]);
            $this->audit($rule, 'evaluation_rule.draft_created', [], ['reason' => $reason, 'previous_rule_id' => $previousId]);

            return $rule;
        }, 3);
    }

    public function newVersion(int $id, string $reason): EvaluationRuleVersion
    {
        $this->authorize();

        return DB::transaction(function () use ($id, $reason): EvaluationRuleVersion {
            $rule = $this->lock($id);

            return $this->createDraft($rule->admission_method_id, $rule->template_identifier, $rule->template_version, $rule->payload, $reason, $rule->id);
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    public function updateDraft(int $id, array $payload, string $reason): void
    {
        $this->authorize();
        $this->reason($reason);
        DB::transaction(function () use ($id, $payload, $reason): void {
            $rule = $this->lock($id);
            $this->requireDraft($rule);
            $old = $rule->getAttributes();
            $data = $this->templates->validate($rule->template_identifier, $rule->template_version, $payload);
            $rule->update(['payload' => $data, 'content_hash' => NativeWishRegistration::hash($data)]);
            $this->audit($rule, 'evaluation_rule.draft_updated', $old, ['reason' => $reason]);
        }, 3);
    }

    public function approve(int $id): void
    {
        $this->authorize();
        DB::transaction(function () use ($id): void {
            $rule = $this->lock($id);
            Gate::authorize('approve', $rule);
            $this->requireDraft($rule);
            $method = AdmissionMethod::query()->lockForUpdate()->findOrFail($rule->admission_method_id);
            abort_unless($method->is_active, 422);
            $template = $this->templates->supported($rule->template_identifier, $rule->template_version);
            if ($rule->ranking_contract !== $template['ranking_contract'] || $rule->ranking_contract_version !== 1) {
                throw ValidationException::withMessages(['rules' => 'Ranking contract không khớp template.']);
            }
            $payload = $this->templates->validate($rule->template_identifier, $rule->template_version, $rule->payload);
            $rule->update(['payload' => $payload, 'content_hash' => NativeWishRegistration::hash($payload),
                'status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()]);
            $this->audit($rule, 'evaluation_rule.approved');
        }, 3);
    }

    public function retire(int $id, string $reason): void
    {
        $this->authorize();
        $this->reason($reason);
        DB::transaction(function () use ($id, $reason): void {
            $rule = $this->lock($id);
            if ($rule->status !== 'approved') {
                throw ValidationException::withMessages(['rules' => 'Chỉ ngừng sử dụng phiên bản đã phê duyệt.']);
            }
            $rule->update(['status' => 'retired']);
            $this->audit($rule, 'evaluation_rule.retired', [], ['reason' => $reason]);
        }, 3);
    }

    public function bind(int $programId, int $ruleId, ?int $expectedRuleId, string $reason): void
    {
        $this->authorize();
        $this->reason($reason);
        DB::transaction(function () use ($programId, $ruleId, $expectedRuleId, $reason): void {
            AdmissionCatalogLock::acquire();
            $program = AdmissionProgram::query()->lockForUpdate()->findOrFail($programId);
            Gate::authorize('update', $program);
            $rule = EvaluationRuleVersion::query()->lockForUpdate()->findOrFail($ruleId);
            if ($program->evaluation_rule_version_id !== $expectedRuleId) {
                throw ValidationException::withMessages(['rules' => 'Rule của chương trình đã thay đổi. Hãy tải lại trước khi gắn.']);
            }
            if ($program->status !== 'active' || ! $program->major->is_active || ! $program->admissionMethod->is_active
                || ! $this->templates->validApproved($rule, $program->admission_method_id)) {
                throw ValidationException::withMessages(['rules' => 'Cần chương trình hợp lệ và rule đã duyệt, đúng phương thức, template/payload/hash hợp lệ.']);
            }
            $program->evaluationRule()->associate($rule);
            $program->save();
            $this->audit($program, 'evaluation_rule.program_bound', ['evaluation_rule_version_id' => $expectedRuleId],
                ['evaluation_rule_version_id' => $rule->id, 'reason' => $reason]);
        }, 3);
    }

    private function authorize(): void
    {
        Auth::user()?->refresh();
        Gate::authorize('viewAny', EvaluationRuleVersion::class);
    }

    private function lock(int $id): EvaluationRuleVersion
    {
        AdmissionCatalogLock::acquire();
        $rule = EvaluationRuleVersion::query()->lockForUpdate()->findOrFail($id);
        Gate::authorize('update', $rule);

        return $rule;
    }

    private function requireDraft(EvaluationRuleVersion $rule): void
    {
        if ($rule->status !== 'draft') {
            throw ValidationException::withMessages(['rules' => 'Chỉ được sửa hoặc phê duyệt bản nháp.']);
        }
    }

    private function reason(string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();
    }

    /** @param array<string, mixed> $old
     * @param  array<string, mixed>  $extra
     */
    private function audit(Model $subject, string $action, array $old = [], array $extra = []): void
    {
        ActivityLog::query()->create(['user_id' => Auth::id(), 'action' => $action, 'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(), 'old_values' => $old, 'new_values' => $extra + $subject->getAttributes(), 'created_at' => now()]);
    }
}
