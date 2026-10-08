<?php

namespace App\Livewire\Candidate;

use App\Actions\AnswerAdmissionQuestion;
use App\Support\AdmissionCounselingFailure;
use App\Support\AdmissionCounselingHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

#[Title('Tư vấn tuyển sinh')]
class AdmissionCounseling extends CandidatePage
{
    public string $question = '';

    public bool $consent = false;

    #[Locked]
    public string $revision = '';

    #[Locked]
    public ?string $failure = null;

    public function mount(AdmissionCounselingHistory $history): void
    {
        $this->revision = $history->read($this->candidate())['revision'];
    }

    public function send(AnswerAdmissionQuestion $counseling, AdmissionCounselingHistory $history): void
    {
        $this->resetValidation();
        $this->failure = null;
        try {
            $counseling->answer($this->candidate(), $this->question, $this->revision, $this->consent);
            $this->question = '';
            $this->revision = $history->read($this->candidate())['revision'];
            $this->dispatch('counseling-answered');
        } catch (AdmissionCounselingFailure $failure) {
            $this->failure = $failure->displayMessage();
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

    public function render(AdmissionCounselingHistory $history): View
    {
        return view('livewire.candidate.admission-counseling', [
            'turns' => $history->read($this->candidate())['turns'],
            'ready' => config('admission_chatbot.enabled')
                && config('admission_chatbot.provider') === 'gemini'
                && filled(config('services.gemini.key')) && filled(config('services.gemini.model')),
            'suggestions' => [
                'Hiện tại trường đang tuyển sinh những ngành nào?',
                'Đợt tuyển sinh nào đang mở?',
                'Hạn đăng ký nguyện vọng là ngày nào?',
                'Ngành Công nghệ thông tin có đang nhận đăng ký không?',
                'Học phí ngành Công nghệ thông tin là bao nhiêu?',
            ],
        ]);
    }
}
