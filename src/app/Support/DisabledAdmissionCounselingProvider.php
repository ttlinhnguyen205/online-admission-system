<?php

namespace App\Support;

class DisabledAdmissionCounselingProvider implements AdmissionCounselingProvider
{
    public function plan(string $question, array $context, array $history): array
    {
        throw new AdmissionCounselingFailure('disabled');
    }
}
