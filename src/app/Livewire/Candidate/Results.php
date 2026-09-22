<?php

namespace App\Livewire\Candidate;

use App\Actions\ConfirmAdmissionResult;
use App\Models\AdmissionResult;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

#[Title('Kết quả xét tuyển')]
class Results extends CandidatePage
{
    use WithPagination;

    public function confirm(int $resultId, ConfirmAdmissionResult $confirmation): void
    {
        $this->candidate();
        $confirmation->confirm($resultId);
        Flux::toast(variant: 'success', text: __('Đã xác nhận nhập học.'));
    }

    public function render(): View
    {
        return view('livewire.candidate.results', [
            'results' => AdmissionResult::query()->visibleToCandidate($this->candidate())
                ->with(['admissionWish.application.admissionRound', 'admissionWish.admissionProgram.major', 'admissionWish.admissionProgram.admissionMethod'])
                ->orderByDesc('published_at')->orderBy('id')->paginate(25),
        ]);
    }
}
