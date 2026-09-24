<?php

namespace App\Livewire\Candidate;

use App\Actions\CandidateApplications;
use App\Actions\CandidateMajorOfferings;
use App\Actions\CandidateWishes;
use App\Models\AdmissionProgram;
use App\Models\Application;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

#[Title('Đăng ký nguyện vọng')]
class ApplicationDetails extends CandidatePage
{
    #[Locked]
    public int $applicationId;

    /** @var list<int> */
    #[Locked]
    public array $expectedOrder = [];

    #[Locked]
    public ?int $deleteId = null;

    /** @var array<string, mixed> */
    public array $form = ['candidate_major_offering_id' => ''];

    public bool $showDeletion = false;

    public bool $showSubmission = false;

    public function mount(int $application): void
    {
        $this->applicationId = $this->profile()->applications()->findOrFail($application)->getKey();
        $this->reloadWishes();
    }

    protected function application(): Application
    {
        $application = $this->profile()->applications()->findOrFail($this->applicationId);
        Gate::authorize('view', $application);

        return $application;
    }

    public function reloadWishes(): void
    {
        $this->expectedOrder = CandidateWishes::orderedIds($this->application()->wishes()->get());
        $this->resetValidation();
        $this->deleteId = null;
        $this->showDeletion = false;
        $this->showSubmission = false;
    }

    public function addWish(CandidateWishes $wishes): void
    {
        $this->candidate();
        $wishes->add($this->applicationId, $this->form);
        $this->form = ['candidate_major_offering_id' => ''];
        $this->reloadWishes();
        Flux::toast(variant: 'success', text: __('Đã thêm nguyện vọng.'));
    }

    public function confirmDeletion(int $id): void
    {
        $application = $this->application();
        $wish = $application->wishes()->findOrFail($id);
        Gate::authorize('delete', $wish);
        CandidateApplications::requireOpenRound($application->admissionRound()->firstOrFail());
        $this->resetValidation();
        $this->deleteId = $wish->getKey();
        $this->showDeletion = true;
    }

    public function deleteWish(CandidateWishes $wishes): void
    {
        $this->candidate();
        abort_if($this->deleteId === null, 404);
        $wishes->delete($this->applicationId, $this->deleteId, $this->expectedOrder);
        $this->reloadWishes();
        Flux::toast(variant: 'success', text: __('Đã xóa nguyện vọng và cập nhật thứ tự ưu tiên.'));
    }

    /** @param array<mixed> $order */
    public function reorderWishes(array $order, CandidateWishes $wishes): void
    {
        $this->candidate();
        $wishes->reorder($this->applicationId, $order, $this->expectedOrder);
        $this->reloadWishes();
        Flux::toast(variant: 'success', text: __('Đã lưu thứ tự ưu tiên nguyện vọng.'));
    }

    public function moveWish(int $id, string $direction, CandidateWishes $wishes): void
    {
        $application = $this->application();
        $wish = $application->wishes()->findOrFail($id);
        Gate::authorize('update', $wish);
        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['order' => __('Vui lòng chọn chuyển lên hoặc chuyển xuống.')]);
        }
        $order = $this->expectedOrder;
        $position = array_search($id, $order, true);
        if ($position === false) {
            throw ValidationException::withMessages(['order' => __('Hãy tải lại nguyện vọng trước khi thay đổi thứ tự.')]);
        }
        $target = $position + ($direction === 'up' ? -1 : 1);
        if (array_key_exists($target, $order)) {
            [$order[$position], $order[$target]] = [$order[$target], $order[$position]];
        }
        $this->reorderWishes($order, $wishes);
    }

    public function confirmSubmission(): void
    {
        $application = $this->application();
        Gate::authorize('submit', $application);
        CandidateApplications::requireOpenRound($application->admissionRound()->firstOrFail());
        $this->resetValidation();
        $this->showSubmission = true;
    }

    public function submit(CandidateApplications $applications): void
    {
        $this->candidate();
        $this->validate(['form' => ['array:candidate_major_offering_id']]);
        $applications->submit($this->applicationId);
        $this->reloadWishes();
        Flux::toast(variant: 'success', text: __('Đã nộp hồ sơ xét tuyển.'));
    }

    public function render(): View
    {
        $profile = $this->profile();
        $application = $this->application();
        Gate::authorize('browseForApplication', [AdmissionProgram::class, $application]);
        $round = $application->admissionRound()->firstOrFail();
        $wishes = $application->wishes()->with(['admissionProgram.major', 'admissionProgram.admissionMethod', 'candidateMajorOffering'])->withExists('result')->orderBy('priority')->get();
        $selectedMajors = $wishes->pluck('admissionProgram.major_id')->all();
        $majorCounts = $wishes->countBy('admissionProgram.major_id');
        $editable = Gate::allows('update', $application) && CandidateApplications::roundIsOpen($round);
        $offerings = $editable ? CandidateMajorOfferings::choices($round)->reject(fn ($offering): bool => in_array($offering->major_id, $selectedMajors, true)) : collect();
        $checklist = CandidateApplications::submissionErrors($profile, $application, $round);
        if ($wishes->isEmpty()) {
            $checklist['wishes'] = __('Cần có ít nhất một nguyện vọng trước khi nộp hồ sơ.');
        } elseif ($wishes->pluck('priority')->all() !== range(1, $wishes->count()) || $wishes->pluck('admission_program_id')->unique()->count() !== $wishes->count()) {
            $checklist['wishes'] = __('Hãy tải lại và sắp xếp nguyện vọng để thứ tự ưu tiên liên tục.');
        }
        foreach ($wishes as $wish) {
            if (CandidateWishes::unavailableReason($wish->admissionProgram, $round) !== null
                || ($wish->candidate_major_offering_id !== null && (! CandidateMajorOfferings::available($wish->candidateMajorOffering, $wish->admissionProgram, $round)
                    || $majorCounts->get($wish->admissionProgram->major_id) !== 1))) {
                $checklist['wishes'] = __('Một hoặc nhiều nguyện vọng không còn nhận đăng ký. Hãy kiểm tra danh sách nguyện vọng.');
            }
        }

        return view('livewire.candidate.application-details', compact('application', 'round', 'wishes', 'offerings', 'editable', 'checklist'));
    }
}
