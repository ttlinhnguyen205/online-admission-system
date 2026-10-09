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
        'draft', 'closed', 'processing' => $offering->admissionRound->update(['status' => $condition]),
        'before' => $offering->admissionRound->update(['start_date' => now()->addMinute()]),
        'invalid-window' => $offering->admissionRound->update(['start_date' => now(), 'end_date' => now()]),
    };

    $context = app(BuildAdmissionCounselingContext::class)->build($program->major->name);

    expect(array_filter($context['facts'], fn ($fact) => $fact['kind'] === 'offering'))->toBeEmpty();
    expect(json_encode($context))->not->toContain($program->major->name);
})->with(['major', 'method', 'program', 'quota', 'mapping', 'disabled', 'draft', 'closed', 'processing', 'before', 'invalid-window']);

test('registration boundaries remain inclusive', function (string $boundary) {
    $offering = counselingOffering();
    $this->travelTo($offering->admissionRound->{$boundary});

    $context = app(BuildAdmissionCounselingContext::class)->build('');

    expect(array_column($context['facts'], 'kind'))->toContain('offering');
})->with(['start_date', 'end_date']);

test('previously public rounds remain browsable without accepting applications', function (string $status) {
    $offering = counselingOffering();
    $offering->admissionRound->update(['status' => $status, 'end_date' => now()->subMinute()]);
    $context = app(BuildAdmissionCounselingContext::class)->build($offering->major->name);
    $fact = collect($context['facts'])->firstWhere('kind', 'offering');
    $renderer = app(AdmissionCounselingAnswer::class);

    expect($context['has_open_rounds'])->toBeFalse();
    expect($fact)->toMatchArray(['available' => false, 'expired' => true]);
    $answer = $renderer->render(counselingPlan('published_majors', [$fact['ref']]), $context);
    expect($answer['body'])->toContain($offering->major->name, 'Đợt đã hết hạn', 'không còn nhận hồ sơ')
        ->not->toContain('Hiện đang nhận đăng ký');
    expect($answer['route'])->toBeNull();
    expect($renderer->render(counselingPlan('majors', [$fact['ref']]), $context)['body'])
        ->toContain('Hiện không có đợt tuyển sinh nào đang nhận hồ sơ');
    $round = collect($context['facts'])->firstWhere('kind', 'round');
    expect($renderer->render(counselingPlan('deadline', [$round['ref']]), $context)['body'])
        ->toContain($round['end'], 'Đợt đã hết hạn');
})->with(['open', 'published']);

test('historical offerings retain catalog disclosure safeguards', function (string $condition) {
    $offering = counselingOffering();
    $offering->admissionRound->update(['end_date' => now()->subMinute()]);
    match ($condition) {
        'major' => $offering->major->update(['is_active' => false]),
        'method' => $offering->admissionProgram->admissionMethod->update(['is_active' => false]),
        'program' => $offering->admissionProgram->update(['status' => 'inactive']),
        'quota' => $offering->admissionProgram->update(['quota' => 0]),
        'mapping' => $offering->update(['admission_program_id' => null]),
        'disabled' => $offering->update(['is_selectable' => false]),
    };

    expect(array_column(app(BuildAdmissionCounselingContext::class)->build('')['facts'], 'kind'))
        ->not->toContain('offering');
})->with(['major', 'method', 'program', 'quota', 'mapping', 'disabled']);

test('published rounds never accept applications even inside their registration dates', function () {
    $offering = counselingOffering();
    $offering->admissionRound->update(['status' => 'published']);
    $context = app(BuildAdmissionCounselingContext::class)->build('');
    $round = collect($context['facts'])->firstWhere('kind', 'round');
    $answer = app(AdmissionCounselingAnswer::class)->render(counselingPlan('published_rounds', [$round['ref']]), $context);

    expect($round)->toMatchArray(['available' => false, 'expired' => false]);
    expect($answer['body'])->toContain('hiện không nhận hồ sơ')->not->toContain('Hiện đang nhận đăng ký');
    expect($answer['route'])->toBeNull();
});

test('approved fields cannot disclose a draft round or its majors', function () {
    $offering = counselingOffering();
    $offering->admissionRound->update(['status' => 'draft']);
    $major = $offering->major;
    $major->update(['description' => 'Approved but unpublished']);
    config(['admission_chatbot.publications' => [
        'major:'.$major->id.':description' => ['approved' => true, 'fingerprint' => BuildAdmissionCounselingContext::fingerprint($major, 'description')],
    ]]);

    expect(app(BuildAdmissionCounselingContext::class)->build($major->name)['facts'])->toBeEmpty();
});

