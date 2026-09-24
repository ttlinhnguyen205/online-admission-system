<?php

namespace Database\Factories;

use App\Models\AdmissionProgram;
use App\Models\CandidateMajorOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CandidateMajorOffering> */
class CandidateMajorOfferingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'admission_program_id' => AdmissionProgram::factory(),
            'admission_round_id' => fn (array $attributes): int => AdmissionProgram::query()->whereKey($attributes['admission_program_id'])->sole()->admission_round_id,
            'major_id' => fn (array $attributes): int => AdmissionProgram::query()->whereKey($attributes['admission_program_id'])->sole()->major_id,
            'is_selectable' => true,
        ];
    }
}
