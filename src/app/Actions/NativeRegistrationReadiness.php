<?php

namespace App\Actions;

use App\Models\AdmissionProgram;
use App\Models\CandidateMajorOffering;
use Illuminate\Database\Eloquent\Collection;

class NativeRegistrationReadiness
{
    public function __construct(private AdmissionQuotaManagement $quotas, private EvaluationTemplateRegistry $templates) {}

    /** @param Collection<int, AdmissionProgram>|null $catalog
     * @return array{status: string, total: int, approved: int, missing: int, unsupported: int, methods: list<array{program_id: int, name: string, ready: bool, reason: string}>}
     */
    public function check(CandidateMajorOffering $offering, ?Collection $catalog = null): array
    {
        $programs = $catalog === null ? $this->quotas->acceptedPrograms($offering)->load('evaluationRule')
            : $catalog->filter(fn (AdmissionProgram $program): bool => $program->admission_round_id === $offering->admission_round_id
                && $program->major_id === $offering->major_id && $program->status === 'active' && $program->admissionMethod->is_active);
        $methods = [];
        $approved = 0;
        $unsupported = 0;
        foreach ($programs as $program) {
            $ready = $this->programReady($program);
            $approved += (int) $ready;
            $rule = $program->evaluationRule;
            $definition = $rule === null ? null : ($this->templates->templates()[$rule->template_identifier] ?? null);
            $isUnsupported = $rule !== null && ($definition === null || ! $definition['supported'] || $definition['version'] !== $rule->template_version);
            $unsupported += (int) $isUnsupported;
            $methods[] = ['program_id' => $program->id, 'name' => $program->admissionMethod->name, 'ready' => $ready,
                'reason' => $ready ? 'Rule đã duyệt hợp lệ' : ($isUnsupported ? ($definition['reason'] ?? 'Template hoặc phiên bản không được hỗ trợ') : 'Thiếu rule đã duyệt hợp lệ (phương thức/template/payload/ranking/hash).')];
        }
        $total = $programs->count();

        return ['status' => $total > 0 && $approved === $total ? 'READY' : 'NOT READY', 'total' => $total,
            'approved' => $approved, 'missing' => $total - $approved, 'unsupported' => $unsupported, 'methods' => $methods];
    }

    public function programReady(AdmissionProgram $program): bool
    {
        return $this->templates->validApproved($program->evaluationRule, $program->admission_method_id);
    }
}
