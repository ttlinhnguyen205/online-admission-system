<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Thông báo')]
class Notifications extends Component
{
    use WithPagination;

    public function boot(): void
    {
        $this->currentUser();
    }

    public function markRead(string $notificationId): void
    {
        $user = $this->currentUser();
        $notification = $user->notifications()->findOrFail($notificationId);
        $user->notifications()->whereKey($notification->id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function render(): View
    {
        $user = $this->currentUser();
        $notifications = $user->notifications()->orderByDesc('created_at')->orderBy('id')->paginate(20);

        return view('livewire.notifications', [
            'notifications' => $notifications,
            'unreadCount' => $user->unreadNotifications()->count(),
            'details' => $notifications->getCollection()->mapWithKeys(fn (DatabaseNotification $notification): array => [
                $notification->getKey() => $this->details($notification),
            ]),
        ]);
    }

    private function currentUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $user->refresh();
        abort_unless($user->isActive() && $user->hasVerifiedEmail(), 403);

        return $user;
    }

    /** @return array{title: string, message: ?string} */
    private function details(DatabaseNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $message = $data['message'] ?? $data['body'] ?? $data['reason'] ?? null;

        return [
            'title' => is_string($data['title'] ?? null) ? $data['title'] : __('Thông báo hệ thống'),
            'message' => is_string($message) ? $message : null,
        ];
    }
}
