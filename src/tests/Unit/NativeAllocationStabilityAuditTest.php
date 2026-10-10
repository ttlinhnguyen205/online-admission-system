<?php

use App\Actions\NativeAllocationConstraints;
use App\Actions\NativeAllocationStabilityAudit;

/** @return array{binding_id: int, application_id: int, admission_program_id: int, offering_id: int, wish_priority: int, ranking_group: ?string, rank: ?int} */
function stabilityContract(int $binding, int $application, int $program, int $offering, int $wish, ?int $rank, ?string $group = 'approved-test-ranking-v1'): array
{
    return ['binding_id' => $binding, 'application_id' => $application, 'admission_program_id' => $program, 'offering_id' => $offering,
        'wish_priority' => $wish, 'ranking_group' => $group, 'rank' => $rank];
}

/**
 * @param  list<array{admission_program_id: int, quota: ?int}>  $limits
 * @return array{offering_id: int, total_quota: int, limits: list<array{admission_program_id: int, quota: ?int}>}
 */
function stabilityCapacity(int $offering, int $total, array $limits): array
{
    return ['offering_id' => $offering, 'total_quota' => $total, 'limits' => $limits];
}

function stabilityAuditor(): NativeAllocationStabilityAudit
{
    return new NativeAllocationStabilityAudit(new NativeAllocationConstraints);
}

test('higher admission count cannot justify pushing a higher ranked candidate down a wish', function () {
    $contracts = [stabilityContract(1, 1, 10, 100, 1, 1), stabilityContract(2, 1, 20, 200, 2, 2),
        stabilityContract(3, 2, 20, 200, 1, 1), stabilityContract(4, 2, 30, 300, 2, 1), stabilityContract(5, 3, 10, 100, 1, 2)];
    $capacities = [stabilityCapacity(100, 1, [['admission_program_id' => 10, 'quota' => 1]]),
        stabilityCapacity(200, 1, [['admission_program_id' => 20, 'quota' => 1]]), stabilityCapacity(300, 1, [['admission_program_id' => 30, 'quota' => 1]])];

    $moreAdmissions = stabilityAuditor()->audit([5, 2, 4], $contracts, $capacities);
    $firstChoices = stabilityAuditor()->audit([1, 3], $contracts, $capacities);

    expect($moreAdmissions['status'])->toBe('UNSTABLE');
    expect($moreAdmissions['blocking_pairs'])->toBe([['binding_id' => 1, 'displaced_binding_id' => 5], ['binding_id' => 3, 'displaced_binding_id' => 2]]);
    expect($firstChoices['blocking_pairs'])->toBe([]);
    expect($firstChoices['status'])->toBe('BLOCKED');
});

test('NV2 does not lose to NV1 when its candidate is higher ranked at that offering', function () {
    $contracts = [stabilityContract(1, 1, 10, 100, 1, 1), stabilityContract(2, 1, 20, 200, 2, 1), stabilityContract(3, 2, 20, 200, 1, 2)];
    $capacities = [stabilityCapacity(100, 0, [['admission_program_id' => 10, 'quota' => 0]]), stabilityCapacity(200, 1, [['admission_program_id' => 20, 'quota' => 1]])];

    expect(stabilityAuditor()->audit([3], $contracts, $capacities)['blocking_pairs'])->toBe([['binding_id' => 2, 'displaced_binding_id' => 3]]);
    expect(stabilityAuditor()->audit([2], $contracts, $capacities)['blocking_pairs'])->toBe([]);
});

test('a candidate admitted at a better wish causes no envy at a worse wish', function () {
    $contracts = [stabilityContract(1, 1, 10, 100, 1, 1), stabilityContract(2, 1, 20, 200, 2, 1), stabilityContract(3, 2, 20, 200, 1, 2)];
    $capacities = [stabilityCapacity(100, 1, [['admission_program_id' => 10, 'quota' => 1]]), stabilityCapacity(200, 1, [['admission_program_id' => 20, 'quota' => 1]])];

    expect(stabilityAuditor()->audit([1, 3], $contracts, $capacities)['blocking_pairs'])->toBe([]);
});

