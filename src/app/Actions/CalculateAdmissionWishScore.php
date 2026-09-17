<?php

namespace App\Actions;

class CalculateAdmissionWishScore
{
    /**
     * Preserve every matching attempt; aliases never deduplicate records.
     *
     * @param  iterable<array<string, mixed>>  $scores
     * @param  array<string, string>  $aliases
     * @return array<int, array<string, mixed>>
     */
    public function index(iterable $scores, array $aliases): array
    {
        $index = [];
        foreach ($scores as $score) {
            $type = $aliases[mb_strtolower(trim((string) $score['score_type']), 'UTF-8')] ?? null;
            if ($type === null || ! $score['verified']) {
                continue;
            }
            $profile = (int) $score['candidate_profile_id'];
            $year = (int) $score['exam_year'];
            $subject = mb_strtoupper(trim((string) ($score['subject_code'] ?? '')), 'UTF-8');
            $index[$profile][$type][$year]['all'][] = $score;
            $index[$profile][$type][$year]['subjects'][$subject][] = $score;
        }

        return $index;
    }

    /**
     * Integer thousandths are the sole comparison/persistence representation.
     * Products have six decimal places; round their sum once, half up.
     *
     * @param  array<string, mixed>  $descriptor
     * @param  array{all?: list<array<string, mixed>>, subjects?: array<string, list<array<string, mixed>>>}  $inputs
     */
    public function calculate(array $descriptor, mixed $configuration, array $inputs): int
    {
        $type = $descriptor['type'] ?? 'unsupported';
        if (! in_array($type, ['weighted', 'scalar'], true) || ! is_string($descriptor['score_type'] ?? null)) {
            throw new \DomainException('Unsupported calculation contract.');
        }
        if ($type === 'scalar') {
            if ($configuration !== null) {
                throw new \DomainException('The scalar method requires null score configuration.');
            }

            return $this->selected($inputs['all'] ?? []);
        }
        $subjects = $descriptor['subjects'] ?? [];
        if (! is_array($subjects) || $subjects === [] || count($subjects) > 30
            || ! is_array($configuration) || array_keys($configuration) !== ['weights']
            || ! is_array($configuration['weights'])) {
            throw new \DomainException('Malformed weighted calculation contract.');
        }
        $weights = $configuration['weights'];
        $keys = array_map('strval', array_keys($weights));
        $expected = $subjects;
        sort($keys);
        sort($expected);
        if ($keys !== $expected || count(array_unique($subjects)) !== count($subjects)) {
            throw new \DomainException('Configured subjects must exactly match the approved method descriptor.');
        }
        $total = 0;
        foreach ($weights as $subject => $weight) {
            if (! preg_match('/\A[A-Z0-9_-]{1,30}\z/D', (string) $subject)) {
                throw new \DomainException('Invalid configured subject.');
            }
            $units = self::decimal($weight);
            if ($units < 1 || $units > 100000) {
                throw new \DomainException('Weight must be positive and at most 100.');
            }
            $total += $this->selected($inputs['subjects'][$subject] ?? []) * $units;
        }
        $rounded = intdiv($total + 500, 1000);
        if ($rounded > 99999999) {
            throw new \DomainException('Calculated score exceeds decimal(8,3) storage capacity.');
        }

        return $rounded;
    }

    /** @param list<array<string, mixed>> $scores */
    private function selected(array $scores): int
    {
        if ($scores === []) {
            throw new \DomainException('Missing verified score for a required input in the round year.');
        }
        if (count($scores) !== 1) {
            throw new \DomainException('Ambiguous verified score attempts for a required input in the round year.');
        }

        return self::decimal($scores[0]['score']);
    }

    public static function decimal(mixed $value): int
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new \DomainException('Invalid decimal value.');
        }
        $value = (string) $value;
        if (! preg_match('/\A([0-9]+)(?:\.([0-9]{1,3}))?\z/D', $value, $parts)) {
            throw new \DomainException('Invalid decimal value or precision.');
        }
        $whole = ltrim($parts[1], '0');
        if (strlen($whole) > 5) {
            throw new \DomainException('Decimal value exceeds decimal(8,3) storage capacity.');
        }

        return (int) $whole * 1000 + (int) str_pad($parts[2] ?? '', 3, '0');
    }

    public static function format(int $units): string
    {
        return intdiv($units, 1000).'.'.str_pad((string) ($units % 1000), 3, '0', STR_PAD_LEFT);
    }
}
