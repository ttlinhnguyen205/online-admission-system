<?php

namespace App\Actions;

use App\Models\EvaluationRuleVersion;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EvaluationTemplateRegistry
{
    /** @return array<string, array{name: string, version: int, source: string, supported: bool, reason: ?string, ranking_contract: string, schema: list<string>}> */
    public function templates(): array
    {
        $schema = ['subjects', 'source_year', 'minimum_subject_score', 'minimum_total_score', 'policy_reference'];
        $templates = [
            'THPT_SCORE' => ['name' => 'THPT — tổng ba môn, hệ số 1', 'version' => 1, 'source' => 'candidate_exam_results(thpt) + candidate_exam_subject_scores', 'supported' => true, 'reason' => null, 'ranking_contract' => 'thpt-three-subject-sum-rule-scoped', 'schema' => $schema],
            'TRANSCRIPT_SCORE' => ['name' => 'Học bạ — tổng ba môn của một lớp, hệ số 1', 'version' => 1, 'source' => 'candidate_transcripts + candidate_transcript_scores', 'supported' => true, 'reason' => null, 'ranking_contract' => 'transcript-three-subject-sum-rule-scoped', 'schema' => [...$schema, 'grade_level']],
            'APTITUDE_SCORE' => ['name' => 'Đánh giá năng lực / tư duy', 'version' => 1, 'source' => 'candidate_exam_results', 'supported' => false, 'reason' => 'Nguồn dgnl chưa phân biệt HSA/V-ACT; chưa có định danh kỳ thi, thang điểm và chính sách được xác nhận. Không chuyển đổi DGNL legacy.', 'ranking_contract' => '', 'schema' => []],
            'CERTIFICATE_CONDITION' => ['name' => 'Điều kiện chứng chỉ', 'version' => 1, 'source' => 'candidate_certificates', 'supported' => false, 'reason' => 'Chưa có chính sách ngưỡng, hiệu lực và hợp đồng xếp hạng cho chứng chỉ; không quy đổi IELTS/TOEIC/SAT.', 'ranking_contract' => '', 'schema' => []],
        ];
        foreach ($templates as $identifier => &$template) {
            if ($template['supported'] && ! in_array($template['version'], config('admission_registration.approved_templates.'.$identifier, []), true)) {
                $template['supported'] = false;
                $template['reason'] = 'Template đã bị tắt trong allowlist cấu hình.';
            }
        }
        unset($template);

        return $templates;
    }

    /** @return array{name: string, version: int, source: string, supported: bool, reason: ?string, ranking_contract: string, schema: list<string>} */
    public function supported(string $identifier, int $version): array
    {
        $template = $this->templates()[$identifier] ?? null;
        if ($template === null || ! $template['supported'] || $template['version'] !== $version
            || ! in_array($version, config('admission_registration.approved_templates.'.$identifier, []), true)) {
            throw ValidationException::withMessages(['rules' => $template['reason'] ?? 'Template hoặc phiên bản chưa được hỗ trợ.']);
        }

        return $template;
    }

    /** @return array<string, mixed> */
    public function validationRules(string $identifier, int $version): array
    {
        $template = $this->supported($identifier, $version);
        $rules = [
            'payload' => ['required', 'array:'.implode(',', $template['schema'])],
            'payload.subjects' => ['required', 'array', 'list', 'size:3'],
            'payload.subjects.*' => ['required', 'string', 'distinct', Rule::in(array_keys(config('admission_data.subjects', [])))],
            'payload.source_year' => ['required', 'integer', 'between:2000,2100'],
            'payload.minimum_subject_score' => ['required', 'numeric', 'between:0,10'],
            'payload.minimum_total_score' => ['required', 'numeric', 'between:0,30'],
            'payload.policy_reference' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
        ];
        if ($identifier === 'TRANSCRIPT_SCORE') {
            $rules['payload.grade_level'] = ['required', 'integer', Rule::in([10, 11, 12])];
        }

        return $rules;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validate(string $identifier, int $version, array $payload): array
    {
        $data = Validator::make(['payload' => $payload], $this->validationRules($identifier, $version))->validate()['payload'];
        sort($data['subjects']);
        $data['source_year'] = (int) $data['source_year'];
        if (isset($data['grade_level'])) {
            $data['grade_level'] = (int) $data['grade_level'];
        }
        $data['minimum_subject_score'] = (float) $data['minimum_subject_score'];
        $data['minimum_total_score'] = (float) $data['minimum_total_score'];
        $data['policy_reference'] = trim($data['policy_reference']);
        ksort($data);

        return $data;
    }

    public function validApproved(?EvaluationRuleVersion $rule, int $methodId): bool
    {
        if ($rule === null || $rule->status !== 'approved' || $rule->approved_by === null || $rule->approved_at === null
            || $rule->admission_method_id !== $methodId) {
            return false;
        }
        $payload = $rule->getAttribute('payload');
        if (! is_array($payload)) {
            return false;
        }
        try {
            $template = $this->supported($rule->template_identifier, $rule->template_version);
            $this->validate($rule->template_identifier, $rule->template_version, $payload);

            return $rule->ranking_contract === $template['ranking_contract'] && $rule->ranking_contract_version === 1
                && hash_equals($rule->content_hash, NativeWishRegistration::hash($payload));
        } catch (ValidationException) {
            return false;
        }
    }

    /** Comparison is restricted to the exact approved version, never across scales or versions. */
    public function comparisonScope(EvaluationRuleVersion $rule): string
    {
        abort_unless($this->validApproved($rule, $rule->admission_method_id), 422);

        return $rule->ranking_contract.':1:rule:'.$rule->id.':'.$rule->content_hash;
    }
}
