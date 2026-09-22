<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class AdmissionResultsPublished extends Notification
{
    public function __construct(public int $roundId, public string $roundName, public int $applicationId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, int|string> */
    public function toDatabase(object $notifiable): array
    {
        return ['round_id' => $this->roundId, 'round_name' => $this->roundName,
            'application_id' => $this->applicationId, 'message' => 'Kết quả xét tuyển đã được công bố.'];
    }
}
