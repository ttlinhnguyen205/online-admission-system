<?php

namespace App\Support;

use App\Actions\CandidateFiles;
use App\Models\Application;
use App\Models\CandidateTranscript;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AdmissionSourceEvidence
{
    public const TYPES = [
        'exam-results' => 'Kết quả kỳ thi',
        'transcripts' => 'Học bạ',
        'certificates' => 'Chứng chỉ',
        'admission-claims' => 'Xét tuyển thẳng / ưu tiên',
    ];

    /** @return Collection<string, Model> */
    public static function load(Application $application, bool $lock = false): Collection
    {
        $records = collect();
        if ($application->registration_mode !== 'legacy' || $application->submitted_at === null || $application->getRawOriginal('status') === 'draft') {
            return $records;
        }
        $profile = $application->candidateProfile;
        if ($profile === null) {
            return $records;
        }
        foreach (['exam-results' => 'examResults', 'transcripts' => 'transcripts', 'certificates' => 'certificates', 'admission-claims' => 'admissionClaims'] as $type => $relation) {
            foreach ($profile->{$relation}()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get() as $source) {
                if ($type === 'exam-results') {
                    $source->setRelation('subjectScores', $source->subjectScores()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get());
                }
                if ($source instanceof CandidateTranscript) {
                    $source->setRelation('scores', $source->scores()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get());
                    $source->setRelation('evidenceImages', $source->evidenceImages()->when($lock, fn ($query) => $query->lockForUpdate())->get());
                }
                $records->put($type.':'.$source->id, $source);
            }
        }

        return $records;
    }

    /** @return list<array{type: string, id: int, available: bool}> */
    public static function files(string $type, Model $source): array
    {
        $files = [];
        if ($source->getAttribute('evidence_path')) {
            $files[] = ['type' => $type, 'id' => $source->id, 'available' => self::available($source->evidence_path, 'candidate-'.$type.'/'.$source->candidate_profile_id.'/')];
        }
        if ($source instanceof CandidateTranscript) {
            foreach ($source->evidenceImages as $image) {
                $files[] = ['type' => 'transcript-images', 'id' => $image->id, 'available' => self::available($image->path, 'candidate-transcripts/'.$source->candidate_profile_id.'/')];
            }
        }

        return $files;
    }

    public static function available(string $path, string $prefix): bool
    {
        // Legacy imports used flat filenames; nested uploads must still belong to this profile.
        if (! CandidateFiles::safePath($path) || (! str_starts_with($path, $prefix)
            && dirname($path) !== dirname(rtrim($prefix, '/')))) {
            return false;
        }
        try {
            $disk = Storage::disk(CandidateFiles::DISK);

            return $disk->exists($path) && in_array($disk->mimeType($path), ['image/jpeg', 'image/png'], true);
        } catch (Throwable) {
            return false;
        }
    }
}
