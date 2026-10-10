<?php

use App\Actions\NativeAllocationCertification;
use App\Actions\NativeAllocationConstraints;
use App\Actions\NativeAllocationRun;
use App\Models\NativeResultVersion;

test('A B C has two feasible allocations and neither is certified without policy', function () {
    $capacities = [['total_quota' => 2, 'limits' => [
        ['admission_program_id' => 10, 'quota' => 1],
        ['admission_program_id' => 20, 'quota' => 1],
    ]]];
    // A qualifies in both methods; B only in THPT; C only in transcript.
    $first = [['application_id' => 1, 'admission_program_id' => 10], ['application_id' => 3, 'admission_program_id' => 20]];
    $second = [['application_id' => 2, 'admission_program_id' => 10], ['application_id' => 1, 'admission_program_id' => 20]];
    $constraints = new NativeAllocationConstraints;

    expect($constraints->blockers($first, $capacities))->toBe([]);
    expect($constraints->blockers($second, $capacities))->toBe([]);
    $run = Mockery::mock(NativeAllocationRun::class);
    $run->shouldNotReceive('preview');
    expect((new NativeAllocationCertification($run))->blockers(new NativeResultVersion))->not->toBeEmpty();
});

test('multiple wishes can trade two first choices for three admissions without violating capacities', function () {
    $capacities = array_map(fn (int $program) => ['total_quota' => 1, 'limits' => [['admission_program_id' => $program, 'quota' => 1]]], [10, 20, 30]);
    // A: X > Y; B: Y > Z; C: X only. No objective selects a winner here.
    $firstChoices = [['application_id' => 1, 'admission_program_id' => 10], ['application_id' => 2, 'admission_program_id' => 20]];
    $moreAdmissions = [['application_id' => 3, 'admission_program_id' => 10], ['application_id' => 1, 'admission_program_id' => 20], ['application_id' => 2, 'admission_program_id' => 30]];
    $constraints = new NativeAllocationConstraints;

    expect($constraints->blockers($firstChoices, $capacities))->toBe([]);
    expect($constraints->blockers($moreAdmissions, $capacities))->toBe([]);
    expect($constraints->blockers(array_reverse($moreAdmissions), array_reverse($capacities)))->toBe([]);
});

test('hard method ceilings do not borrow unused capacity from another method', function () {
    $capacities = [['total_quota' => 3, 'limits' => [['admission_program_id' => 10, 'quota' => 1], ['admission_program_id' => 20, 'quota' => 2]]]];
    $assignments = [['application_id' => 1, 'admission_program_id' => 10], ['application_id' => 2, 'admission_program_id' => 10]];

    expect((new NativeAllocationConstraints)->blockers($assignments, $capacities))->toBe(['Phân bổ vượt q hoặc q chưa xác định.']);
});

test('major ceiling stays hard when method capacities together exceed Q', function () {
    $capacities = [['total_quota' => 1, 'limits' => [['admission_program_id' => 10, 'quota' => 1], ['admission_program_id' => 20, 'quota' => 1]]]];
    $assignments = [['application_id' => 1, 'admission_program_id' => 10], ['application_id' => 2, 'admission_program_id' => 20]];

    expect((new NativeAllocationConstraints)->blockers($assignments, $capacities))->toBe(['Phân bổ vượt Q.']);
});

test('one candidate cannot take two method seats or two wishes', function () {
    $capacities = [['total_quota' => 2, 'limits' => [['admission_program_id' => 10, 'quota' => 1], ['admission_program_id' => 20, 'quota' => 1]]]];
    $assignments = [['application_id' => 1, 'admission_program_id' => 10], ['application_id' => 1, 'admission_program_id' => 20]];

    expect((new NativeAllocationConstraints)->blockers($assignments, $capacities))->toBe(['Một hồ sơ không được trúng tuyển nhiều lần.']);
});

test('a winning program outside approved quota scope fails closed', function () {
    expect((new NativeAllocationConstraints)->blockers([['application_id' => 1, 'admission_program_id' => 10]], []))
        ->toBe(['Chương trình trúng tuyển chưa có phạm vi Q/q.']);
});

test('overlapping quota scopes cannot silently create separate capacity pools', function () {
    $capacity = ['total_quota' => 1, 'limits' => [['admission_program_id' => 10, 'quota' => 1]]];

    expect((new NativeAllocationConstraints)->blockers([], [$capacity, $capacity]))
        ->toBe(['Chương trình có nhiều phạm vi Q/q; cần xử lý xung đột.']);
});

test('unknown or negative method quotas block even an empty proposal', function (?int $quota) {
    expect((new NativeAllocationConstraints)->blockers([], [['total_quota' => 1, 'limits' => [['admission_program_id' => 10, 'quota' => $quota]]]]))
        ->toBe(['Phân bổ vượt q hoặc q chưa xác định.']);
})->with([null, -1]);

test('zero quota permits no admissions and negative total quota is invalid', function () {
    $constraints = new NativeAllocationConstraints;

    expect($constraints->blockers([], [['total_quota' => 0, 'limits' => [['admission_program_id' => 10, 'quota' => 0]]]]))->toBe([]);
    expect($constraints->blockers([], [['total_quota' => -1, 'limits' => []]]))->toBe(['Phân bổ vượt Q.']);
});