test('open and historical answers do not mix application availability', function () {
    $open = counselingOffering();
    $expired = counselingOffering();
    $expired->admissionRound->update(['end_date' => now()->subMinute()]);
    $context = app(BuildAdmissionCounselingContext::class)->build('');
    $renderer = app(AdmissionCounselingAnswer::class);
    $offerings = array_column(array_filter($context['facts'], fn ($fact) => $fact['kind'] === 'offering'), 'ref');
    $rounds = array_column(array_filter($context['facts'], fn ($fact) => $fact['kind'] === 'round'), 'ref');

    expect($renderer->render(counselingPlan('majors', $offerings), $context)['body'])
        ->toContain($open->major->name)->not->toContain($expired->major->name);
    expect($renderer->render(counselingPlan('rounds', $rounds), $context)['body'])
        ->toContain($open->admissionRound->name)->not->toContain($expired->admissionRound->name);
    expect($renderer->render(counselingPlan('published_majors', $offerings), $context)['body'])
        ->toContain($expired->major->name)->not->toContain($open->major->name);
    expect($renderer->render(counselingPlan('published_rounds', $rounds), $context)['body'])
        ->toContain($expired->admissionRound->name)->not->toContain($open->admissionRound->name);
});

test('empty catalogs give intent specific answers', function (string $intent, array $fields, string $message) {
    $context = app(BuildAdmissionCounselingContext::class)->build('');
    $answer = app(AdmissionCounselingAnswer::class)->render(counselingPlan($intent, [], $fields), $context);

    expect($answer['body'])->toContain($message);
})->with([
    ['rounds', [], 'Hiện không có đợt tuyển sinh nào đang nhận hồ sơ'],
    ['majors', [], 'Hiện không có đợt tuyển sinh nào đang nhận hồ sơ'],
    ['published_majors', [], 'thông tin đã công bố trước đây'],
    ['tuition', ['tuition'], 'học phí phù hợp được phê duyệt'],
    ['deadline', [], 'Hiện không có đợt tuyển sinh nào đang nhận hồ sơ'],
]);

test('Information Technology tuition stays available as approved historical information', function () {
    $offering = counselingOffering();
    $offering->major->update(['name' => 'Công nghệ thông tin']);
    $offering->admissionRound->update(['end_date' => now()->subMinute()]);
    $program = $offering->admissionProgram;
    $program->update(['tuition_fee' => '15000000.00']);
    $metadata = ['currency' => 'VND', 'period' => 'semester'];
    config(['admission_chatbot.publications' => [
        'program:'.$program->id.':tuition' => ['approved' => true, 'fingerprint' => BuildAdmissionCounselingContext::fingerprint($program, 'tuition', $metadata), ...$metadata],
    ]]);
    $context = app(BuildAdmissionCounselingContext::class)->build('Học phí ngành Công nghệ thông tin là bao nhiêu?');
    $fact = collect($context['facts'])->firstWhere('kind', 'offering');
    $answer = app(AdmissionCounselingAnswer::class)->render(counselingPlan('tuition', [$fact['ref']], ['tuition']), $context);

    expect($answer['body'])->toContain('Công nghệ thông tin', '15000000.00 VND / học kỳ', 'Đợt đã hết hạn')
        ->not->toContain('Hiện đang nhận đăng ký', 'Học phí minh họa');
});

test('historical field approvals expire after content edits', function () {
    $offering = counselingOffering();
    $offering->admissionRound->update(['end_date' => now()->subMinute()]);
    $major = $offering->major;
    $program = $offering->admissionProgram;
    $method = $program->admissionMethod;
    $metadata = ['currency' => 'VND', 'period' => 'year'];
    $major->update(['description' => 'Approved description']);
    $program->update(['tuition_fee' => '10000.00']);
    config(['admission_chatbot.publications' => [
        'major:'.$major->id.':description' => ['approved' => true, 'fingerprint' => BuildAdmissionCounselingContext::fingerprint($major, 'description')],
        'method:'.$method->id.':method' => ['approved' => true, 'fingerprint' => BuildAdmissionCounselingContext::fingerprint($method, 'method')],
        'program:'.$program->id.':program' => ['approved' => true, 'fingerprint' => BuildAdmissionCounselingContext::fingerprint($program, 'program')],
        'program:'.$program->id.':tuition' => ['approved' => true, 'fingerprint' => BuildAdmissionCounselingContext::fingerprint($program, 'tuition', $metadata), ...$metadata],
    ]]);
    $builder = app(BuildAdmissionCounselingContext::class);
    expect(collect($builder->build('')['facts'])->firstWhere('kind', 'offering'))
        ->toHaveKeys(['description', 'method', 'program', 'tuition']);
    $major->update(['description' => 'Changed description']);
    $method->update(['description' => 'Changed method']);
    $program->update(['tuition_fee' => '12345.00']);

    expect(collect($builder->build('')['facts'])->firstWhere('kind', 'offering'))
        ->not->toHaveKeys(['description', 'method', 'program', 'tuition']);
});

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

test('tuition requires explicit units approval and preserves zero and demo labels', function (?string $amount, ?string $currency, ?string $period, bool $visible, bool $expired) {
    $offering = counselingOffering();
    $offering->admissionRound->update(['code' => 'DEMO-ROUND']);
    if ($expired) {
        $offering->admissionRound->update(['end_date' => now()->subMinute()]);
    }
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
})->with([[null, 'VND', 'year', false], ['0.00', null, 'year', false], ['0.00', 'VND', null, false], ['0.00', 'VND', 'year', true]])->with([false, true]);

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
