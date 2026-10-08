<?php

use App\Support\AdmissionCounselingFailure;
use App\Support\AdmissionCounselingProvider;
use App\Support\DisabledAdmissionCounselingProvider;
use App\Support\GeminiAdmissionCounselingProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/AdmissionCounselingFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'admission_chatbot.enabled' => true,
        'services.gemini.key' => 'test-placeholder-not-a-real-key',
        'services.gemini.model' => 'gemini-3.5-flash-lite',
    ]);
});

test('gemini sends a server side schema constrained request with no tools or query credentials', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode(counselingPlan())]]]]],
    ])]);

    $plan = app(GeminiAdmissionCounselingProvider::class)->plan('Ngành nào?', ['facts' => []], []);

    expect($plan)->toBe(counselingPlan());
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent'
        && $request->hasHeader('x-goog-api-key', 'test-placeholder-not-a-real-key')
        && $request['generationConfig']['responseMimeType'] === 'application/json'
        && isset($request['generationConfig']['responseJsonSchema'])
        && isset($request['systemInstruction'])
        && ! isset($request['tools'], $request['cachedContent'])
    );
});

test('gemini normalizes errors without retries or leaking provider text', function (int $status, string $reason) {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response('sensitive provider response', $status)]);

    try {
        app(GeminiAdmissionCounselingProvider::class)->plan('Ngành nào?', [], []);
        test()->fail('Expected a safe provider failure');
    } catch (AdmissionCounselingFailure $failure) {
        expect($failure->reason)->toBe($reason);
        expect($failure->getMessage())->not->toContain('sensitive', 'test-placeholder');
        expect($failure->getPrevious())->toBeNull();
    }
    Http::assertSentCount(1);
})->with([[400, 'configuration'], [401, 'configuration'], [403, 'configuration'], [404, 'configuration'], [429, 'quota'], [500, 'network'], [503, 'network']]);

test('gemini rejects empty malformed oversized truncated and refused responses', function (mixed $payload, string $reason) {
    config(['admission_chatbot.max_response_bytes' => 1024]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($payload)]);

    expect(fn () => app(GeminiAdmissionCounselingProvider::class)->plan('Ngành nào?', [], []))
        ->toThrow(AdmissionCounselingFailure::class, $reason);
    Http::assertSentCount(1);
})->with([
    'empty' => ['', 'invalid'],
    'invalid json' => ['not JSON', 'invalid'],
    'empty candidate' => [['candidates' => []], 'invalid'],
    'scalar candidate' => [['candidates' => ['bad']], 'invalid'],
    'scalar content' => [['candidates' => [['finishReason' => 'STOP', 'content' => 'bad']]], 'invalid'],
    'scalar parts' => [['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => ['bad']]]]], 'invalid'],
    'invalid plan JSON' => [
        ['candidates' => [
            ['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{']]]],
        ]],
        'invalid',
    ],
    'oversized' => [str_repeat('a', 1025), 'invalid'],
    'blocked prompt' => [['promptFeedback' => ['blockReason' => 'SAFETY']], 'refused'],
    'blocked answer' => [['candidates' => [['finishReason' => 'SAFETY']]], 'refused'],
    'token limit' => [['candidates' => [['finishReason' => 'MAX_TOKENS']]], 'invalid'],
]);

test('network failures are normalized without retry', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::failedConnection()]);

    expect(fn () => app(GeminiAdmissionCounselingProvider::class)->plan('Ngành nào?', [], []))
        ->toThrow(AdmissionCounselingFailure::class, 'network');
});

test('missing credentials model or disabled feature never sends a request', function (string $setting, mixed $value) {
    config([$setting => $value]);

    expect(fn () => app(GeminiAdmissionCounselingProvider::class)->plan('Ngành nào?', [], []))
        ->toThrow(AdmissionCounselingFailure::class);
    Http::assertNothingSent();
})->with([
    ['services.gemini.key', ''], ['services.gemini.model', ''],
    ['services.gemini.model', '../private?key=oops'], ['admission_chatbot.enabled', false],
]);

test('unknown provider is disabled instead of silently using gemini', function () {
    config(['admission_chatbot.provider' => 'unknown']);

    expect(app(AdmissionCounselingProvider::class))->toBeInstanceOf(DisabledAdmissionCounselingProvider::class);
    expect(fn () => app(AdmissionCounselingProvider::class)->plan('', [], []))->toThrow(AdmissionCounselingFailure::class, 'disabled');
    Http::assertNothingSent();
});
