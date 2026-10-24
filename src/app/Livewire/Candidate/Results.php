<?php

namespace App\Livewire\Candidate;

use App\Actions\ConfirmAdmissionResult;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\NativeResultEntry;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
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
        $candidate = $this->candidate();
        $resultRounds = AdmissionRound::query()
            ->whereHas('applications.wishes.result', fn ($query) => $query->visibleToCandidate($candidate))
            ->orderByDesc('year')->orderByDesc('id')->paginate(5);

        return view('livewire.candidate.results', [
            'resultRounds' => $resultRounds,
            'nativeResults' => Schema::hasTable('native_result_entries') ? NativeResultEntry::query()->visibleToCandidate($this->candidate())
                ->with('version.admissionRound')->orderByDesc('id')->paginate(25, pageName: 'nativePage') : null,
            'results' => AdmissionResult::query()->visibleToCandidate($candidate)
                ->whereHas('admissionWish.application', fn ($query) => $query->whereIn('admission_round_id', $resultRounds->pluck('id')))
                ->with(['admissionWish.application.admissionRound', 'admissionWish.admissionProgram.major', 'admissionWish.admissionProgram.admissionMethod'])
                ->orderBy('id')->get(),
        ]);
    }
}
