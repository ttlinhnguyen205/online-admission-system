<?php

namespace App\Actions;

class NativeAllocationConstraints
{
    /**
     * Feasibility is necessary, never a ranking or optimality certificate.
     * Scores stay outside this boundary; methods do not acquire a common scale.
     *
     * @param  list<array{application_id: int, admission_program_id: int}>  $assignments
     * @param  list<array{total_quota: int, limits: list<array{admission_program_id: int, quota: ?int}>}>  $capacities
     * @return list<string>
     */
    public function blockers(array $assignments, array $capacities): array
    {
        $blockers = [];
        $applications = [];
        $programCounts = [];
        foreach ($assignments as $assignment) {
            if (isset($applications[$assignment['application_id']])) {
                $blockers[] = 'Một hồ sơ không được trúng tuyển nhiều lần.';
            }
            $applications[$assignment['application_id']] = true;
            $programId = $assignment['admission_program_id'];
            $programCounts[$programId] = ($programCounts[$programId] ?? 0) + 1;
        }
        $coveredPrograms = [];
        foreach ($capacities as $capacity) {
            $total = 0;
            foreach ($capacity['limits'] as $limit) {
                $programId = $limit['admission_program_id'];
                if (isset($coveredPrograms[$programId])) {
                    $blockers[] = 'Chương trình có nhiều phạm vi Q/q; cần xử lý xung đột.';
                }
                $coveredPrograms[$programId] = true;
                $count = $programCounts[$programId] ?? 0;
                $total += $count;
                if ($limit['quota'] === null || $limit['quota'] < 0 || $count > $limit['quota']) {
                    $blockers[] = 'Phân bổ vượt q hoặc q chưa xác định.';
                }
            }
            if ($capacity['total_quota'] < 0 || $total > $capacity['total_quota']) {
                $blockers[] = 'Phân bổ vượt Q.';
            }
        }
        foreach (array_keys($programCounts) as $programId) {
            if (! isset($coveredPrograms[$programId])) {
                $blockers[] = 'Chương trình trúng tuyển chưa có phạm vi Q/q.';
            }
        }

        return array_values(array_unique($blockers));
    }
}
