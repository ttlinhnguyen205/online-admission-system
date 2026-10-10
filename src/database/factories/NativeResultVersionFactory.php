<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\AdmissionRound;
use App\Models\NativeResultVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NativeResultVersion>
 */
class NativeResultVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_round_id' => AdmissionRound::factory()->state(['native_registration_state' => 'native_closed', 'status' => 'closed']),
            'version' => 1, 'status' => 'draft', 'algorithm_version' => 'test-fixture-only', 'policy_reference' => 'Isolated test fixture',
            'input_manifest' => [], 'input_hash' => hash('sha256', '[]'), 'content_hash' => hash('sha256', 'test-fixture-only'),
            'created_by' => User::factory()->state(['role' => UserRole::Admin]), 'reason' => 'Isolated test fixture',
        ];
    }
}
