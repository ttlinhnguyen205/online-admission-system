<?php

use App\Models\CandidateScore;
use App\Support\DisplayNumber;

test('decimal display uses two Vietnamese decimal places', function (int|float|string|null $value, string $expected) {
    expect(DisplayNumber::decimal($value))->toBe($expected);
})->with([
    'missing' => [null, '—'],
    'zero' => [0, '0,00'],
    'integer score' => [8, '8,00'],
    'rounding' => ['8.125', '8,13'],
    'rounding across integer boundary' => ['8.999', '9,00'],
    'large decimal' => ['25000000.5', '25.000.000,50'],
]);

test('tuition display uses whole dong', function (int|float|string|null $value, string $expected) {
    expect(DisplayNumber::tuition($value))->toBe($expected);
})->with([
    'unspecified' => [null, '—'],
    'zero' => [0, '0'],
    'whole tuition' => ['25000000.00', '25.000.000'],
    'existing fractional amount' => ['25000000.50', '25.000.001'],
]);

test('candidate score display rounds without changing the stored scoring precision', function () {
    $score = CandidateScore::factory()->create(['score' => '8.125']);
    $this->actingAs($score->candidateProfile->user);

    $this->get(route('candidate.scores.index'))->assertOk()->assertSeeText('8,13');

    expect($score->fresh()->score)->toBe('8.125');
});
