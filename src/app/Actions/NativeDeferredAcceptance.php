<?php

namespace App\Actions;

use DomainException;

/**
 * @phpstan-type Contract array{application_id: int, binding_id: int, offering_id: int, method_id: int, rule_id: int, priority: int, score: int}
 * @phpstan-type Capacity array{Q: int, limits: array<int, int>}
 */
class NativeDeferredAcceptance
{
    public const ALGORITHM = 'student-da-matroid-v1';

    /**
     * Fixed full-market priorities, never recomputed from a proposal subset.
     * Method precedence only completes incomparable applicant priorities and picks
     * final bindings. Scores are compared strictly inside one approved rule group.
     *
     * @param  list<Contract>  $contracts
     * @param  array<int, Capacity>  $capacities
     * @param  array{algorithm: string, method_priority: list<int>, rule_equivalences: list<list<int>>, ties: string, policy_reference: string}  $policy
     * @return array{bindings: array<int, int>, priorities: array<int, list<int>>, proposals: int}
     */
    public function allocate(array $contracts, array $capacities, array $policy): array
    {
        $this->require($policy['algorithm'] === self::ALGORITHM && $policy['ties'] === 'block', 'Unsupported policy.');
        $this->require(count($policy['method_priority']) === count(array_unique($policy['method_priority'])), 'Duplicate method precedence.');
        $groups = [];
        foreach ($policy['rule_equivalences'] as $index => $rules) {
            foreach ($rules as $rule) {
                $this->require(! isset($groups[$rule]), 'Overlapping rule equivalence.');
                $groups[$rule] = 'equivalent:'.$index;
            }
        }
        $byOffering = [];
        $preferences = [];
        $bindingIds = [];
        foreach ($contracts as $contract) {
            $app = $contract['application_id'];
            $offering = $contract['offering_id'];
            $method = $contract['method_id'];
            $this->require($contract['priority'] > 0 && $contract['score'] >= 0 && $contract['score'] <= 30000
                && isset($capacities[$offering]['limits'][$method]) && in_array($method, $policy['method_priority'], true), 'Invalid contract scope or score.');
            $this->require(! isset($bindingIds[$contract['binding_id']]) && ! isset($byOffering[$offering][$app][$method]), 'Duplicate binding.');
            $bindingIds[$contract['binding_id']] = true;
            $this->require(! isset($preferences[$app][$contract['priority']]) || $preferences[$app][$contract['priority']] === $offering, 'Duplicate wish priority.');
            foreach ($preferences[$app] ?? [] as $priority => $existing) {
                $this->require($existing !== $offering || $priority === $contract['priority'], 'Inconsistent wish priority.');
            }
            $preferences[$app][$contract['priority']] = $offering;
            $byOffering[$offering][$app][$method] = $contract;
        }
        foreach ($capacities as $capacity) {
            $this->require($capacity['Q'] >= 0 && $capacity['limits'] !== [] && min($capacity['limits']) >= 0, 'Invalid Q/q.');
        }
        $orders = [];
        foreach ($byOffering as $offering => $applicants) {
            $orders[$offering] = $this->priorityOrder($applicants, $policy['method_priority'], $groups);
        }
        foreach ($preferences as &$wishes) {
            ksort($wishes, SORT_NUMERIC);
            $wishes = array_values($wishes);
        }
        unset($wishes);
        $next = array_fill_keys(array_keys($preferences), 0);
        $held = [];
        $rejected = [];
        $proposals = 0;
        do {
            $active = false;
            $assigned = [];
            foreach ($held as $students) {
                foreach ($students as $app) {
                    $assigned[$app] = true;
                }
            }
            $offers = $held;
            foreach ($preferences as $app => $wishes) {
                if (! isset($assigned[$app]) && isset($wishes[$next[$app]])) {
                    $offering = $wishes[$next[$app]++];
                    $offers[$offering][] = $app;
                    $active = true;
                    $proposals++;
                }
            }
            foreach ($offers as $offering => $students) {
                $chosen = $this->choose($students, $orders[$offering], $byOffering[$offering], $capacities[$offering]);
                foreach (array_diff($students, $chosen) as $app) {
                    $rejected[$app][$offering] = true;
                }
                $held[$offering] = $chosen;
            }
        } while ($active);
        // Independent terminal stability check using the fixed institutional choice.
        $assigned = [];
        foreach ($held as $offering => $students) {
            foreach ($students as $app) {
                $assigned[$app] = $offering;
            }
        }
        foreach ($preferences as $app => $wishes) {
            foreach ($wishes as $offering) {
                if (($assigned[$app] ?? null) === $offering) {
                    break;
                }
                $trial = $this->choose([...($held[$offering] ?? []), $app], $orders[$offering], $byOffering[$offering], $capacities[$offering]);
                $this->require(! in_array($app, $trial, true), 'Unstable terminal allocation.');
            }
        }
        $bindings = [];
        foreach ($held as $offering => $students) {
            $limits = $capacities[$offering]['limits'];
            foreach ($students as $position => $app) {
                $found = false;
                foreach ($policy['method_priority'] as $method) {
                    if (! isset($byOffering[$offering][$app][$method]) || ($limits[$method] ?? 0) === 0) {
                        continue;
                    }
                    $remaining = $limits;
                    $remaining[$method]--;
                    if ($this->matchable(array_slice($students, $position + 1), $byOffering[$offering], $remaining)) {
                        $bindings[$app] = $byOffering[$offering][$app][$method]['binding_id'];
                        $limits = $remaining;
                        $found = true;
                        break;
                    }
                }
                $this->require($found, 'No canonical feasible binding.');
            }
        }
        // Sorting identities canonicalizes serialization only, never a decision.
        ksort($bindings, SORT_NUMERIC);
        ksort($orders, SORT_NUMERIC);

        return ['bindings' => $bindings, 'priorities' => $orders, 'proposals' => $proposals];
    }

