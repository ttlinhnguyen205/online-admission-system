<?php

namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\AdmissionProgram;
use App\Models\AdmissionQuotaVersion;
use App\Models\CandidateMajorOffering;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdmissionQuotaManagement
{
    /** @return Collection<int, AdmissionProgram> */
    public function acceptedPrograms(CandidateMajorOffering $offering, bool $lock = false): Collection
    {
        return AdmissionProgram::query()->where('admission_round_id', $offering->admission_round_id)
            ->where('major_id', $offering->major_id)->where('status', 'active')
            ->whereHas('admissionMethod', fn ($query) => $query->where('is_active', true))
            ->with('admissionMethod')->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
    }

    public function catalogFingerprint(CandidateMajorOffering $offering): string
    {
        $programs = AdmissionProgram::query()->where('admission_round_id', $offering->admission_round_id)
            ->where('major_id', $offering->major_id)->with('admissionMethod')->orderBy('id')->get();

        return hash('sha256', json_encode([
            $offering->only(['id', 'admission_round_id', 'major_id']),
            $programs->map(fn (AdmissionProgram $program): array => [
                $program->only(['id', 'admission_round_id', 'major_id', 'admission_method_id', 'status']),
                $program->admissionMethod->only(['id', 'code', 'is_active']),
            ])->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function createDraft(int $offeringId, string $reason): AdmissionQuotaVersion
    {
        $this->authorize();
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']],
            ['reason.required' => 'Vui lòng ghi lý do tạo phiên bản chỉ tiêu.'])->validate();

        return DB::transaction(function () use ($offeringId, $reason): AdmissionQuotaVersion {
            AdmissionCatalogLock::acquire();
            $offering = CandidateMajorOffering::query()->lockForUpdate()->findOrFail($offeringId);
            Gate::authorize('update', $offering);
            $previous = $offering->quotaVersions()->orderByDesc('version')->lockForUpdate()->first();
            $version = AdmissionQuotaVersion::query()->create([
                'candidate_major_offering_id' => $offering->id, 'admission_round_id' => $offering->admission_round_id, 'major_id' => $offering->major_id,
                'version' => ($previous->version ?? 0) + 1, 'total_quota' => $previous->total_quota ?? 0,
                'status' => 'draft', 'previous_version_id' => $previous?->id, 'reason' => trim($reason),
                'catalog_fingerprint' => $this->catalogFingerprint($offering),
            ]);
            $previousLimits = $previous?->limits()->pluck('quota', 'admission_program_id');
            foreach ($this->acceptedPrograms($offering) as $program) {
                $version->limits()->create([
                    'admission_program_id' => $program->id, 'admission_round_id' => $offering->admission_round_id, 'major_id' => $offering->major_id,
                    'quota' => $previousLimits?->get($program->id),
                ]);
            }
            $this->audit($version, 'admission_quota.draft_created');

            return $version;
        }, 3);
    }

    /** @param array{total_quota: mixed, reason: mixed, limits: mixed} $form */
    public function updateDraft(int $id, array $form): void
    {
        $this->authorize();
        if (is_array($form['limits'])) {
            foreach ($form['limits'] as &$row) {
                if (is_array($row) && ($row['quota'] ?? null) === '') {
                    $row['quota'] = null;
                }
            }
            unset($row);
        }
        $data = Validator::make($form, [
            'total_quota' => ['required', 'integer', 'between:0,4294967295'], 'reason' => ['required', 'string', 'max:2000'],
            'limits' => ['required', 'array', 'list', 'max:1000'], 'limits.*' => ['required', 'array:program_id,quota'],
            'limits.*.program_id' => ['required', 'integer', 'min:1', 'distinct'],
            'limits.*.quota' => ['nullable', 'integer', 'between:0,4294967295'],
        ], [
            'total_quota.between' => 'Tổng chỉ tiêu phải từ 0 đến 4.294.967.295.',
            'limits.*.quota.between' => 'Chỉ tiêu phương thức không được âm hoặc vượt giới hạn.',
            'limits.*.program_id.distinct' => 'Không được lặp phương thức.',
            'reason.required' => 'Vui lòng ghi lý do điều chỉnh chỉ tiêu.',
        ])->validate();
        DB::transaction(function () use ($id, $data): void {
            [$offering,$version] = $this->lock($id);
            $this->requireDraft($version);
            $accepted = $this->acceptedPrograms($offering)->modelKeys();
            $provided = array_map(fn (array $row): int => (int) $row['program_id'], $data['limits']);
            if (array_diff($provided, $accepted) !== []) {
                $this->fail('Không được cấu hình phương thức ngoài ngành/đợt hoặc không còn được chấp nhận.');
            }
            if (array_sum(array_column($data['limits'], 'quota')) > (int) $data['total_quota']) {
                $this->fail('Tổng chỉ tiêu phương thức không được vượt tổng chỉ tiêu ngành.');
            }
            $version->limits()->get()->each->delete();
            foreach ($data['limits'] as $row) {
                $version->limits()->create(['admission_program_id' => $row['program_id'], 'quota' => $row['quota'],
                    'admission_round_id' => $offering->admission_round_id, 'major_id' => $offering->major_id]);
            }
            $version->update(['total_quota' => $data['total_quota'], 'reason' => trim($data['reason']),
                'catalog_fingerprint' => $this->catalogFingerprint($offering)]);
            $this->audit($version, 'admission_quota.draft_updated');
        }, 3);
    }

    public function approve(int $id): void
    {
        $this->authorize();
        DB::transaction(function () use ($id): void {
            [$offering,$version] = $this->lock($id);
            Gate::authorize('approve', $version);
            $this->requireDraft($version);
            $fingerprint = $this->catalogFingerprint($offering);
            if (! hash_equals($version->catalog_fingerprint, $fingerprint)) {
                $this->fail('Danh mục phương thức đã thay đổi. Hãy mở và lưu lại bản nháp trước khi phê duyệt.');
            }
            $accepted = $this->acceptedPrograms($offering)->modelKeys();
            $limits = $version->limits()->orderBy('admission_program_id')->lockForUpdate()->get();
            $ids = $limits->pluck('admission_program_id')->all();
            sort($accepted);
            sort($ids);
            if ($accepted === [] || $accepted !== $ids || $limits->contains(fn ($limit): bool => $limit->quota === null)) {
                $this->fail('Mỗi phương thức được chấp nhận phải có một chỉ tiêu rõ ràng, kể cả 0.');
            }
            if ($limits->sum('quota') > $version->total_quota) {
                $this->fail('Tổng chỉ tiêu phương thức không được vượt tổng chỉ tiêu ngành.');
            }
            $hash = hash('sha256', json_encode([
                'total_quota' => $version->total_quota, 'catalog_fingerprint' => $fingerprint,
                'limits' => $limits->map->only(['admission_program_id', 'quota'])->all(),
            ], JSON_THROW_ON_ERROR));
            $version->update(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now(), 'content_hash' => $hash]);
            $this->audit($version, 'admission_quota.approved');
        }, 3);
    }

    public function retire(int $id): void
    {
        $this->authorize();
        DB::transaction(function () use ($id): void {
            [, $version] = $this->lock($id);
            if ($version->status !== 'approved') {
                $this->fail('Chỉ phiên bản đã phê duyệt mới được ngừng sử dụng.');
            }
            $version->update(['status' => 'retired', 'retired_at' => now()]);
            $this->audit($version, 'admission_quota.retired');
        }, 3);
    }

    /** @return array{CandidateMajorOffering, AdmissionQuotaVersion} */
    private function lock(int $id): array
    {
        AdmissionCatalogLock::acquire();
        $hint = AdmissionQuotaVersion::query()->findOrFail($id);
        $offering = CandidateMajorOffering::query()->lockForUpdate()->findOrFail($hint->candidate_major_offering_id);
        $version = AdmissionQuotaVersion::query()->lockForUpdate()->findOrFail($id);
        Gate::authorize('update', $version);
        Gate::authorize('update', $offering);

        return [$offering, $version];
    }

    private function authorize(): void
    {
        Auth::user()?->refresh();
        Gate::authorize('viewAny', AdmissionQuotaVersion::class);
    }

    private function requireDraft(AdmissionQuotaVersion $version): void
    {
        if ($version->status !== 'draft') {
            $this->fail('Chỉ được chỉnh sửa hoặc phê duyệt bản nháp.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['quota' => $message]);
    }

    private function audit(AdmissionQuotaVersion $version, string $action): void
    {
        ActivityLog::query()->create(['user_id' => Auth::id(), 'action' => $action, 'subject_type' => $version->getMorphClass(),
            'subject_id' => $version->id, 'new_values' => $version->only(['candidate_major_offering_id', 'version', 'total_quota', 'status', 'catalog_fingerprint', 'content_hash', 'reason']),
            'created_at' => now()]);
    }
}
