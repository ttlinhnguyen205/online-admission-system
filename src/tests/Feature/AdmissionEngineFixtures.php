<?php

use App\Actions\ProcessAdmissionRound;
use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\CandidateScore;

function engineProgram(?AdmissionRound $round = null, string $code = 'DEMO-DGNL', int $quota = 1): AdmissionProgram
{
    $round ??= AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Closed, 'year' => 2026]);
    $configuration = match ($code) {
        'DEMO-THPT-A00' => ['weights' => ['MATH' => 1, 'PHYSICS' => 1, 'CHEMISTRY' => 1]],
        'DEMO-HB-D01' => ['weights' => ['MATH' => 1, 'LITERATURE' => 1, 'ENG' => 2]],
        default => null,
    };
    $method = AdmissionMethod::query()->where('code', $code)->first()
        ?? AdmissionMethod::factory()->create(['code' => $code, 'score_config' => $configuration]);

    return AdmissionProgram::factory()->for($round)->for($method)->create(['quota' => $quota]);
}

function engineApplication(AdmissionProgram $program, string $score = '8.000'): Application
{
    $application = Application::factory()->create([
        'admission_round_id' => $program->admission_round_id, 'status' => ApplicationStatus::Verified,
        'submitted_at' => now()->subDay(), 'reviewed_at' => now(),
    ]);
    AdmissionWish::factory()->for($application)->for($program)->create();
    $descriptor = config('admission_engine.methods.'.$program->admissionMethod->code);
    foreach ($descriptor['subjects'] ?? [null] as $subject) {
        CandidateScore::factory()->create([
            'candidate_profile_id' => $application->candidate_profile_id,
            'score_type' => $descriptor['score_type'] ?? 'dgnl', 'subject_code' => $subject,
            'score' => $score, 'exam_year' => 2026, 'verified' => true,
        ]);
    }

    return $application;
}

/** @return array<string, mixed> */
function engineRun(AdmissionProgram $program): array
{
    $engine = app(ProcessAdmissionRound::class);

    return $engine->process($program->admission_round_id, $engine->preview($program->admission_round_id)['fingerprint']);
}
