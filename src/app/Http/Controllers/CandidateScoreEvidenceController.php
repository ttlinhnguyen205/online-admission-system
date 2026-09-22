<?php

namespace App\Http\Controllers;

use App\Actions\CandidateFiles;
use App\Models\CandidateScore;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateScoreEvidenceController extends Controller
{
    public function __invoke(Request $request, int $score): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        if ($user->isCandidate()) {
            $profile = $user->candidateProfile()->firstOrFail();
            $record = $profile->scores()->findOrFail($score);
        } else {
            Gate::authorize('viewAny', CandidateScore::class);
            $record = CandidateScore::query()->findOrFail($score);
        }
        Gate::authorize('view', $record);
        $path = $record->getAttribute('evidence_path');
        abort_unless(CandidateFiles::safePath($path) && str_starts_with($path, 'candidate-scores/'), 404);
        $disk = Storage::disk(CandidateFiles::DISK);
        abort_unless($disk->exists($path), 404);
        $mime = $disk->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png'], true), 404);

        return $disk->response($path, 'minh-chung-diem.'.($mime === 'image/png' ? 'png' : 'jpg'), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
