<?php

namespace Database\Factories;

use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Major;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionProgram>
 */
class AdmissionProgramFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_round_id' => AdmissionRound::factory(),
            'major_id' => Major::factory(),
            'admission_method_id' => AdmissionMethod::factory(),
            'quota' => 100,
        ];
    }
}
