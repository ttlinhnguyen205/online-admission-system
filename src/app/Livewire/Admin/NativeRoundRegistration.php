<?php

namespace App\Livewire\Admin;

use App\Actions\NativeRoundActivation;
use App\Models\AdmissionRound;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NativeRoundRegistration extends Component
{
    #[Locked]
    public int $roundId;

    #[Locked]
    public string $fingerprint = '';

    #[Locked]
    public string $pendingAction = '';

    public bool $showConfirmation = false;

    public bool $confirmed = false;

    public string $confirmationCode = '';

    public string $reason = '';

    public function boot(): void
    {
        Auth::user()?->refresh();
        $actor = Auth::user();
        abort_unless($actor instanceof User && $actor->isAdmin() && $actor->hasVerifiedEmail() && $actor->isActive(), 403);
    }

    public function mount(int $roundId): void
    {
        $this->roundId = $roundId;
        $this->checkConditions();
    }

    private function round(): AdmissionRound
    {
        $round = AdmissionRound::query()->findOrFail($this->roundId);
        Gate::authorize('manageNativeRegistration', $round);

        return $round;
    }

    public function checkConditions(): void
    {
        $this->resetValidation();
        $this->fingerprint = app(NativeRoundActivation::class)->check($this->round())['fingerprint'];
        $this->pendingAction = '';
        $this->showConfirmation = false;
    }

    public function confirm(string $action): void
    {
        $this->round();
        abort_unless(in_array($action, ['prepare', 'activate', 'close'], true), 422);
        $this->resetValidation();
        $this->pendingAction = $action;
        $this->confirmationCode = '';
        $this->reason = '';
        $this->confirmed = false;
        $this->showConfirmation = true;
    }

    public function apply(NativeRoundActivation $activation): void
    {
        abort_unless($this->showConfirmation && $this->pendingAction !== '', 403);
        $activation->transition($this->roundId, $this->pendingAction, $this->fingerprint, $this->confirmationCode, $this->confirmed, $this->reason);
        $this->checkConditions();
        $this->dispatch('native-registration-changed');
    }

    public function render(): View
    {
        $round = $this->round();

        return view('livewire.admin.native-round-registration', ['round' => $round,
            'check' => app(NativeRoundActivation::class)->check($round),
            'activator' => User::query()->find($round->getAttribute('native_activated_by'))]);
    }
}
