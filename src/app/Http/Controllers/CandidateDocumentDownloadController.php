<?php

namespace App\Http\Controllers;

use App\Actions\CandidateFiles;
use App\Models\CandidateDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateDocumentDownloadController extends Controller
{
    public function __invoke(Request $request, int $document): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        if ($user->isCandidate()) {
            $profile = $user->candidateProfile()->firstOrFail();
            $record = CandidateDocument::query()->whereHas('application', fn ($query) => $query->whereBelongsTo($profile))->findOrFail($document);
        } else {
            Gate::authorize('viewAny', CandidateDocument::class);
            $record = CandidateDocument::query()->findOrFail($document);
        }
        Gate::authorize('download', $record);
        $path = $record->getAttribute('file_path');
        abort_unless(CandidateFiles::safePath($path) && str_starts_with($path, 'candidate-documents/'), 404);
        $disk = Storage::disk(CandidateFiles::DISK);
        abort_unless($disk->exists($path), 404);

        return $disk->download($path, CandidateFiles::displayName($record->getAttribute('original_name')), [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
