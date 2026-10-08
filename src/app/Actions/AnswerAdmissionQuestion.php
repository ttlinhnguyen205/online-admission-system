<?php

namespace App\Actions;

use App\Models\User;
use App\Support\AdmissionCounselingAnswer;
use App\Support\AdmissionCounselingFailure;
use App\Support\AdmissionCounselingHistory;
use App\Support\AdmissionCounselingProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AnswerAdmissionQuestion
{
    public function __construct(
        private BuildAdmissionCounselingContext $context,
        private AdmissionCounselingProvider $provider,
        private AdmissionCounselingAnswer $answers,
        private AdmissionCounselingHistory $history,
    ) {}

    public function answer(User $user, string $question, string $revision, bool $consent): void
    {
        Gate::forUser($user->fresh())->authorize('use-admission-counseling');
        $question = trim($question);
        Validator::make(['question' => $question, 'consent' => $consent], [
            'question' => ['required', 'string', 'max:'.min(2000, (int) config('admission_chatbot.max_question_characters'))],
            'consent' => ['accepted'],
        ], [
            'question.required' => 'Vui lòng nhập câu hỏi.',
            'question.max' => 'Câu hỏi quá dài. Tối đa :max ký tự.',
            'consent.accepted' => 'Vui lòng xác nhận thông báo quyền riêng tư trước khi gửi.',
        ])->validate();
        if (! config('admission_chatbot.enabled')) {
            throw new AdmissionCounselingFailure('disabled');
        }
        $lock = Cache::lock('admission-chat:user:'.$user->id, 45);
        if (! $lock->get()) {
            throw new AdmissionCounselingFailure('busy');
        }
        try {
            $state = $this->history->read($user);
            if (! hash_equals($state['revision'], $revision)) {
                throw new AdmissionCounselingFailure('stale');
            }
            $this->limit($user);
            $normalized = Str::lower(Str::ascii($question));
            $directIntent = match (true) {
                str_contains($normalized, 'ket qua'), str_contains($normalized, 'diem cua toi') => 'results',
                preg_match('/hoc bong|chac chan|dam bao|xac suat|du doan|diem chuan|chinh sach|dieu kien trung tuyen/', $normalized) === 1 => 'unsupported',
                preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}|\b\d[\d .-]{8,}\d\b/iu', $question) === 1 => 'unsupported',
                default => null,
            };
            $previous = array_slice($state['turns'], -3);
            $history = array_map(static fn (array $turn): array => [
                'question' => Str::limit($turn['question'], 500),
                'focus' => $turn['answer']['focus'],
            ], $previous);
            $focus = $previous === [] ? [] : $previous[array_key_last($previous)]['answer']['focus'];
            $retrievalQuestion = $question.' '.implode(' ', $focus);
            $context = $this->context->build($retrievalQuestion);
            if ($directIntent !== null) {
                $plan = ['intent' => $directIntent, 'template' => $directIntent, 'sources' => [], 'fields' => [], 'choices' => []];
            } else {
                if (RateLimiter::tooManyAttempts('admission-chat:failures', 5)) {
                    throw new AdmissionCounselingFailure('network');
                }
                try {
                    $plan = $this->provider->plan($question, $context, $history);
                } catch (AdmissionCounselingFailure $failure) {
                    if (in_array($failure->reason, ['network', 'quota', 'configuration'], true)) {
                        RateLimiter::hit('admission-chat:failures', 60);
                    }
                    Log::notice('Admission counseling provider unavailable', ['reason' => $failure->reason]);
                    throw $failure;
                }
            }
            $this->answers->render($plan, $context);
            Gate::forUser($user->fresh())->authorize('use-admission-counseling');
            $fresh = $this->context->build($retrievalQuestion);
            if ($context['facts'] !== $fresh['facts'] || $context['truncated'] !== $fresh['truncated']) {
                throw new AdmissionCounselingFailure('changed');
            }
            $answer = $this->answers->render($plan, $fresh);
            /** Unsupported personal text is neither replayed to the provider nor retained verbatim. */
            $storedQuestion = $directIntent === null ? $question : 'Câu hỏi ngoài danh mục (nội dung riêng tư không được lưu lại)';
            $this->history->append($user, $state, $storedQuestion, $answer);
        } finally {
            $lock->release();
        }
    }

    private function limit(User $user): void
    {
        $lock = Cache::lock('admission-chat:limits', 5);
        if (! $lock->get()) {
            throw new AdmissionCounselingFailure('busy');
        }
        try {
            $limits = [
                ['user:'.$user->id.':minute', (int) config('admission_chatbot.per_minute'), 60],
                ['user:'.$user->id.':day', (int) config('admission_chatbot.per_day'), 86400],
                ['ip:'.hash('sha256', (string) request()->ip()), (int) config('admission_chatbot.per_ip_minute'), 60],
                ['global:day', (int) config('admission_chatbot.global_per_day'), 86400],
            ];
            foreach ($limits as [$key, $maximum, $decay]) {
                if (RateLimiter::tooManyAttempts('admission-chat:'.$key, max(1, $maximum))) {
                    throw new AdmissionCounselingFailure('limited');
                }
            }
            foreach ($limits as [$key, $maximum, $decay]) {
                RateLimiter::hit('admission-chat:'.$key, $decay);
            }
        } finally {
            $lock->release();
        }
    }
}
