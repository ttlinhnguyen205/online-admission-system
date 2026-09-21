<?php

namespace App\Livewire\Candidate;

use App\Notifications\AdmissionResultsPublished;
use App\Notifications\ApplicationReviewStarted;
use App\Notifications\ApplicationRevisionRequested;
use App\Notifications\ApplicationSubmitted;
use App\Notifications\CandidateScoreVerified;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

#[Title('Thông báo')]
class Notifications extends CandidatePage
{
    use WithPagination;

    public function markRead(string $notificationId): void
    {
        $notification = $this->candidate()->notifications()->findOrFail($notificationId);
        $this->candidate()->notifications()->whereKey($notification->id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function render(): View
    {
        $candidate = $this->candidate();
        $notifications = $candidate->notifications()->orderByDesc('created_at')->orderBy('id')->paginate(20);
        $details = $notifications->getCollection()->mapWithKeys(fn (DatabaseNotification $notification): array => [
            $notification->getKey() => $this->details($notification),
        ]);

        return view('livewire.candidate.notifications', [
            'notifications' => $notifications,
            'details' => $details,
        ]);
    }

    /** @return array{title: string, message: ?string, action: ?string, url: ?string} */
    private function details(DatabaseNotification $notification): array
    {
        $data = $notification->data;
        $applicationId = $data['application_id'] ?? null;
        $scoreId = $data['score_id'] ?? null;
        $applicationOwned = is_int($applicationId) && $this->candidate()->candidateProfile?->applications()->whereKey($applicationId)->exists();
        $scoreOwned = is_int($scoreId) && $this->candidate()->candidateProfile?->scores()->whereKey($scoreId)->exists();

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
