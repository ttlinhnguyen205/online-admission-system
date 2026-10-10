<?php

namespace App\Livewire\Admin;

use App\Actions\AdmissionQuotaManagement;
use App\Models\AdmissionQuotaVersion;
use App\Models\CandidateMajorOffering;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OfferingQuotas extends Component
{
    #[Locked]
    public int $offeringId;

    #[Locked]
    public ?int $versionId = null;

    /** @var array{total_quota: mixed, reason: mixed, limits: list<array{program_id: int, quota: mixed}>} */
    public array $quotaForm = ['total_quota' => 0, 'reason' => '', 'limits' => []];

    public function boot(): void
    {
        Auth::user()?->refresh();
        Gate::authorize('viewAny', AdmissionQuotaVersion::class);
    }

    public function mount(int $offeringId): void
    {
        $this->offeringId = $offeringId;
        Gate::authorize('view', CandidateMajorOffering::query()->findOrFail($offeringId));
    }

    public function createDraft(AdmissionQuotaManagement $quotas): void
    {
        $this->validate(['quotaForm.reason' => ['required', 'string', 'max:2000']]);
        $version = $quotas->createDraft($this->offeringId, (string) $this->quotaForm['reason']);
        $this->selectVersion($version->id);
        Flux::toast(variant: 'success', text: 'Đã tạo bản nháp chỉ tiêu.');
    }

    public function selectVersion(int $id): void
    {
        $version = $this->version($id);
        $this->resetValidation();
        $this->versionId = $id;
        $quotas = app(AdmissionQuotaManagement::class);
        $limits = $version->limits()->pluck('quota', 'admission_program_id');
        $programs = $version->status === 'draft' ? $quotas->acceptedPrograms($version->offering)
            : $version->limits()->with('program.admissionMethod')->get()->map(fn ($limit) => $limit->program);
        $rows = [];
        foreach ($programs as $program) {
            $rows[] = ['program_id' => (int) $program->getKey(), 'quota' => $limits->get($program->getKey())];
        }
        $this->quotaForm = ['total_quota' => $version->total_quota, 'reason' => $version->reason, 'limits' => $rows];
    }

    public function saveDraft(AdmissionQuotaManagement $quotas): void
    {
        abort_if($this->versionId === null, 404);
        $this->version($this->versionId);
        $quotas->updateDraft($this->versionId, $this->quotaForm);
        Flux::toast(variant: 'success', text: 'Đã lưu bản nháp. Chỉ tiêu chưa được kích hoạt.');
    }

    public function approve(AdmissionQuotaManagement $quotas): void
    {
        abort_if($this->versionId === null, 404);
        $this->version($this->versionId);
        $quotas->approve($this->versionId);
        $this->selectVersion($this->versionId);
        Flux::toast(variant: 'success', text: 'Đã phê duyệt phiên bản chỉ tiêu. Không thay đổi engine legacy.');
    }

    public function retire(AdmissionQuotaManagement $quotas): void
    {
        abort_if($this->versionId === null, 404);
        $this->version($this->versionId);
        $quotas->retire($this->versionId);
        $this->selectVersion($this->versionId);
        Flux::toast(variant: 'success', text: 'Đã ngừng sử dụng phiên bản; lịch sử được giữ nguyên.');
    }

    private function version(int $id): AdmissionQuotaVersion
    {
        $version = AdmissionQuotaVersion::query()->where('candidate_major_offering_id', $this->offeringId)->findOrFail($id);
        Gate::authorize('update', $version);

        return $version;
    }

    public function render(): View
    {
        $offering = CandidateMajorOffering::query()->with(['major', 'admissionRound'])->findOrFail($this->offeringId);
        $versions = $offering->quotaVersions()->with('limits.program.admissionMethod')->orderByDesc('version')->get();
        $selected = $versions->firstWhere('id', $this->versionId);
        $programs = $selected !== null && $selected->status !== 'draft'
            ? $selected->limits->map(fn ($limit) => $limit->program)
            : app(AdmissionQuotaManagement::class)->acceptedPrograms($offering);

        return view('livewire.admin.offering-quotas', compact('offering', 'versions', 'selected', 'programs'));
    }
}
