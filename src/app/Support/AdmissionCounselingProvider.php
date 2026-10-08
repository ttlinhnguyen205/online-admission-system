<?php

namespace App\Support;

interface AdmissionCounselingProvider
{
    /** @param array<string, mixed> $context
     * @param  list<array<string, mixed>>  $history
     * @return array<string, mixed>
     */
    public function plan(string $question, array $context, array $history): array;
}
