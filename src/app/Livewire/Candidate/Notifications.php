<?php

namespace App\Livewire\Candidate;

use App\Notifications\AdmissionResultsPublished;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

#[Title('Notifications')]
class Notifications extends CandidatePage
{
    use WithPagination;

    public function markRead(string $notificationId): void
    {
        $notification = $this->candidate()->notifications()->where('type', AdmissionResultsPublished::class)->findOrFail($notificationId);
        $this->candidate()->notifications()->whereKey($notification->id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function render(): View
    {
        return view('livewire.candidate.notifications', [
            'notifications' => $this->candidate()->notifications()->where('type', AdmissionResultsPublished::class)
                ->orderByDesc('created_at')->orderBy('id')->paginate(20),
        ]);
    }
}
