<?php

use App\Actions\BuildAdmissionCounselingContext;
use App\Models\AdmissionProgram;
use App\Support\AdmissionCounselingAnswer;
use App\Support\AdmissionCounselingFailure;

require_once __DIR__.'/AdmissionCounselingFixtures.php';

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 8)->setTime(12, 0));
});

test('counseling uses only available major offerings and exposes no internal configuration', function () {
    $offering = counselingOffering();
    AdmissionProgram::factory()->for($offering->admissionRound)->create();
    $program = $offering->admissionProgram;
    $program->admissionMethod->update(['score_config' => ['secret' => 'internal-scoring']]);

    $context = app(BuildAdmissionCounselingContext::class)->build('');

    expect(array_column($context['facts'], 'kind'))->toContain('round', 'offering');
    expect(array_filter($context['facts'], fn ($fact) => $fact['kind'] === 'offering'))->toHaveCount(1);
    expect(json_encode($context))->not->toContain('admission_program_id', 'score_config', 'internal-scoring', $program->admissionMethod->name);
});

test('counseling excludes every unavailable registration condition', function (string $condition) {
    $offering = counselingOffering();
    $program = $offering->admissionProgram;
    match ($condition) {
        'major' => $program->major->update(['is_active' => false]),
        'method' => $program->admissionMethod->update(['is_active' => false]),
        'program' => $program->update(['status' => 'inactive']),
        'quota' => $program->update(['quota' => 0]),
        'mapping' => $offering->update(['admission_program_id' => null]),
        'disabled' => $offering->update(['is_selectable' => false]),
        'draft', 'closed', 'published' => $offering->admissionRound->update(['status' => $condition]),
        'before' => $offering->admissionRound->update(['start_date' => now()->addMinute()]),
        'after' => $offering->admissionRound->update(['end_date' => now()->subMinute()]),
        'invalid-window' => $offering->admissionRound->update(['start_date' => now(), 'end_date' => now()]),
    };

    $context = app(BuildAdmissionCounselingContext::class)->build($program->major->name);

    expect(array_filter($context['facts'], fn ($fact) => $fact['kind'] === 'offering'))->toBeEmpty();
    expect(json_encode($context))->not->toContain($program->major->name);
})->with(['major', 'method', 'program', 'quota', 'mapping', 'disabled', 'draft', 'closed', 'published', 'before', 'after', 'invalid-window']);

test('registration boundaries remain inclusive', function (string $boundary) {
    $offering = counselingOffering();
    $this->travelTo($offering->admissionRound->{$boundary});

    $context = app(BuildAdmissionCounselingContext::class)->build('');

    expect(array_column($context['facts'], 'kind'))->toContain('offering');
})->with(['start_date', 'end_date']);

test('additional disclosure requires content bound approval and vanishes after edits', function () {
    $offering = counselingOffering();
    $major = $offering->major;
    $major->update(['description' => 'Mô tả được phê duyệt']);
    config(['admission_chatbot.publications' => [
        'major:'.$major->id.':description' => ['approved' => true, 'fingerprint' => BuildAdmissionCounselingContext::fingerprint($major, 'description')],
    ]]);
    $builder = app(BuildAdmissionCounselingContext::class);

    expect(json_encode($builder->build(''), JSON_UNESCAPED_UNICODE))->toContain('Mô tả được phê duyệt');
    $major->update(['description' => 'Nội dung mới chưa được duyệt']);

    expect(json_encode($builder->build(''), JSON_UNESCAPED_UNICODE))->not->toContain('Nội dung mới chưa được duyệt', 'description');
});

test('tuition requires explicit units approval and preserves zero and demo labels', function (?string $amount, ?string $currency, ?string $period, bool $visible) {
    $offering = counselingOffering();
    $offering->admissionRound->update(['code' => 'DEMO-ROUND']);
    $program = $offering->admissionProgram;
    $program->update(['tuition_fee' => $amount]);
    $metadata = compact('currency', 'period');
    config(['admission_chatbot.publications' => [
        'program:'.$program->id.':tuition' => ['approved' => true, 'fingerprint' => BuildAdmissionCounselingContext::fingerprint($program, 'tuition', $metadata), ...$metadata],
    ]]);

    $context = app(BuildAdmissionCounselingContext::class)->build('');
    $fact = collect($context['facts'])->firstWhere('kind', 'offering');
    $answer = app(AdmissionCounselingAnswer::class)->render(counselingPlan('tuition', [$fact['ref']], ['tuition']), $context);

    expect(isset($fact['tuition']))->toBe($visible);
    expect($answer['body'])->toContain('DỮ LIỆU DEMO');
    if ($visible) {
        expect($fact['tuition']['amount'])->toBe('0.00');
        expect($answer['body'])->toContain('Học phí minh họa: 0.00 VND / năm học');
    } else {
        expect($answer['body'])->toContain('chưa được phê duyệt');
    }
})->with([[null, 'VND', 'year', false], ['0.00', null, 'year', false], ['0.00', 'VND', null, false], ['0.00', 'VND', 'year', true]]);

test('approved methods and programs do not expose quotas or scoring configuration', function () {
    $offering = counselingOffering();
    $program = $offering->admissionProgram;
    $method = $program->admissionMethod;
    config(['admission_chatbot.publications' => [
        'method:'.$method->id.':method' => ['approved' => true, 'fingerprint' => BuildAdmissionCounselingContext::fingerprint($method, 'method')],
        'program:'.$program->id.':program' => ['approved' => true, 'fingerprint' => BuildAdmissionCounselingContext::fingerprint($program, 'program')],
    ]]);

    $fact = collect(app(BuildAdmissionCounselingContext::class)->build('')['facts'])->firstWhere('kind', 'offering');

    expect($fact)->toHaveKeys(['method', 'program'])->not->toHaveKeys(['quota', 'score_config', 'minimum_score', 'previous_cutoff_score']);
});

test('bounded retrieval marks incomplete results and never claims complete absence', function () {
    counselingOffering();
    config(['admission_chatbot.max_facts' => 1]);
    $context = app(BuildAdmissionCounselingContext::class)->build('');
    $answer = app(AdmissionCounselingAnswer::class)->render(counselingPlan(), $context);

    expect($context['truncated'])->toBeTrue();
    expect($context['facts'])->toHaveCount(1);
    expect($answer['body'])->toContain('vượt giới hạn');
});

test('untrusted answer plans cannot introduce facts or unrelated fields', function (array $plan) {
    $context = app(BuildAdmissionCounselingContext::class)->build('');

    expect(fn () => app(AdmissionCounselingAnswer::class)->render($plan, $context))->toThrow(AdmissionCounselingFailure::class);
})->with([
    'invented source' => [counselingPlan('majors', ['invented'])],
    'invented amount' => [[...counselingPlan(), 'tuition' => 100]],
    'raw prose' => [[...counselingPlan(), 'answer' => 'Guaranteed admission']],
    'mismatched template' => [[...counselingPlan(), 'template' => 'tuition']],
    'unsupported field' => [counselingPlan('majors', [], ['score_config'])],
    'missing field request' => [counselingPlan('tuition')],
    'invented clarification' => [counselingPlan('clarify', [], [], ['a', 'b'])],
]);
