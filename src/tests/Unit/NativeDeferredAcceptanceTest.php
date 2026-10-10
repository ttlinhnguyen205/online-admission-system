<?php

use App\Actions\NativeDeferredAcceptance;

function daPolicy(array $methods = [10, 20], array $equivalences = []): array
{
    return ['algorithm' => NativeDeferredAcceptance::ALGORITHM, 'method_priority' => $methods, 'rule_equivalences' => $equivalences, 'ties' => 'block', 'policy_reference' => 'Approved isolated test policy'];
}

function daContract(int $binding, int $app, int $offering, int $method, int $score, int $priority = 1, int $rule = 1): array
{
    return ['binding_id' => $binding, 'application_id' => $app, 'offering_id' => $offering, 'method_id' => $method, 'rule_id' => $rule, 'priority' => $priority, 'score' => $score];
}

function bruteBindingFeasibility(array $students, array $applicants, array $limits): bool
{
    if ($students === []) {
        return true;
    }
    $app = array_shift($students);
    foreach ($applicants[$app] as $method => $contract) {
        if (($limits[$method] ?? 0) > 0) {
            $remaining = $limits;
            $remaining[$method]--;
            if (bruteBindingFeasibility($students, $applicants, $remaining)) {
                return true;
            }
        }
    }

    return false;
}

test('A B C reassigns A to transcript to keep B without demoting any wish', function () {
    $contracts = [daContract(1, 1, 100, 10, 29000), daContract(2, 1, 100, 20, 29000), daContract(3, 2, 100, 10, 28000), daContract(4, 3, 100, 20, 28000)];

    $result = (new NativeDeferredAcceptance)->allocate($contracts, [100 => ['Q' => 2, 'limits' => [10 => 1, 20 => 1]]], daPolicy());

    expect($result['bindings'])->toBe([1 => 2, 2 => 3]);
    expect($result['priorities'])->toBe([100 => [1, 2, 3]]);
    expect((new NativeDeferredAcceptance)->allocate(array_reverse($contracts), [100 => ['Q' => 2, 'limits' => [20 => 1, 10 => 1]]], daPolicy()))->toBe($result);
});

test('shared Q uses approved precedence rather than comparing scales across methods', function () {
    $contracts = [daContract(1, 1, 100, 10, 18000), daContract(2, 2, 100, 20, 30000)];
    $capacities = [100 => ['Q' => 1, 'limits' => [10 => 1, 20 => 1]]];

    expect((new NativeDeferredAcceptance)->allocate($contracts, $capacities, daPolicy())['bindings'])->toBe([1 => 1]);
    expect((new NativeDeferredAcceptance)->allocate($contracts, $capacities, daPolicy([20, 10]))['bindings'])->toBe([2 => 2]);
});

test('student optimal DA chooses first wishes even when another stable matching exists', function () {
    // A: X > Y; B: Y > X; X: B > A; Y: A > B.
    $contracts = [daContract(1, 1, 100, 10, 20000), daContract(2, 1, 200, 10, 29000, 2), daContract(3, 2, 200, 10, 20000), daContract(4, 2, 100, 10, 29000, 2)];

    $result = (new NativeDeferredAcceptance)->allocate($contracts, [100 => ['Q' => 1, 'limits' => [10 => 1]], 200 => ['Q' => 1, 'limits' => [10 => 1]]], daPolicy([10]));

    expect($result['bindings'])->toBe([1 => 1, 2 => 3]);
});

test('a higher ranked NV2 displaces a lower ranked NV1 after rejection at another major', function () {
    $contracts = [daContract(1, 1, 100, 10, 20000), daContract(2, 1, 200, 10, 29000, 2), daContract(3, 2, 200, 10, 20000)];

    $result = (new NativeDeferredAcceptance)->allocate($contracts, [100 => ['Q' => 0, 'limits' => [10 => 0]], 200 => ['Q' => 1, 'limits' => [10 => 1]]], daPolicy([10]));

    expect($result['bindings'])->toBe([1 => 2]);
    expect($result['proposals'])->toBe(3);
});

