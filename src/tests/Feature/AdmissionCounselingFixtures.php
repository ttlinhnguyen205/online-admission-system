<?php

use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\CandidateMajorOffering;
use App\Support\AdmissionCounselingProvider;

function counselingOffering(): CandidateMajorOffering
{
    $round = AdmissionRound::factory()->create([
        'status' => 'open', 'start_date' => now()->subHour(), 'end_date' => now()->addHour(),
    ]);
    $program = AdmissionProgram::factory()->for($round)->create();

    return CandidateMajorOffering::factory()->for($program)->create();
}

/** @return array<string, mixed> */
function counselingPlan(string $intent = 'majors', array $sources = [], array $fields = [], array $choices = []): array
{
    return ['intent' => $intent, 'template' => $intent, 'sources' => $sources, 'fields' => $fields, 'choices' => $choices];
}

class FakeAdmissionCounselingProvider implements AdmissionCounselingProvider
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    public function __construct(public ?Closure $callback = null) {}

    public function plan(string $question, array $context, array $history): array
    {
        $this->requests[] = compact('question', 'context', 'history');
        if ($this->callback !== null) {
            return ($this->callback)($question, $context, $history);
        }

        return counselingPlan('majors', array_column(array_values(array_filter($context['facts'], fn (array $fact): bool => $fact['kind'] === 'offering')), 'ref'));
    }
}
