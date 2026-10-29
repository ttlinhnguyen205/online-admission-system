<?php

namespace App\Http\Controllers;

use App\Actions\AdmissionReviewSnapshot;
use App\Actions\CandidateFiles;
use App\Models\CandidateAdmissionClaim;
use App\Models\CandidateCertificate;
use App\Models\CandidateExamResult;
use App\Models\CandidateTranscript;
use App\Models\CandidateTranscriptEvidence;
use App\Models\User;
use App\Support\AdmissionSourceEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateAdmissionEvidenceController extends Controller
{
    public function __invoke(Request $request, string $type, int $record): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        if (! $user->isCandidate()) {
            AdmissionReviewSnapshot::reviewer();
            if (! $request->has('application')) {
                abort_unless(in_array($type, ['exam-results', 'transcripts', 'transcript-images'], true), 403);
            }
            $model = match ($type) {
                'exam-results' => CandidateExamResult::query()->findOrFail($record),
                'transcripts' => CandidateTranscript::query()->findOrFail($record),
                'transcript-images' => CandidateTranscriptEvidence::query()->with('transcript')->findOrFail($record),
                'certificates' => CandidateCertificate::query()->findOrFail($record),
                'admission-claims' => CandidateAdmissionClaim::query()->findOrFail($record),
                default => abort(403),
            };
            $source = $model instanceof CandidateTranscriptEvidence ? $model->transcript : $model;
            if (! $request->has('application') && $source instanceof CandidateExamResult) {
                abort_unless($source->getRawOriginal('exam_type') === 'thpt', 404);
            }
            $applications = $source->candidateProfile->applications()->whereNotNull('submitted_at')->where('status', '!=', 'draft');
            $application = $request->has('application')
                ? $applications->findOrFail($request->integer('application'))
                : $applications->where('registration_mode', 'native')->firstOrFail();
            Gate::authorize('view', $application);
            if ($application->registration_mode === 'native') {
                abort_unless($source instanceof CandidateTranscript || ($source instanceof CandidateExamResult && $source->getRawOriginal('exam_type') === 'thpt'), 403);
            }
            $prefix = 'candidate-'.($type === 'transcript-images' ? 'transcripts' : $type).'/'.$application->candidate_profile_id.'/';
            if ($application->registration_mode === 'legacy') {
                $path = $model->getAttribute($type === 'transcript-images' ? 'path' : 'evidence_path');
                abort_unless(is_string($path) && AdmissionSourceEvidence::available($path, $prefix), 404);
                $prefix = dirname(rtrim($prefix, '/')).'/';
            }

            return $this->response($model, $prefix, $type === 'transcript-images' ? 'path' : 'evidence_path');
        }
        $profile = $user->candidateProfile()->firstOrFail();
        if ($type === 'transcript-images') {
            $image = CandidateTranscriptEvidence::query()
                ->whereHas('transcript', fn ($query) => $query->where('candidate_profile_id', $profile->id))
                ->findOrFail($record);
            Gate::authorize('view', $image->transcript);
            abort_unless(AdmissionSourceEvidence::available($image->path, 'candidate-transcripts/'.$profile->id.'/'), 404);

            return $this->response($image, 'candidate-transcripts/', 'path');
        }
        [$model, $prefix] = match ($type) {
            'exam-results' => [$profile->examResults()->findOrFail($record), 'candidate-exam-results/'],
            'transcripts' => [$profile->transcripts()->findOrFail($record), 'candidate-transcripts/'],
            'certificates' => [$profile->certificates()->findOrFail($record), 'candidate-certificates/'],
            'admission-claims' => [$profile->admissionClaims()->findOrFail($record), 'candidate-admission-claims/'],
            default => abort(404),
        };
        Gate::authorize('view', $model);

        return $this->response($model, $prefix);
    }

    private function response(Model $model, string $prefix, string $attribute = 'evidence_path'): StreamedResponse
    {
        $path = $model->getAttribute($attribute);
        abort_unless(CandidateFiles::safePath($path) && str_starts_with((string) $path, $prefix), 404);
        $disk = Storage::disk(CandidateFiles::DISK);
        abort_unless($disk->exists($path), 404);
        $mime = $disk->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png'], true), 404);

        return $disk->response($path, 'minh-chung.'.($mime === 'image/png' ? 'png' : 'jpg'), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
