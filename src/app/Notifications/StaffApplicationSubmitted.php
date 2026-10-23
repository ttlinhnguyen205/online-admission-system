<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class StaffApplicationSubmitted extends Notification
{
    public function __construct(public int $applicationId, public string $applicationCode) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{application_id: int, title: string, message: string} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'application_id' => $this->applicationId,
            'title' => 'Có hồ sơ mới cần xét duyệt',
            'message' => 'Hồ sơ '.$this->applicationCode.' vừa được nộp. Vui lòng kiểm tra trong danh sách hồ sơ xét tuyển.',
        ];
    }
}
