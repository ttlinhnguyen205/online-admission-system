<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationSubmissionSnapshot;
use App\Models\NativeResultEntry;
use App\Models\NativeResultVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NativeResultEntry>
 */
class NativeResultEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'native_result_version_id' => NativeResultVersion::factory(),
            'application_id' => fn (array $attributes) => Application::factory()->create(['registration_mode' => 'native',
                'admission_round_id' => NativeResultVersion::query()->whereKey($attributes['native_result_version_id'])->firstOrFail()->admission_round_id])->id,
            'submission_snapshot_id' => fn (array $attributes) => ApplicationSubmissionSnapshot::query()->create(['application_id' => $attributes['application_id'],
                'submission_version' => 1, 'registration_mode' => 'native', 'readiness' => 'native_ready', 'submitted_at' => now(), 'sealed_at' => now(),
                'catalog_fingerprint' => hash('sha256', 'fixture'), 'manifest' => [], 'content_hash' => hash('sha256', '[]')])->id,
            'wish_method_binding_id' => null, 'decision' => 'not_admitted', 'score' => null, 'payload' => [], 'reason' => 'Test fixture only',
        ];
    }
}
