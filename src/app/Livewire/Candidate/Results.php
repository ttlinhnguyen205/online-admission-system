<?php

namespace App\Livewire\Candidate;

use App\Actions\ConfirmAdmissionResult;
use App\Models\AdmissionResult;
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
        return view('livewire.candidate.results', [
            'nativeResults' => Schema::hasTable('native_result_entries') ? NativeResultEntry::query()->visibleToCandidate($this->candidate())
                ->with('version.admissionRound')->orderByDesc('id')->paginate(25, pageName: 'nativePage') : null,
            'results' => AdmissionResult::query()->visibleToCandidate($this->candidate())
                ->with(['admissionWish.application.admissionRound', 'admissionWish.admissionProgram.major', 'admissionWish.admissionProgram.admissionMethod'])
                ->orderByDesc('published_at')->orderBy('id')->paginate(25),
        ]);
    }
}
