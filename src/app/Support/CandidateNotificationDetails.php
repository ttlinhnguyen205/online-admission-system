<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\AdmissionResultsPublished;
use App\Notifications\ApplicationReviewStarted;
use App\Notifications\ApplicationRevisionRequested;
use App\Notifications\ApplicationSubmitted;
use App\Notifications\CandidateScoreVerified;
use Illuminate\Notifications\DatabaseNotification;

class CandidateNotificationDetails
{
    /** @return array{title: string, message: ?string, action: ?string, url: ?string} */
    public function for(User $candidate, DatabaseNotification $notification): array
    {
        abort_unless($candidate->notifications()->whereKey($notification->id)->exists(), 404);
        $data = $notification->data;
        $applicationId = $data['application_id'] ?? null;
        $scoreId = $data['score_id'] ?? null;
        $profile = $candidate->candidateProfile;
        $applicationOwned = is_int($applicationId) && $profile?->applications()->whereKey($applicationId)->exists();
        $scoreOwned = is_int($scoreId) && $profile?->scores()->whereKey($scoreId)->exists();

        return match ($notification->type) {
            ApplicationSubmitted::class => $this->applicationDetails('Hồ sơ đã được tiếp nhận', null, $applicationOwned ? $applicationId : null),
            ApplicationReviewStarted::class => $this->applicationDetails('Hồ sơ đang được xét duyệt', null, $applicationOwned ? $applicationId : null),
            ApplicationRevisionRequested::class => $this->applicationDetails('Hồ sơ cần bổ sung', is_string($data['reason'] ?? null) ? $data['reason'] : null, $applicationOwned ? $applicationId : null),
            CandidateScoreVerified::class => [
                'title' => 'Minh chứng điểm đã được xác minh', 'message' => null,
                'action' => $scoreOwned ? 'Xem thông tin tuyển sinh' : null,
                'url' => $scoreOwned ? route('candidate.admission-information.index') : null,
            ],
            AdmissionResultsPublished::class => [
                'title' => 'Kết quả xét tuyển đã được công bố',
                'message' => is_string($data['round_name'] ?? null) ? $data['round_name'] : null,
                'action' => $applicationOwned ? 'Xem kết quả xét tuyển' : null,
                'url' => $applicationOwned ? route('candidate.results.index') : null,
            ],
            default => ['title' => 'Thông báo', 'message' => null, 'action' => null, 'url' => null],
        };
    }

    /** @return array{title: string, message: ?string, action: ?string, url: ?string} */
    private function applicationDetails(string $title, ?string $message, ?int $applicationId): array
    {
        return [
            'title' => $title, 'message' => $message,
            'action' => $applicationId === null ? null : 'Xem hồ sơ',
            'url' => $applicationId === null ? null : route('candidate.applications.show', $applicationId),
        ];
    }
}
