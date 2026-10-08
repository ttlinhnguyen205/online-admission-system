<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

class AdmissionCounselingHistory
{
    /** Session-bound encrypted cache avoids concurrent requests overwriting Laravel session payloads.
     * @return array{revision: string, created: int, turns: list<array<string, mixed>>}
     */
    public function read(User $user): array
    {
        $key = $this->key($user);
        $stored = Cache::get($key);
        if (is_string($stored)) {
            try {
                $state = json_decode(Crypt::decryptString($stored), true, 32, JSON_THROW_ON_ERROR);
                if (is_array($state) && isset($state['revision'], $state['created'], $state['turns'])
                    && $state['created'] > now()->subHours(max(1, min(24, (int) config('admission_chatbot.history_absolute_hours'))))->timestamp) {
                    return $state;
                }
            } catch (Throwable) {
                /** An unreadable or expired conversation is discarded, never returned to the provider. */
            }
            Cache::forget($key);
        }
        $state = ['revision' => (string) Str::uuid(), 'created' => now()->timestamp, 'turns' => []];
        Cache::add($key, Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)), $this->lifetime());

        return json_decode(Crypt::decryptString(Cache::get($key)), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @param array{revision: string, created: int, turns: list<array<string, mixed>>} $state
     * @param  array<string, mixed>  $answer
     */
    public function append(User $user, array $state, string $question, array $answer): void
    {
        $state['revision'] = (string) Str::uuid();
        $state['turns'][] = ['id' => (string) Str::uuid(), 'question' => $question, 'answer' => $answer];
        $state['turns'] = array_slice($state['turns'], -max(1, min(10, (int) config('admission_chatbot.history_turns'))));
        $limit = max(4096, min(32768, (int) config('admission_chatbot.history_bytes')));
        while (count($state['turns']) > 1 && strlen(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > $limit) {
            array_shift($state['turns']);
        }
        if (strlen(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > $limit) {
            throw new AdmissionCounselingFailure('invalid');
        }
        $expires = CarbonImmutable::createFromTimestamp($state['created'])
            ->addHours(max(1, min(24, (int) config('admission_chatbot.history_absolute_hours'))))
            ->min(now()->addSeconds($this->lifetime()));
        Cache::put($this->key($user), Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), $expires);
    }

    public function clear(User $user): void
    {
        Cache::forget($this->key($user));
    }

    public function key(User $user): string
    {
        return 'admission-chat:history:'.hash('sha256', session()->getId().':'.$user->getAuthIdentifier());
    }

    private function lifetime(): int
    {
        return max(60, min(120, (int) config('admission_chatbot.history_minutes'), (int) config('session.lifetime')) * 60);
    }
}
