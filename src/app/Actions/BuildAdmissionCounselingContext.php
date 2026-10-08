<?php

namespace App\Actions;

use App\Enums\AdmissionRoundStatus;
use App\Models\AdmissionRound;
use App\Models\CandidateMajorOffering;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BuildAdmissionCounselingContext
{
    /** @return array{facts: list<array<string, mixed>>, truncated: bool, checked_at: string, timezone: string} */
    public function build(string $question): array
    {
        $maximum = max(1, min(500, (int) config('admission_chatbot.max_catalog_records')));
        $now = now(config('app.timezone'));
        $rounds = AdmissionRound::query()->where('status', AdmissionRoundStatus::Open)
            ->where('start_date', '<=', $now)->where('end_date', '>=', $now)
            ->orderBy('id')->limit($maximum + 1)->get();
        $truncated = $rounds->count() > $maximum;
        $rounds = $rounds->take($maximum)->filter(fn (AdmissionRound $round): bool => CandidateApplications::roundIsOpen($round));
        $offerings = CandidateMajorOffering::query()->whereIn('admission_round_id', $rounds->modelKeys())
            ->where('is_selectable', true)->with(['major', 'admissionProgram.major', 'admissionProgram.admissionMethod'])
            ->orderBy('id')->limit($maximum + 1)->get();
        $truncated = $truncated || $offerings->count() > $maximum;
        $facts = [];
        foreach ($rounds as $round) {
            $facts[] = [
                'ref' => $this->reference($round), 'kind' => 'round',
                'name' => $round->name, 'code' => $round->code,
                'start' => $this->date($round, 'start_date'),
                'end' => $this->date($round, 'end_date'),
                'available' => true, 'demo' => $this->demo($round->code),
            ];
        }
        foreach ($offerings->take($maximum) as $offering) {
            $round = $rounds->find($offering->admission_round_id);
            $program = $offering->admissionProgram;
            if ($round === null || ! CandidateMajorOfferings::available($offering, $program, $round)) {
                continue;
            }
            $major = $offering->major;
            $method = $program->admissionMethod;
            $fact = [
                'ref' => $this->reference($offering), 'kind' => 'offering',
                'name' => $major->name, 'code' => $major->code,
                'round' => $round->name, 'round_code' => $round->code,
                'start' => $this->date($round, 'start_date'),
                'end' => $this->date($round, 'end_date'),
                'available' => true,
                'demo' => $this->demo($round->code) || $this->demo($major->code) || $this->demo($method->code),
            ];
            if ($this->approved($major, 'description') && is_string($major->description) && $major->description !== '') {
                $fact['description'] = Str::limit(strip_tags($major->description), 800);
            }
            if ($this->approved($method, 'method')) {
                $fact['method'] = Str::limit($method->name.' — '.strip_tags($method->description ?? ''), 800);
                if ($this->approved($program, 'program')) {
                    $fact['program'] = $major->name.' — '.$method->name.' — '.$round->name;
                }
            }
            foreach ([[$program, 'tuition_fee', 'program'], [$major, 'default_tuition_fee', 'default']] as [$model, $attribute, $basis]) {
                $approval = $this->approval($model, 'tuition');
                $metadata = ['currency' => $approval['currency'] ?? null, 'period' => $approval['period'] ?? null];
                if ($model->getAttribute($attribute) !== null && $this->approved($model, 'tuition', $metadata)
                    && is_string($metadata['currency']) && preg_match('/\A[A-Z]{3}\z/', $metadata['currency'])
                    && in_array($metadata['period'], ['semester', 'year', 'credit', 'course'], true)) {
                    $fact['tuition'] = ['amount' => $model->getAttribute($attribute), ...$metadata, 'basis' => $basis];
                    break;
                }
            }
            $facts[] = $fact;
        }
        $normalized = Str::lower(Str::ascii($question));
        usort($facts, function (array $left, array $right) use ($normalized): int {
            $score = static function (array $fact) use ($normalized): int {
                return str_contains($normalized, Str::lower(Str::ascii($fact['name'])))
                    || str_contains($normalized, Str::lower($fact['code'])) ? 1 : 0;
            };

            return $score($right) <=> $score($left) ?: strcmp($left['ref'], $right['ref']);
        });
        $limit = max(1, min(40, (int) config('admission_chatbot.max_facts')));
        $truncated = $truncated || count($facts) > $limit;
        $facts = array_slice($facts, 0, $limit);
        $byteLimit = max(1000, min(32000, (int) config('admission_chatbot.max_context_bytes')));
        while ($facts !== [] && strlen(json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > $byteLimit) {
            array_pop($facts);
            $truncated = true;
        }

        return ['facts' => $facts, 'truncated' => $truncated, 'checked_at' => $now->format('d/m/Y H:i:s'), 'timezone' => (string) config('app.timezone')];
    }

    /** @param array<string, mixed> $metadata */
    public static function fingerprint(Model $model, string $field, array $metadata = []): string
    {
        $attributes = $model->getAttributes();
        foreach ($attributes as $key => $value) {
            $attributes[$key] = $model->getAttribute($key);
            if ($attributes[$key] instanceof \DateTimeInterface) {
                $attributes[$key] = $attributes[$key]->format(DATE_ATOM);
            }
        }
        ksort($attributes);
        ksort($metadata);

        return hash('sha256', json_encode([$model->getTable(), $attributes, $field, $metadata], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $metadata */
    private function approved(Model $model, string $field, array $metadata = []): bool
    {
        $approval = $this->approval($model, $field);

        return ($approval['approved'] ?? false) === true && is_string($approval['fingerprint'] ?? null)
            && hash_equals(self::fingerprint($model, $field, $metadata), $approval['fingerprint']);
    }

    /** @return array<string, mixed> */
    private function approval(Model $model, string $field): array
    {
        $type = match ($model->getTable()) {
            'majors' => 'major', 'admission_methods' => 'method', default => 'program',
        };

        return config('admission_chatbot.publications', [])[$type.':'.$model->getKey().':'.$field] ?? [];
    }

    private function reference(Model $model): string
    {
        return substr(hash_hmac('sha256', $model->getTable().':'.$model->getKey(), (string) config('app.key')), 0, 24);
    }

    private function demo(string $code): bool
    {
        return str_starts_with(strtoupper($code), 'DEMO-');
    }

    private function date(AdmissionRound $round, string $field): string
    {
        return CarbonImmutable::parse($round->getRawOriginal($field), config('app.timezone'))->format('d/m/Y H:i');
    }
}
