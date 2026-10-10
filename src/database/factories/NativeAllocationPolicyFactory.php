<?php

namespace Database\Factories;

use App\Actions\NativeDeferredAcceptance;
use App\Models\AdmissionRound;
use App\Models\NativeAllocationPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NativeAllocationPolicy>
 */
class NativeAllocationPolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_round_id' => AdmissionRound::factory(),
            'version' => 1,
            'status' => 'draft',
            'payload' => ['algorithm' => NativeDeferredAcceptance::ALGORITHM, 'method_priority' => [], 'rule_equivalences' => [], 'ties' => 'block', 'policy_reference' => 'Unapproved fixture'],
            'content_hash' => str_repeat('0', 64),
            'created_by' => User::factory(),
        ];
    }
}
