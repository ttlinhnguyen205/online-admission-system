<?php

namespace App\Http\Controllers;

use App\Actions\AdmissionStatistics;
use App\Models\AdmissionResult;
use App\Models\Application;
use App\Models\User;
use App\Support\AdmissionReportFilters;
use App\Support\AdmissionReportWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AdmissionReportController extends Controller
{
    public function __invoke(Request $request, string $format, AdmissionStatistics $statistics, AdmissionReportWriter $writer): StreamedResponse
    {
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $actor = $statistics->authorize($user);
        Gate::forUser($actor)->authorize('export', Application::class);
        Gate::forUser($actor)->authorize('export', AdmissionResult::class);
        $filters = AdmissionReportFilters::from($request->query());
        $disk = Storage::disk('candidate-private');
        $directory = 'reports/'.Str::uuid();
        $disk->makeDirectory($directory);
        $path = $disk->path($directory.'/report.'.$format);
        try {
            $writer->write($actor, $filters, $format, $path);
        } catch (Throwable $exception) {
            $disk->deleteDirectory($directory);
            throw $exception;
        }

        return response()->streamDownload(function () use ($actor, $statistics, $disk, $directory, $path): void {
            $handle = null;
            try {
                $current = $statistics->authorize($actor);
                abort_unless($current->role === $actor->role, 403);
                Gate::forUser($current)->authorize('export', Application::class);
                Gate::forUser($current)->authorize('export', AdmissionResult::class);
                $handle = fopen($path, 'rb');
                abort_if($handle === false, 500);
                while (! feof($handle)) {
                    echo fread($handle, 16384);
                }
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                $disk->deleteDirectory($directory);
            }
        }, 'bao-cao-tuyen-sinh-'.now()->format('Ymd-His').'.'.$format, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
