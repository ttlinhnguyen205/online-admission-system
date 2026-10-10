<?php

namespace App\Http\Controllers;

use App\Actions\AdmissionReviewSnapshot;
use App\Actions\CandidateFiles;
use App\Models\CandidateExamResult;
use App\Models\CandidateTranscript;
use App\Models\CandidateTranscriptEvidence;
use App\Models\User;
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
            $model = match ($type) {
                'exam-results' => CandidateExamResult::query()->where('exam_type', 'thpt')->findOrFail($record),
                'transcripts' => CandidateTranscript::query()->findOrFail($record),
                'transcript-images' => CandidateTranscriptEvidence::query()->with('transcript')->findOrFail($record),
                default => abort(403),
            };
            $source = $model instanceof CandidateTranscriptEvidence ? $model->transcript : $model;
            $application = $source->candidateProfile->applications()->where('registration_mode', 'native')->whereNotNull('submitted_at')->firstOrFail();
            Gate::authorize('view', $application);
            $prefix = ($type === 'exam-results' ? 'candidate-exam-results/' : 'candidate-transcripts/').$application->candidate_profile_id.'/';

            return $this->response($model, $prefix, $type === 'transcript-images' ? 'path' : 'evidence_path');
        }
        $profile = $user->candidateProfile()->firstOrFail();
        if ($type === 'transcript-images') {
            $image = CandidateTranscriptEvidence::query()
                ->whereHas('transcript', fn ($query) => $query->where('candidate_profile_id', $profile->id))
                ->findOrFail($record);
            Gate::authorize('view', $image->transcript);

            return $this->response($image, 'candidate-transcripts/'.$profile->id.'/', 'path');
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