    /**
     * @param  list<int>  $proposals
     * @param  list<int>  $order
     * @param  array<int, array<int, Contract>>  $applicants
     * @param  Capacity  $capacity
     * @return list<int>
     */
    public function choose(array $proposals, array $order, array $applicants, array $capacity): array
    {
        $selected = [];
        $requested = array_fill_keys($proposals, true);
        foreach ($order as $app) {
            if (isset($requested[$app]) && count($selected) < $capacity['Q'] && $this->matchable([...$selected, $app], $applicants, $capacity['limits'])) {
                $selected[] = $app;
            }
        }

        return $selected;
    }

    /**
     * @param  array<int, array<int, Contract>>  $applicants
     * @param  list<int>  $methods
     * @param  array<int, string>  $groups
     * @return list<int>
     */
    private function priorityOrder(array $applicants, array $methods, array $groups): array
    {
        $edges = [];
        $degree = array_fill_keys(array_keys($applicants), 0);
        $primary = [];
        foreach ($applicants as $app => $bindings) {
            foreach ($methods as $index => $method) {
                if (isset($bindings[$method])) {
                    $primary[$app] = $index;
                    break;
                }
            }
        }
        foreach ($methods as $method) {
            $ranking = [];
            $group = null;
            foreach ($applicants as $app => $bindings) {
                if (isset($bindings[$method])) {
                    $contract = $bindings[$method];
                    $reference = $groups[$contract['rule_id']] ?? 'rule:'.$contract['rule_id'];
                    $this->require($group === null || $group === $reference, 'Non-equivalent rule versions.');
                    $group = $reference;
                    $ranking[$app] = $contract['score'];
                }
            }
            arsort($ranking, SORT_NUMERIC);
            $previous = null;
            $score = null;
            foreach ($ranking as $app => $value) {
                $this->require($score !== $value, 'Unresolved score tie.');
                if ($previous !== null && ! isset($edges[$previous][$app])) {
                    $edges[$previous][$app] = true;
                    $degree[$app]++;
                }
                $previous = $app;
                $score = $value;
            }
        }
        $order = [];
        while ($degree !== []) {
            $available = array_keys(array_filter($degree, fn (int $value): bool => $value === 0));
            $this->require($available !== [], 'Conflicting method rankings.');
            usort($available, fn (int $a, int $b): int => $primary[$a] <=> $primary[$b]);
            $this->require(count($available) === 1 || $primary[$available[0]] !== $primary[$available[1]], 'Missing joint priority policy.');
            $app = $available[0];
            $order[] = $app;
            unset($degree[$app]);
            foreach (array_keys($edges[$app] ?? []) as $lower) {
                $degree[$lower]--;
            }
        }

        return $order;
    }

    /**
     * @param  list<int>  $students
     * @param  array<int, array<int, Contract>>  $applicants
     * @param  array<int, int>  $limits
     */
    private function matchable(array $students, array $applicants, array $limits): bool
    {
        $held = [];
        foreach ($students as $app) {
            $visited = [];
            if (! $this->augment($app, $applicants, $limits, $held, $visited)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<int, Contract>>  $applicants
     * @param  array<int, int>  $limits
     * @param  array<int, list<int>>  $held
     * @param  array<int, true>  $visited
     */
    private function augment(int $app, array $applicants, array $limits, array &$held, array &$visited): bool
    {
        if (isset($visited[$app])) {
            return false;
        }
        $visited[$app] = true;
        foreach ($applicants[$app] as $method => $contract) {
            if (count($held[$method] ?? []) < ($limits[$method] ?? 0)) {
                $held[$method][] = $app;

                return true;
            }
            foreach ($held[$method] ?? [] as $other) {
                if ($this->augment($other, $applicants, $limits, $held, $visited)) {
                    $held[$method] = array_map(fn (int $student): int => $student === $other ? $app : $student, $held[$method]);

                    return true;
                }
            }
        }

        return false;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new DomainException('BLOCKED: '.$message);
        }
    }
}
