<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class CandidateScoreVerified extends Notification
{
    public function __construct(public int $scoreId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, int|string> */
    public function toDatabase(object $notifiable): array
    {
        return ['score_id' => $this->scoreId];
    }
}
