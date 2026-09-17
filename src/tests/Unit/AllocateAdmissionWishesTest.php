<?php

use App\Actions\AllocateAdmissionWishes;

/** @return array{id: int, application_id: int, program_id: int, priority: int, score: int, eligible: bool} */
function allocationWish(int $id, int $candidate, int $program, int $priority, int $score, bool $eligible = true): array
{
    return ['id' => $id, 'application_id' => $candidate, 'program_id' => $program, 'priority' => $priority, 'score' => $score, 'eligible' => $eligible];
}

test('allocation handles zero insufficient exact and excess total capacity', function (int $quota, array $expected) {
    $wishes = [allocationWish(1, 1, 1, 1, 28000), allocationWish(2, 2, 1, 1, 27000)];

    $result = (new AllocateAdmissionWishes)->allocate($wishes, [1 => $quota]);

    expect($result['admitted'])->toBe($expected);
    expect($result['ranks'])->toBe([1 => 1, 2 => 2]);
})->with([[0, []], [1, [1]], [2, [1, 2]], [20, [1, 2]]]);

test('competition ranks include all eligible wishes within their own program', function () {
    $wishes = [
        allocationWish(1, 1, 1, 1, 28000), allocationWish(2, 2, 1, 1, 27000),
        allocationWish(3, 3, 1, 1, 27000), allocationWish(4, 4, 1, 1, 26500),
        allocationWish(5, 5, 1, 1, 25000, false), allocationWish(6, 6, 2, 1, 900000),
    ];

    $result = (new AllocateAdmissionWishes)->allocate($wishes, [1 => 4, 2 => 1]);

    expect($result['ranks'])->toBe([1 => 1, 2 => 2, 3 => 2, 4 => 4, 6 => 1]);
    expect($result['admitted'])->toBe([1, 2, 3, 4, 6]);
});

test('ties wholly retained or wholly rejected are allowed', function (int $quota, array $expected) {
    $wishes = [allocationWish(1, 1, 1, 1, 28000), allocationWish(2, 2, 1, 1, 27000), allocationWish(3, 3, 1, 1, 27000)];

    expect((new AllocateAdmissionWishes)->allocate($wishes, [1 => $quota])['admitted'])->toBe($expected);
})->with([[0, []], [1, [1]], [3, [1, 2, 3]], [4, [1, 2, 3]]]);

test('contested academic ties block regardless of technical iteration and IDs', function (bool $reverse) {
    $wishes = [allocationWish(90, 1, 1, 1, 27500), allocationWish(2, 2, 1, 1, 27000), allocationWish(1, 3, 1, 1, 27000)];
    if ($reverse) {
        $wishes = array_reverse($wishes);
    }

    expect(fn () => (new AllocateAdmissionWishes)->allocate($wishes, [1 => 2]))->toThrow(DomainException::class, 'Equal-score tie at quota boundary');
})->with([false, true]);

test('highest eligible preference wins and lower preferences do not consume capacity', function () {
    $wishes = [
        allocationWish(1, 1, 1, 1, 10000, false), allocationWish(2, 1, 2, 2, 20000),
        allocationWish(3, 1, 3, 3, 30000), allocationWish(4, 2, 3, 1, 10000),
    ];

    $result = (new AllocateAdmissionWishes)->allocate($wishes, [1 => 1, 2 => 1, 3 => 1]);

    expect($result['admitted'])->toBe([2, 4]);
    expect($result['ranks'])->not->toHaveKey(1);
    expect($result['ranks'][4])->toBe(2);
});

test('displacement cascades through fallback preferences and fills available places', function () {
    $wishes = [
        allocationWish(1, 1, 1, 1, 20000), allocationWish(2, 1, 2, 2, 20000), allocationWish(3, 1, 3, 3, 20000),
        allocationWish(4, 2, 2, 1, 10000), allocationWish(5, 2, 3, 2, 10000),
        allocationWish(6, 3, 4, 1, 30000), allocationWish(7, 3, 1, 2, 30000),
    ];

    $result = (new AllocateAdmissionWishes)->allocate($wishes, [1 => 1, 2 => 1, 3 => 1, 4 => 0]);

    expect($result['admitted'])->toBe([2, 5, 7]);
});

test('a later proposal tying an incumbent at the boundary blocks', function () {
    $wishes = [
        allocationWish(1, 1, 1, 1, 27000), allocationWish(2, 2, 2, 1, 27000),
        allocationWish(3, 2, 1, 2, 27000), allocationWish(4, 3, 2, 1, 28000),
    ];

    expect(fn () => (new AllocateAdmissionWishes)->allocate($wishes, [1 => 1, 2 => 1]))
        ->toThrow(DomainException::class, 'Equal-score tie at quota boundary');
});

test('unused capacity in another program never transfers', function () {
    $wishes = [allocationWish(1, 1, 1, 1, 28000), allocationWish(2, 2, 1, 1, 27000)];

    expect((new AllocateAdmissionWishes)->allocate($wishes, [1 => 1, 2 => 99])['admitted'])->toBe([1]);
});

test('all ineligible wishes produce no ranking or admission', function () {
    expect((new AllocateAdmissionWishes)->allocate([allocationWish(1, 1, 1, 1, 8000, false)], [1 => 5]))
        ->toBe(['admitted' => [], 'ranks' => []]);
});
