<?php

namespace Database\Factories;

use App\Models\AdmissionProgram;
use App\Models\AdmissionWish;
use App\Models\Application;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionWish>
 */
class AdmissionWishFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'admission_program_id' => fn (array $attributes): int => AdmissionProgram::factory()->create([
                'admission_round_id' => Application::query()->where('id', $attributes['application_id'])->sole()->admission_round_id,
            ])->id,
            'priority' => 1,
        ];
    }
}