test('A B C remains ambiguous across methods without inventing a preferred binding', function () {
    $contracts = [stabilityContract(1, 1, 10, 100, 1, 1, 'THPT'), stabilityContract(2, 1, 20, 100, 1, 1, 'TRANSCRIPT'),
        stabilityContract(3, 2, 10, 100, 1, 2, 'THPT'), stabilityContract(4, 3, 20, 100, 1, 2, 'TRANSCRIPT')];
    $capacities = [stabilityCapacity(100, 2, [['admission_program_id' => 10, 'quota' => 1], ['admission_program_id' => 20, 'quota' => 1]])];

    $first = stabilityAuditor()->audit([1, 4], $contracts, $capacities);
    $second = stabilityAuditor()->audit([3, 2], $contracts, $capacities);

    expect($first['status'])->toBe('BLOCKED');
    expect($second['status'])->toBe('BLOCKED');
    expect($first['blocking_pairs'])->toBe([]);
    expect($second['blocking_pairs'])->toBe([]);
});

test('shared Q cannot be resolved by comparing ordinal ranks from different scales', function () {
    $contracts = [stabilityContract(1, 1, 10, 100, 1, 1, 'THPT'), stabilityContract(2, 2, 20, 100, 1, 1, 'HSA')];
    $capacities = [stabilityCapacity(100, 1, [['admission_program_id' => 10, 'quota' => 1], ['admission_program_id' => 20, 'quota' => 1]])];

    $audit = stabilityAuditor()->audit([1], $contracts, $capacities);

    expect($audit['status'])->toBe('BLOCKED');
    expect($audit['blocking_pairs'])->toBe([]);
    expect($audit['blockers'])->toContain('Q chung đã đầy: cần hàm chọn phối hợp phương thức được phê duyệt.');
});

test('boundary ties never become an implicit ID or input order tie break', function () {
    $contracts = [stabilityContract(9, 90, 10, 100, 1, 1), stabilityContract(1, 10, 10, 100, 1, 1)];
    $capacities = [stabilityCapacity(100, 1, [['admission_program_id' => 10, 'quota' => 1]])];

    $audit = stabilityAuditor()->audit([9], $contracts, $capacities);

    expect($audit)->toBe(stabilityAuditor()->audit([9], array_reverse($contracts), $capacities));
    expect($audit['blocking_pairs'])->toBe([]);
    expect($audit['blockers'])->toContain('Đồng hạng tại ranh giới chưa có tiêu chí phụ được phê duyệt.');
});

test('approved secondary order can reveal envy without changing the pinned rule', function () {
    $contracts = [stabilityContract(1, 1, 10, 100, 1, 1, 'approved-secondary-order'), stabilityContract(2, 2, 10, 100, 1, 2, 'approved-secondary-order')];
    $capacities = [stabilityCapacity(100, 1, [['admission_program_id' => 10, 'quota' => 1]])];

    expect(stabilityAuditor()->audit([2], $contracts, $capacities)['blocking_pairs'])->toBe([['binding_id' => 1, 'displaced_binding_id' => 2]]);
});

test('nonequivalent rule versions do not acquire a common ranking even in the same program', function () {
    $contracts = [stabilityContract(1, 1, 10, 100, 1, 1, 'rule-v1'), stabilityContract(2, 2, 10, 100, 1, 2, 'rule-v2')];
    $capacities = [stabilityCapacity(100, 1, [['admission_program_id' => 10, 'quota' => 1]])];

    $audit = stabilityAuditor()->audit([2], $contracts, $capacities);

    expect($audit['blocking_pairs'])->toBe([]);
    expect($audit['blockers'])->toContain('Chưa có chính sách thứ hạng hoặc tương đương rule versions để so sánh.');
});

test('absent ranking data cannot silently certify stability', function () {
    $contracts = [stabilityContract(1, 1, 10, 100, 1, null, null), stabilityContract(2, 2, 10, 100, 1, 1)];
    $capacities = [stabilityCapacity(100, 1, [['admission_program_id' => 10, 'quota' => 1]])];

    expect(stabilityAuditor()->audit([2], $contracts, $capacities)['status'])->toBe('BLOCKED');
});