test('hard q remains unused rather than transferring spare quota', function () {
    $contracts = [daContract(1, 1, 100, 10, 29000), daContract(2, 2, 100, 10, 28000)];

    expect((new NativeDeferredAcceptance)->allocate($contracts, [100 => ['Q' => 3, 'limits' => [10 => 1, 20 => 2]]], daPolicy())['bindings'])->toBe([1 => 1]);
});

test('conflicting rankings and unresolved ties are blocked without a hidden tie break', function (array $contracts, string $message) {
    expect(fn () => (new NativeDeferredAcceptance)->allocate($contracts, [100 => ['Q' => 1, 'limits' => [10 => 1, 20 => 1]]], daPolicy()))->toThrow(DomainException::class, $message);
})->with([
    'cycle' => [[daContract(1, 1, 100, 10, 29000), daContract(2, 1, 100, 20, 28000), daContract(3, 2, 100, 10, 28000), daContract(4, 2, 100, 20, 29000)], 'Conflicting method rankings'],
    'tie' => [[daContract(99, 99, 100, 10, 29000), daContract(1, 1, 100, 10, 29000)], 'Unresolved score tie'],
    'versions' => [[daContract(1, 1, 100, 10, 29000), daContract(2, 2, 100, 10, 28000, 1, 2)], 'Non-equivalent rule versions'],
]);

test('explicit equivalence permits shared ranking and canonical matching is unique', function () {
    $contracts = [daContract(1, 1, 100, 10, 29000), daContract(2, 2, 100, 10, 28000, 1, 2), daContract(3, 1, 100, 20, 29000), daContract(4, 2, 100, 20, 28000)];

    expect((new NativeDeferredAcceptance)->allocate($contracts, [100 => ['Q' => 2, 'limits' => [10 => 1, 20 => 1]]], daPolicy([10, 20], [[1, 2]]))['bindings'])->toBe([1 => 1, 2 => 4]);
});

test('changing IDs and input order does not change admissions or selected methods', function () {
    $contracts = [daContract(1, 100, 100, 10, 29000), daContract(2, 100, 100, 20, 29000), daContract(3, 1, 100, 10, 28000)];

    $result = (new NativeDeferredAcceptance)->allocate($contracts, [100 => ['Q' => 2, 'limits' => [10 => 1, 20 => 1]]], daPolicy());

    expect($result['bindings'])->toBe([1 => 3, 100 => 2]);
});

test('greedy matroid choice satisfies substitutes IRC and LAD exhaustively on overlapping methods', function () {
    $applicants = [1 => [10 => daContract(1, 1, 100, 10, 29000), 20 => daContract(2, 1, 100, 20, 29000)],
        2 => [10 => daContract(3, 2, 100, 10, 28000)], 3 => [20 => daContract(4, 3, 100, 20, 27000)],
        4 => [10 => daContract(5, 4, 100, 10, 26000), 20 => daContract(6, 4, 100, 20, 26000)]];
    $engine = new NativeDeferredAcceptance;
    foreach ([0, 1, 2, 3] as $Q) {
        foreach ([0, 1, 2] as $q1) {
            foreach ([0, 1, 2] as $q2) {
                $capacity = ['Q' => $Q, 'limits' => [10 => $q1, 20 => $q2]];
                $choices = [];
                $menus = [];
                for ($mask = 0; $mask < 16; $mask++) {
                    $menus[$mask] = array_values(array_filter([1, 2, 3, 4], fn ($app) => ($mask & (1 << ($app - 1))) !== 0));
                    $choices[$mask] = $engine->choose($menus[$mask], [1, 2, 3, 4], $applicants, $capacity);
                    expect(count($choices[$mask]))->toBeLessThanOrEqual($Q);
                    $best = [];
                    $bestValue = -1;
                    for ($subset = 0; $subset < 16; $subset++) {
                        if (($subset & $mask) !== $subset) {
                            continue;
                        }
                        $students = array_values(array_filter([1, 2, 3, 4], fn ($app) => ($subset & (1 << ($app - 1))) !== 0));
                        if (count($students) > $Q || ! bruteBindingFeasibility($students, $applicants, $capacity['limits'])) {
                            continue;
                        }
                        $value = array_sum(array_map(fn ($app) => 1 << (4 - $app), $students));
                        if ($value > $bestValue) {
                            $best = $students;
                            $bestValue = $value;
                        }
                    }
                    expect($choices[$mask])->toBe($best);
                }
                for ($small = 0; $small < 16; $small++) {
                    for ($large = 0; $large < 16; $large++) {
                        if (($small & $large) !== $small) {
                            continue;
                        }
                        expect(array_values(array_diff(array_intersect($choices[$large], $menus[$small]), $choices[$small])))->toBe([]);
                        expect(count($choices[$small]))->toBeLessThanOrEqual(count($choices[$large]));
                        if (array_diff($choices[$large], $menus[$small]) === []) {
                            expect($choices[$small])->toBe($choices[$large]);
                        }
                    }
                }
            }
        }
    }
});

