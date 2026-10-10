<?php

namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\CandidateExamResult;
use App\Models\CandidateTranscript;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class NativeSourceVerification
{
    /** @return array{attributes: array<string, mixed>, scores: list<array<string, mixed>>, images: list<array<string, mixed>>} */
    public function inspect(CandidateExamResult|CandidateTranscript $source, bool $lock = false): array
    {
        $transcript = $source instanceof CandidateTranscript;
        $scores = ($transcript ? $source->scores() : $source->subjectScores())->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $images = $transcript ? $source->evidenceImages()->when($lock, fn ($q) => $q->lockForUpdate())->get() : collect();

        return ['attributes' => $source->getAttributes(), 'scores' => array_values($scores->map->getAttributes()->all()), 'images' => array_values($images->map->getAttributes()->all())];
    }

    public function verify(int $applicationId, string $type, int $sourceId, string $expected): void
    {
        AdmissionReviewSnapshot::reviewer();
        abort_unless(in_array($type, ['thpt', 'transcript'], true), 404);
        DB::transaction(function () use ($applicationId, $type, $sourceId, $expected): void {
            AdmissionCatalogLock::acquire();
            $application = (new AdmissionReviewSnapshot)->load($applicationId, true);
            Gate::authorize('scoreNative', $application);
            $source = $type === 'thpt' ? $application->candidateProfile->examResults()->where('exam_type', 'thpt')->lockForUpdate()->findOrFail($sourceId)
                : $application->candidateProfile->transcripts()->lockForUpdate()->findOrFail($sourceId);
            $data = $this->inspect($source, true);
            $this->ensure(hash_equals($expected, NativeWishRegistration::hash($data)), 'Nguồn đã thay đổi; tải lại và kiểm tra minh chứng.');
            $this->ensure($source->getRawOriginal('status') === 'pending', 'Chỉ xác minh nguồn đang pending.');
            $snapshot = $application->submissionSnapshots()->orderByDesc('submission_version')->with('entries.bindings.rule')->firstOrFail();
            $this->ensure($snapshot->sealed_at !== null, 'Cần snapshot đã niêm phong.');
            $rules = $snapshot->entries->flatMap(fn ($entry) => $entry->bindings)->map->rule
                ->filter(fn ($rule) => $rule->template_identifier === ($type === 'thpt' ? 'THPT_SCORE' : 'TRANSCRIPT_SCORE'));
            $matched = false;
            foreach ($rules as $rule) {
                if ((int) $source->getAttribute($type === 'thpt' ? 'exam_year' : 'graduation_year') !== $rule->payload['source_year']) {
                    continue;
                }
                $scores = collect($data['scores'])->filter(fn (array $row): bool => in_array($row['subject_code'], $rule->payload['subjects'], true)
                    && ($type === 'thpt' || (int) $row['grade_level'] === $rule->payload['grade_level']));
                if ($scores->count() === 3 && $scores->pluck('subject_code')->unique()->count() === 3
                    && $scores->every(fn (array $row): bool => is_numeric($row['score']) && $row['score'] >= 0 && $row['score'] <= 10)) {
                    $matched = true;
                }
            }
            $this->ensure($matched, 'Nguồn không khớp năm/lớp/tổ hợp hợp lệ của rule đã pin.');
            $paths = array_filter([$source->getAttribute('evidence_path'), ...array_column($data['images'], 'path')]);
            $this->ensure($paths !== [], 'Thiếu ảnh minh chứng.');
            foreach ($paths as $path) {
                $this->ensure(CandidateFiles::safePath($path) && str_starts_with($path, ($type === 'thpt' ? 'candidate-exam-results/' : 'candidate-transcripts/').$application->candidate_profile_id.'/')
                    && Storage::disk(CandidateFiles::DISK)->exists($path) && in_array(Storage::disk(CandidateFiles::DISK)->mimeType($path), ['image/jpeg', 'image/png'], true), 'Ảnh minh chứng không hợp lệ/không truy cập được.');
            }
            $source->update(['status' => 'verified', 'verified_by' => auth()->id(), 'verified_at' => now(), 'rejection_reason' => null]);
            ActivityLog::query()->create(['user_id' => auth()->id(), 'action' => 'native_source.verified', 'subject_type' => $source->getMorphClass(),
                'subject_id' => $source->id, 'new_values' => ['application_id' => $application->id, 'source_fingerprint' => $expected, 'verified_by' => auth()->id()], 'created_at' => now()]);
        }, 3);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['sourceVerification' => $message]);
        }
    }
}
