<?php

namespace Database\Factories;

use App\Actions\NativeWishRegistration;
use App\Models\AdmissionMethod;
use App\Models\EvaluationRuleVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EvaluationRuleVersion> */
class EvaluationRuleVersionFactory extends Factory
{
    public function definition(): array
    {
        $payload = ['subjects' => ['MATH', 'PHYSICS', 'CHEMISTRY'], 'source_year' => 2026,
            'minimum_subject_score' => 0, 'minimum_total_score' => 0, 'policy_reference' => 'Test policy: sum three subjects'];

        return [
            'admission_method_id' => AdmissionMethod::factory(), 'template_identifier' => 'THPT_SCORE',
            'template_version' => 1, 'version' => 1, 'payload' => $payload,
            'ranking_contract' => 'thpt-three-subject-sum-rule-scoped', 'ranking_contract_version' => 1,
            'status' => 'draft', 'content_hash' => NativeWishRegistration::hash($payload),
        ];
    }
}
