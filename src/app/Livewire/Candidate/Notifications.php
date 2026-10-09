<?php

namespace App\Livewire\Candidate;

use App\Support\CandidateNotificationDetails;
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

    public function openNotification(string $notificationId): void
    {
        $notification = $this->candidate()->notifications()->findOrFail($notificationId);
        $url = $this->details($notification)['url'];
        $this->markRead($notificationId);

        if ($url !== null) {
            $this->redirect($url, navigate: true);
        }
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
        return app(CandidateNotificationDetails::class)->for($this->candidate(), $notification);
    }
}