test('strict priorities alone can leave two allocations with no same group blocking pair', function () {
    // A: X > Y; B: Y > X. X: B > A; Y: A > B.
    $contracts = [stabilityContract(1, 1, 10, 100, 1, 2), stabilityContract(2, 1, 20, 200, 2, 1),
        stabilityContract(3, 2, 20, 200, 1, 2), stabilityContract(4, 2, 10, 100, 2, 1)];
    $capacities = [stabilityCapacity(100, 1, [['admission_program_id' => 10, 'quota' => 1]]), stabilityCapacity(200, 1, [['admission_program_id' => 20, 'quota' => 1]])];

    expect(stabilityAuditor()->audit([1, 3], $contracts, $capacities)['blocking_pairs'])->toBe([]);
    expect(stabilityAuditor()->audit([2, 4], $contracts, $capacities)['blocking_pairs'])->toBe([]);
    expect(stabilityAuditor()->audit([2, 4], $contracts, $capacities)['status'])->toBe('BLOCKED');
});

test('vacancy is not a certificate and zero quota never permits a blocking replacement', function () {
    $contracts = [stabilityContract(1, 1, 10, 100, 1, 1)];

    expect(stabilityAuditor()->audit([], $contracts, [stabilityCapacity(100, 1, [['admission_program_id' => 10, 'quota' => 1]])])['status'])->toBe('BLOCKED');
    expect(stabilityAuditor()->audit([], $contracts, [stabilityCapacity(100, 0, [['admission_program_id' => 10, 'quota' => 0]])])['blocking_pairs'])->toBe([]);
});

test('infeasible proposals fail before any stability claim', function (array $assignment, int $total, int $quota, string $blocker) {
    $contracts = [stabilityContract(1, 1, 10, 100, 1, 1), stabilityContract(2, 2, 10, 100, 1, 2)];

    $audit = stabilityAuditor()->audit($assignment, $contracts, [stabilityCapacity(100, $total, [['admission_program_id' => 10, 'quota' => $quota]])]);

    expect($audit['status'])->toBe('INVALID');
    expect($audit['blockers'])->toContain($blocker);
})->with([
    'Q' => [[1, 2], 1, 2, 'Phân bổ vượt Q.'],
    'q' => [[1, 2], 2, 1, 'Phân bổ vượt q hoặc q chưa xác định.'],
    'duplicate candidate' => [[1, 1], 2, 2, 'Một hồ sơ không được trúng tuyển nhiều lần.'],
    'ineligible binding' => [[99], 1, 1, 'Binding trúng tuyển không thuộc tập đủ điều kiện.'],
]);

test('inconsistent preference or quota ownership fails closed', function () {
    $contracts = [stabilityContract(1, 1, 10, 100, 1, 1), stabilityContract(2, 1, 20, 200, 1, 1)];

    expect(stabilityAuditor()->audit([], $contracts, [])['blockers'])->toContain('Thứ tự nguyện vọng không nhất quán.');
    expect(stabilityAuditor()->audit([], [$contracts[0]], [stabilityCapacity(200, 1, [['admission_program_id' => 10, 'quota' => 1]])])['status'])->toBe('INVALID');
});

test('malformed contracts cannot establish merit or ownership', function (array $contracts, string $blocker) {
    $audit = stabilityAuditor()->audit([], $contracts, []);

    expect($audit['status'])->toBe('INVALID');
    expect($audit['blockers'])->toContain($blocker);
})->with([
    'duplicate binding' => [[stabilityContract(1, 1, 10, 100, 1, 1), stabilityContract(1, 2, 10, 100, 1, 2)], 'Contract hoặc thứ hạng đầu vào không hợp lệ.'],
    'invalid wish' => [[stabilityContract(1, 1, 10, 100, 0, 1)], 'Contract hoặc thứ hạng đầu vào không hợp lệ.'],
    'invalid rank' => [[stabilityContract(1, 1, 10, 100, 1, 0)], 'Contract hoặc thứ hạng đầu vào không hợp lệ.'],
    'program belongs to two offerings' => [[stabilityContract(1, 1, 10, 100, 1, 1), stabilityContract(2, 2, 10, 200, 1, 2)], 'Chương trình không thuộc đúng offering.'],
]);

test('overlapping offering scopes and missing quota on an eligible choice are blocked', function () {
    $contract = stabilityContract(1, 1, 10, 100, 1, 1);
    $capacity = stabilityCapacity(100, 1, [['admission_program_id' => 10, 'quota' => 1]]);

    expect(stabilityAuditor()->audit([], [$contract], [$capacity, $capacity])['status'])->toBe('INVALID');
    expect(stabilityAuditor()->audit([], [$contract], [])['blockers'])->toContain('Lựa chọn đủ điều kiện chưa có phạm vi Q/q.');
});
