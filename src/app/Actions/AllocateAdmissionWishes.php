<?php

namespace App\Actions;

class AllocateAdmissionWishes
{
    /**
     * IDs order iteration only. A score group split by capacity always blocks.
     * Every proposal advances a candidate's cursor exactly once.
     *
     * @param  list<array{id: int, application_id: int, program_id: int, priority: int, score: int, eligible: bool}>  $wishes
     * @param  array<int, int>  $quotas
     * @return array{admitted: list<int>, ranks: array<int, int>}
     */
    public function allocate(array $wishes, array $quotas): array
    {
        $preferences = $programs = $ranks = [];
        foreach ($wishes as $wish) {
            if ($wish['eligible']) {
                $preferences[$wish['application_id']][] = $wish;
                $programs[$wish['program_id']][] = $wish;
            }
        }
        foreach ($programs as $pool) {
            usort($pool, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $previous = null;
            $rank = 0;
            foreach ($pool as $index => $wish) {
                if ($wish['score'] !== $previous) {
                    $rank = $index + 1;
                }
                $ranks[$wish['id']] = $rank;
                $previous = $wish['score'];
            }
        }
        foreach ($preferences as &$list) {
            usort($list, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);
        }
        unset($list);
        ksort($preferences);
        $pending = array_keys($preferences);
        $cursors = $holders = [];
        while ($pending !== []) {
            $proposals = [];
            foreach ($pending as $candidate) {
                $cursor = $cursors[$candidate] ?? 0;
                $wish = $preferences[$candidate][$cursor] ?? null;
                if ($wish !== null) {
                    $cursors[$candidate] = $cursor + 1;
                    $proposals[$wish['program_id']][] = $wish;
                }
            }
            $pending = [];
            ksort($proposals);
            foreach ($proposals as $program => $new) {
                $pool = [...($holders[$program] ?? []), ...$new];
                usort($pool, fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($a['id'] <=> $b['id']));
                $quota = $quotas[$program];
                if ($quota > 0 && count($pool) > $quota && $pool[$quota - 1]['score'] === $pool[$quota]['score']) {
                    throw new \DomainException('Equal-score tie at quota boundary requires an approved tie-break policy. Program #'.$program.'.');
                }
                $holders[$program] = array_slice($pool, 0, $quota);
                foreach (array_slice($pool, $quota) as $rejected) {
                    $pending[] = $rejected['application_id'];
                }
            }
            sort($pending);
        }
        $admitted = [];
        foreach ($holders as $pool) {
            foreach ($pool as $wish) {
                $admitted[] = $wish['id'];
            }
        }
        sort($admitted);

        return compact('admitted', 'ranks');
    }
}
