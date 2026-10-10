<?php

namespace App\Actions;

class NativeAllocationStabilityAudit
{
    public function __construct(private NativeAllocationConstraints $constraints) {}

    /**
     * Audit necessary merit conditions, never select winners or certify stability.
     * Contracts must be eligible pinned bindings. Ordinal ranks and comparison
     * groups must come from approved ranking policies, not raw cross-method scores.
     * No production ranking-policy adapter exists yet; a clean audit stays BLOCKED.
     *
     * @param  list<int>  $assignedBindingIds
     * @param  list<array{binding_id: int, application_id: int, admission_program_id: int, offering_id: int, wish_priority: int, ranking_group: ?string, rank: ?int}>  $contracts
     * @param  list<array{offering_id: int, total_quota: int, limits: list<array{admission_program_id: int, quota: ?int}>}>  $capacities
     * @return array{status: string, blockers: list<string>, blocking_pairs: list<array{binding_id: int, displaced_binding_id: int}>}
     */
    public function audit(array $assignedBindingIds, array $contracts, array $capacities): array
    {
        $byBinding = [];
        $programOffering = [];
        $preferences = [];
        $blockers = [];
        foreach ($contracts as $contract) {
            if (isset($byBinding[$contract['binding_id']]) || $contract['wish_priority'] < 1 || ($contract['rank'] !== null && $contract['rank'] < 1)) {
                $blockers[] = 'Contract hoặc thứ hạng đầu vào không hợp lệ.';
            }
            $byBinding[$contract['binding_id']] = $contract;
            $programId = $contract['admission_program_id'];
            if (isset($programOffering[$programId]) && $programOffering[$programId] !== $contract['offering_id']) {
                $blockers[] = 'Chương trình không thuộc đúng offering.';
            }
            $programOffering[$programId] = $contract['offering_id'];
            $applicationId = $contract['application_id'];
            $priority = $contract['wish_priority'];
            $offeringId = $contract['offering_id'];
            if ((isset($preferences[$applicationId]['priorities'][$priority]) && $preferences[$applicationId]['priorities'][$priority] !== $offeringId)
                || (isset($preferences[$applicationId]['offerings'][$offeringId]) && $preferences[$applicationId]['offerings'][$offeringId] !== $priority)) {
                $blockers[] = 'Thứ tự nguyện vọng không nhất quán.';
            }
            $preferences[$applicationId]['priorities'][$priority] = $offeringId;
            $preferences[$applicationId]['offerings'][$offeringId] = $priority;
        }
        $capacityByOffering = [];
        $methodLimits = [];
        foreach ($capacities as $capacity) {
            if (isset($capacityByOffering[$capacity['offering_id']])) {
                $blockers[] = 'Offering có nhiều phạm vi Q.';
            }
            $capacityByOffering[$capacity['offering_id']] = $capacity;
            foreach ($capacity['limits'] as $limit) {
                $programId = $limit['admission_program_id'];
                if (isset($programOffering[$programId]) && $programOffering[$programId] !== $capacity['offering_id']) {
                    $blockers[] = 'Phạm vi chỉ tiêu không thuộc đúng offering.';
                }
                $methodLimits[$programId] = $limit['quota'];
            }
        }
        $assignments = [];
        $currentByApplication = [];
        $heldByProgram = [];
        $heldByOffering = [];
        foreach ($assignedBindingIds as $bindingId) {
            if (! isset($byBinding[$bindingId])) {
                $blockers[] = 'Binding trúng tuyển không thuộc tập đủ điều kiện.';

                continue;
            }
            $contract = $byBinding[$bindingId];
            $assignments[] = ['application_id' => $contract['application_id'], 'admission_program_id' => $contract['admission_program_id']];
            $currentByApplication[$contract['application_id']] = $contract;
            $heldByProgram[$contract['admission_program_id']][] = $contract;
            $heldByOffering[$contract['offering_id']][] = $contract;
        }
        $blockers = array_values(array_unique([...$blockers, ...$this->constraints->blockers($assignments, $capacities)]));
        if ($blockers !== []) {
            return ['status' => 'INVALID', 'blockers' => $blockers, 'blocking_pairs' => []];
        }

        $pairs = [];
        foreach ($contracts as $contract) {
            $current = $currentByApplication[$contract['application_id']] ?? null;
            if ($current !== null && $current['wish_priority'] <= $contract['wish_priority']) {
                continue;
            }
            $programId = $contract['admission_program_id'];
            $offeringId = $contract['offering_id'];
            $quota = $methodLimits[$programId] ?? null;
            $capacity = $capacityByOffering[$offeringId] ?? null;
            if ($quota === null || $capacity === null) {
                $blockers[] = 'Lựa chọn đủ điều kiện chưa có phạm vi Q/q.';

                continue;
            }
            if ($quota === 0 || $capacity['total_quota'] === 0) {
                continue;
            }
            foreach ($heldByProgram[$programId] ?? [] as $held) {
                if ($contract['ranking_group'] === null || $contract['ranking_group'] === '' || $contract['ranking_group'] !== $held['ranking_group'] || $contract['rank'] === null || $held['rank'] === null) {
                    $blockers[] = 'Chưa có chính sách thứ hạng hoặc tương đương rule versions để so sánh.';
                } elseif ($contract['rank'] < $held['rank']) {
                    $pairs[] = ['binding_id' => $contract['binding_id'], 'displaced_binding_id' => $held['binding_id']];
                } elseif ($contract['rank'] === $held['rank']) {
                    $blockers[] = 'Đồng hạng tại ranh giới chưa có tiêu chí phụ được phê duyệt.';
                }
            }
            if (count($heldByProgram[$programId] ?? []) < $quota) {
                $blockers[] = count($heldByOffering[$offeringId] ?? []) >= $capacity['total_quota']
                    ? 'Q chung đã đầy: cần hàm chọn phối hợp phương thức được phê duyệt.'
                    : 'Cần hàm chọn được phê duyệt để đánh giá lựa chọn còn suất.';
            }
        }
        if ($pairs !== []) {
            $blockers[] = 'Thứ hạng bị vi phạm tại lựa chọn thí sinh ưu tiên hơn.';
        }
        $blockers[] = NativeAllocationCertification::BLOCKER;

        return ['status' => $pairs === [] ? 'BLOCKED' : 'UNSTABLE', 'blockers' => array_values(array_unique($blockers)), 'blocking_pairs' => $pairs];
    }
}