test('malformed preferences and duplicate binding cannot allocate', function () {
    expect(fn () => (new NativeDeferredAcceptance)->allocate([daContract(1, 1, 100, 10, 29000), daContract(2, 1, 200, 10, 29000)], [100 => ['Q' => 1, 'limits' => [10 => 1]], 200 => ['Q' => 1, 'limits' => [10 => 1]]], daPolicy([10])))->toThrow(DomainException::class, 'Duplicate wish priority');
    expect(fn () => (new NativeDeferredAcceptance)->allocate([daContract(1, 1, 100, 10, 29000), daContract(1, 2, 100, 10, 28000)], [100 => ['Q' => 1, 'limits' => [10 => 1]]], daPolicy([10])))->toThrow(DomainException::class, 'Duplicate binding');
});

test('DA is student optimal against exhaustive stable matchings for every strict two by two profile', function () {
    for ($profile = 0; $profile < 16; $profile++) {
        $wishes = [1 => ($profile & 1) ? [100, 200] : [200, 100], 2 => ($profile & 2) ? [100, 200] : [200, 100]];
        $ranks = [100 => ($profile & 4) ? [1 => 1, 2 => 2] : [1 => 2, 2 => 1], 200 => ($profile & 8) ? [1 => 1, 2 => 2] : [1 => 2, 2 => 1]];
        $contracts = [];
        foreach ($wishes as $app => $offerings) {
            foreach ($offerings as $position => $offering) {
                $contracts[] = daContract($app * 1000 + $offering, $app, $offering, 10, 30000 - $ranks[$offering][$app] * 1000, $position + 1);
            }
        }
        $allocation = (new NativeDeferredAcceptance)->allocate($contracts, [100 => ['Q' => 1, 'limits' => [10 => 1]], 200 => ['Q' => 1, 'limits' => [10 => 1]]], daPolicy([10]));
        $actual = [];
        foreach ($contracts as $contract) {
            if (($allocation['bindings'][$contract['application_id']] ?? null) === $contract['binding_id']) {
                $actual[$contract['application_id']] = $contract['offering_id'];
            }
        }
        expect($actual)->toHaveCount(2);
        foreach ([[1 => 100, 2 => 200], [1 => 200, 2 => 100]] as $proposal) {
            $stable = true;
            foreach ($proposal as $app => $offering) {
                $alternative = $offering === 100 ? 200 : 100;
                $other = $app === 1 ? 2 : 1;
                if (array_search($alternative, $wishes[$app], true) < array_search($offering, $wishes[$app], true)
                    && $ranks[$alternative][$app] < $ranks[$alternative][$other]) {
                    $stable = false;
                }
            }
            if ($stable) {
                foreach ($proposal as $app => $offering) {
                    expect(array_search($actual[$app], $wishes[$app], true))->toBeLessThanOrEqual(array_search($offering, $wishes[$app], true));
                }
            }
        }
    }
});
