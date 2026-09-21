<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ApplicationReviewStarted extends Notification
{
    public function __construct(public int $applicationId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, int|string> */
    public function toDatabase(object $notifiable): array
    {
        return ['application_id' => $this->applicationId];
    }
}
