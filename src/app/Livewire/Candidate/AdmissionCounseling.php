<?php

namespace App\Livewire\Candidate;

use App\Actions\AnswerAdmissionQuestion;
use App\Support\AdmissionCounselingFailure;
use App\Support\AdmissionCounselingHistory;
use App\Support\CandidateFunctionGuide;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

#[Title('Tư vấn tuyển sinh')]
class AdmissionCounseling extends CandidatePage
{
    public string $question = '';

    #[Locked]
    public bool $floating = false;

    #[Locked]
    public string $screen = 'overview';

    #[Locked]
    public string $revision = '';

    #[Locked]
    public ?string $failure = null;

    public function mount(AdmissionCounselingHistory $history, CandidateFunctionGuide $guide, bool $floating = false, string $screen = ''): void
    {
        $this->floating = $floating;
        $this->screen = $guide->screen($screen !== '' ? $screen : request()->route()?->getName());
        $this->revision = $history->read($this->candidate())['revision'];
    }

    public function send(AnswerAdmissionQuestion $counseling, AdmissionCounselingHistory $history): void
    {
        $this->resetValidation();
        $this->failure = null;
        try {
            $counseling->answer($this->candidate(), $this->question, $this->revision, $this->screen);
            $this->question = '';
            $this->revision = $history->read($this->candidate())['revision'];
            $this->dispatch('counseling-answered');
        } catch (AdmissionCounselingFailure $failure) {
            $this->failure = $failure->displayMessage();
            if ($failure->reason === 'stale') {
                $this->revision = $history->read($this->candidate())['revision'];
            }
        }
    }

    public function clear(AdmissionCounselingHistory $history): void
    {
        $user = $this->candidate();
        $lock = Cache::lock('admission-chat:user:'.$user->id, 45);
        if (! $lock->get()) {
            $this->failure = (new AdmissionCounselingFailure('busy'))->displayMessage();

            return;
        }
        try {
            $history->clear($user);
            $this->revision = $history->read($user)['revision'];
            $this->question = '';
            $this->failure = null;
            $this->resetValidation();
        } finally {
            $lock->release();
        }
    }

    public function render(AdmissionCounselingHistory $history, CandidateFunctionGuide $guide): View
    {
        return view('livewire.candidate.admission-counseling', [
            'turns' => $history->read($this->candidate())['turns'],
            'screenLabel' => $guide->label($this->screen),
            'suggestions' => $guide->suggestions($this->screen),
            'enabled' => (bool) config('admission_chatbot.enabled'),
            'ready' => config('admission_chatbot.enabled')
                && config('admission_chatbot.provider') === 'gemini'
                && filled(config('services.gemini.key')) && filled(config('services.gemini.model')),
        ]);
    }
}
