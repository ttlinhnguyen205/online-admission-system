<?php

namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\CandidateExamResult;
use App\Models\CandidateExamSubjectScore;
use App\Models\CandidateTranscript;
use App\Models\CandidateTranscriptScore;
use App\Models\EvaluationRuleVersion;
use App\Models\NativeMethodEvaluation;
use App\Models\WishMethodBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class NativeAdmissionScoring
{
    public const ALGORITHM = 'native-three-subject-sum-v1';

    public function __construct(private EvaluationTemplateRegistry $templates) {}

    /** @return list<NativeMethodEvaluation> */
    public function score(int $applicationId): array
    {
        AdmissionReviewSnapshot::reviewer();
        $this->ensure(Schema::hasTable('native_method_evaluations'), 'Migration tính điểm native chưa được áp dụng.');

        return DB::transaction(function () use ($applicationId): array {
            AdmissionCatalogLock::acquire();
            $application = (new AdmissionReviewSnapshot)->load($applicationId, true);
            Gate::authorize('scoreNative', $application);
            $snapshot = $application->submissionSnapshots()->orderByDesc('submission_version')->lockForUpdate()->first();
            $this->ensure($snapshot !== null && $snapshot->sealed_at !== null && $snapshot->registration_mode === 'native', 'Cần snapshot native đã niêm phong.');
            assert($snapshot !== null);
            $entries = $snapshot->entries()->orderBy('priority')->lockForUpdate()->get();
            $manifest = $snapshot->getAttribute('manifest');
            $this->ensure($entries->isNotEmpty() && $manifest === $entries->pluck('payload')->all()
                && hash_equals($snapshot->content_hash, NativeWishRegistration::hash($manifest)), 'Snapshot/manifest không toàn vẹn.');
            $results = [];
            foreach ($entries as $entry) {
                $this->ensure($entry->application_id === $application->id && $entry->admission_round_id === $application->admission_round_id
                    && hash_equals($entry->content_hash, NativeWishRegistration::hash($entry->getAttribute('payload'))), 'Wish entry không toàn vẹn.');
                $bindings = $entry->bindings()->orderBy('id')->lockForUpdate()->get();
                $this->ensure($bindings->isNotEmpty() && $entry->getAttribute('payload')['methods'] === $bindings->pluck('catalog_reference')->all(), 'Method bindings không khớp manifest.');
                foreach ($bindings as $binding) {
                    $ref = $binding->getAttribute('catalog_reference');
                    $this->ensure(hash_equals($binding->binding_hash, NativeWishRegistration::hash($ref))
                        && $binding->admission_round_id === $entry->admission_round_id && $binding->major_id === $entry->major_id
                        && $ref['rule_id'] === $binding->evaluation_rule_version_id && $ref['method_id'] === $binding->admission_method_id
                        && $ref['program_id'] === $binding->admission_program_id, 'Pinned binding không toàn vẹn.');
                    $result = $this->evaluate($binding, $application->candidate_profile_id);
                    $fingerprint = NativeWishRegistration::hash([$snapshot->content_hash, $binding->binding_hash, self::ALGORITHM, $result]);
                    $stored = NativeMethodEvaluation::query()->where('wish_method_binding_id', $binding->id)->where('algorithm_version', self::ALGORITHM)->lockForUpdate()->first();
                    if ($stored !== null && hash_equals($stored->input_fingerprint, $fingerprint)) {
                        $results[] = $stored;

                        continue;
                    }
                    $stored ??= new NativeMethodEvaluation;
                    $stored->fill(['wish_method_binding_id' => $binding->id, 'evaluation_rule_version_id' => $binding->evaluation_rule_version_id,
                        'algorithm_version' => self::ALGORITHM, 'input_fingerprint' => $fingerprint, ...$result,
                        'evaluated_by' => auth()->id(), 'evaluated_at' => now()]);
                    $stored->save();
                    ActivityLog::query()->create(['user_id' => auth()->id(), 'action' => 'native_scoring.evaluated', 'subject_type' => $application->getMorphClass(),
                        'subject_id' => $application->id, 'new_values' => ['binding_id' => $binding->id, 'rule_id' => $binding->evaluation_rule_version_id,
                            'status' => $stored->status, 'score' => $stored->score, 'input_fingerprint' => $fingerprint, 'algorithm' => self::ALGORITHM], 'created_at' => now()]);
                    $results[] = $stored;
                }
            }

            return $results;
        }, 3);
    }

    /**
     * Call within the scoring transaction: profile locking serializes normalized source changes.
     *
     * @return array{status: string, score: ?string, provenance: array<string, mixed>, reasons: list<string>}
     */
    public function evaluate(WishMethodBinding $binding, int $profileId): array
    {
        $rule = EvaluationRuleVersion::query()->lockForUpdate()->findOrFail($binding->evaluation_rule_version_id);
        $provenance = ['rule_id' => $rule->id, 'rule_hash' => $rule->content_hash, 'sources' => []];
        $definition = $this->templates->templates()[$rule->template_identifier] ?? null;
        if ($definition === null || ! $definition['supported'] || $rule->template_version !== 1
            || ! in_array($rule->template_identifier, ['THPT_SCORE', 'TRANSCRIPT_SCORE'], true)) {
            return $this->result('unsupported', null, $provenance, 'Template/phiên bản chưa được hỗ trợ tính điểm native.');
        }
        try {
            $this->templates->validate($rule->template_identifier, $rule->template_version, $rule->payload);
        } catch (ValidationException) {
            return $this->result('needs_resolution', null, $provenance, 'Payload rule đã pin không hợp lệ.');
        }
        if (! in_array($rule->status, ['approved', 'retired'], true) || $rule->approved_at === null || $rule->approved_by === null
            || $rule->admission_method_id !== $binding->admission_method_id || $rule->ranking_contract !== $definition['ranking_contract']
            || $rule->ranking_contract_version !== 1 || ! hash_equals($rule->content_hash, NativeWishRegistration::hash($rule->payload))
            || $binding->getAttribute('catalog_reference')['rule_hash'] !== $rule->content_hash
            || $binding->getAttribute('catalog_reference')['template'] !== [$rule->template_identifier, $rule->template_version]
            || $binding->getAttribute('catalog_reference')['ranking_contract'] !== [$rule->ranking_contract, $rule->ranking_contract_version]) {
            return $this->result('needs_resolution', null, $provenance, 'Rule đã pin không toàn vẹn hoặc chưa từng được phê duyệt.');
        }
        $transcript = $rule->template_identifier === 'TRANSCRIPT_SCORE';
        $sources = $transcript
            ? CandidateTranscript::query()->where('candidate_profile_id', $profileId)->orderBy('id')->lockForUpdate()->get()
            : CandidateExamResult::query()->where('candidate_profile_id', $profileId)->where('exam_type', 'thpt')->orderBy('id')->lockForUpdate()->get();
        $valid = [];
        foreach ($sources as $source) {
            $scores = $transcript
                ? CandidateTranscriptScore::query()->where('candidate_transcript_id', $source->id)->where('grade_level', (string) $rule->payload['grade_level'])->whereIn('subject_code', $rule->payload['subjects'])->orderBy('subject_code')->orderBy('id')->lockForUpdate()->get()
                : CandidateExamSubjectScore::query()->where('candidate_exam_result_id', $source->id)->whereIn('subject_code', $rule->payload['subjects'])->orderBy('subject_code')->orderBy('id')->lockForUpdate()->get();
            $data = ['type' => $transcript ? 'candidate_transcripts' : 'candidate_exam_results', 'id' => $source->id,
                'year' => $source->getAttribute($transcript ? 'graduation_year' : 'exam_year'), 'status' => $source->getRawOriginal('status'),
                'verified_by' => $source->verified_by, 'verified_at' => $source->getRawOriginal('verified_at'), 'updated_at' => $source->getRawOriginal('updated_at'),
                'grade_level' => $transcript ? $rule->payload['grade_level'] : null,
                'scores' => $scores->map(fn ($score): array => ['id' => $score->id, 'subject' => $score->subject_code, 'score' => $score->getRawOriginal('score'), 'updated_at' => $score->getRawOriginal('updated_at')])->all()];
            $provenance['sources'][] = $data;
            if ((int) $data['year'] === $rule->payload['source_year'] && $data['status'] === 'verified' && $data['verified_by'] !== null && $data['verified_at'] !== null) {
                $valid[] = $data;
            }
        }
        if (count($valid) > 1) {
            return $this->result('needs_resolution', null, $provenance, 'Có nhiều nguồn đã xác minh đúng năm; chưa có chính sách chọn nguồn.');
        }
        if ($valid === []) {
            return $this->result('pending_data', null, $provenance, 'Thiếu nguồn đã xác minh đầy đủ đúng năm '.$rule->payload['source_year'].'.');
        }
        $provenance['selected_source_id'] = $valid[0]['id'];
        $values = [];
        foreach ($rule->payload['subjects'] as $subject) {
            $rows = array_values(array_filter($valid[0]['scores'], fn (array $row): bool => $row['subject'] === $subject));
            if (count($rows) > 1) {
                return $this->result('needs_resolution', null, $provenance, 'Nguồn có điểm môn trùng lặp: '.$subject.'.');
            }
            if ($rows === [] || ! is_numeric($rows[0]['score']) || (float) $rows[0]['score'] < 0 || (float) $rows[0]['score'] > 10) {
                return $this->result('pending_data', null, $provenance, 'Thiếu điểm hợp lệ trong thang 0–10: '.$subject.'.');
            }
            $values[] = (int) round((float) $rows[0]['score'] * 1000);
        }
        $total = array_sum($values);
        if ($values === []) {
            return $this->result('needs_resolution', null, $provenance, 'Rule không có tổ hợp môn hợp lệ.');
        }
        $eligible = min($values) / 1000 >= $rule->payload['minimum_subject_score']
            && $total / 1000 >= $rule->payload['minimum_total_score'];

        return $this->result($eligible ? 'eligible' : 'ineligible', number_format($total / 1000, 3, '.', ''), $provenance,
            $eligible ? 'Đạt ngưỡng từng môn và tổng ba môn hệ số 1 (thang 30).' : 'Không đạt ngưỡng từng môn hoặc tổng theo rule đã pin.');
    }

    /** @param array<string, mixed> $provenance
     * @return array{status: string, score: ?string, provenance: array<string, mixed>, reasons: list<string>}
     */
    private function result(string $status, ?string $score, array $provenance, string $reason): array
    {
        return ['status' => $status, 'score' => $score, 'provenance' => $provenance, 'reasons' => [$reason]];
    }

    private function ensure(bool $valid, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['nativeScoring' => $message]);
        }
    }
}
