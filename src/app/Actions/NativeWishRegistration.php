<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\ApplicationSubmissionSnapshot;
use App\Models\CandidateMajorOffering;
use App\Models\EvaluationRuleVersion;
use App\Models\NativeAdmissionWish;
use App\Notifications\ApplicationSubmitted;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class NativeWishRegistration
{
    public static function enabled(): bool
    {
        return (bool) config('admission_registration.native_registration', false);
    }

    public static function openForRound(AdmissionRound $round): bool
    {
        return self::enabled() && $round->nativeRegistrationState() === 'native_open' && CandidateApplications::roundIsOpen($round);
    }

    public static function catalogFingerprint(AdmissionRound $round): string
    {
        $offerings = $round->candidateMajorOfferings()->orderBy('id')->get()
            ->map->only(['id', 'major_id', 'is_selectable'])->all();
        $programs = $round->programs()->with(['major', 'admissionMethod', 'evaluationRule'])->orderBy('id')->get()
            ->map(fn (AdmissionProgram $program): array => [
                $program->only(['id', 'major_id', 'status', 'admission_method_id', 'evaluation_rule_version_id']),
                $program->major->only(['id', 'is_active']), $program->admissionMethod->only(['id', 'is_active']),
                $program->evaluationRule?->only(['id', 'status', 'template_identifier', 'template_version', 'version', 'content_hash', 'ranking_contract', 'ranking_contract_version']),
            ])->all();

        return self::hash([$offerings, $programs]);
    }

    /** @param array<mixed> $payload */
    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function lockApplication(int $id): Application
    {
        abort_unless(self::enabled(), 403);
        AdmissionCatalogLock::acquire();
        $application = CandidateApplications::lockApplication(CandidateApplications::lockProfile(), $id);
        $round = $application->admissionRound()->lockForUpdate()->firstOrFail();
        abort_unless(self::openForRound($round), 403);
        abort_unless($application->registration_mode === 'native', 403);
        CandidateApplications::requireEditable($application);
        CandidateApplications::requireOpenRound($application->admissionRound()->firstOrFail());
        if ($application->wishes()->exists()) {
            $this->fail('Không trộn nguyện vọng legacy và native trong cùng hồ sơ.');
        }

        return $application;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['wishes' => $message]);
    }

    /** @return Collection<int, AdmissionProgram> */
    public static function programs(CandidateMajorOffering $offering, bool $lock = false): Collection
    {
        return app(AdmissionQuotaManagement::class)->acceptedPrograms($offering, $lock);
    }

    public function add(int $applicationId, int $offeringId): void
    {
        DB::transaction(function () use ($applicationId, $offeringId): void {
            $application = $this->lockApplication($applicationId);
            $offering = CandidateMajorOffering::query()->where('admission_round_id', $application->admission_round_id)
                ->lockForUpdate()->findOrFail($offeringId);
            if (! $offering->is_selectable || ! $offering->major->is_active || self::programs($offering)->isEmpty()) {
                $this->fail('Ngành này hiện không nhận đăng ký.');
            }
            $wishes = $application->nativeWishes()->orderBy('priority')->lockForUpdate()->get();
            if ($wishes->contains('candidate_major_offering_id', $offeringId)) {
                $this->fail('Mỗi ngành chỉ được đăng ký một nguyện vọng.');
            }
            if ($wishes->count() >= 65535) {
                $this->fail('Đã đạt giới hạn số nguyện vọng.');
            }
            $application->nativeWishes()->create([
                'candidate_major_offering_id' => $offering->id, 'admission_round_id' => $offering->admission_round_id,
                'major_id' => $offering->major_id, 'priority' => $wishes->count() + 1,
            ]);
        }, 3);
    }

    /** @param list<int> $expected */
    public function delete(int $applicationId, int $wishId, array $expected): void
    {
        DB::transaction(function () use ($applicationId, $wishId, $expected): void {
            $application = $this->lockApplication($applicationId);
            $wishes = $application->nativeWishes()->orderBy('priority')->lockForUpdate()->get();
            $this->assertOrder($wishes, $expected);
            $wish = $wishes->firstWhere('id', $wishId);
            abort_unless($wish instanceof NativeAdmissionWish, 404);
            $wish->delete();
            foreach ($wishes->reject(fn (NativeAdmissionWish $row): bool => $row->id === $wishId)->values() as $index => $row) {
                $row->update(['priority' => $index + 1]);
            }
        }, 3);
    }

    /** @param array<mixed> $order
     * @param  list<int>  $expected
     */
    public function reorder(int $applicationId, array $order, array $expected): void
    {
        DB::transaction(function () use ($applicationId, $order, $expected): void {
            $application = $this->lockApplication($applicationId);
            $wishes = $application->nativeWishes()->orderBy('priority')->lockForUpdate()->get();
            $this->assertOrder($wishes, $expected);
            $ids = $wishes->modelKeys();
            if (count($order) !== count($ids) || count(array_unique($order)) !== count($ids)
                || array_diff($ids, $order) !== [] || array_filter($order, fn ($id): bool => ! is_int($id)) !== []) {
                $this->fail('Danh sách thứ tự không hợp lệ.');
            }
            $pending = $wishes->keyBy('id');
            $desired = array_flip($order);
            while ($pending->isNotEmpty()) {
                $progress = false;
                foreach ($pending as $id => $wish) {
                    $target = $desired[$id] + 1;
                    if ($wish->priority === $target) {
                        $pending->forget($id);
                        $progress = true;
                    } elseif (! $wishes->contains('priority', $target)) {
                        $wish->update(['priority' => $target]);
                        $pending->forget($id);
                        $progress = true;
                    }
                }
                if (! $progress) {
                    $pending->first()->update(['priority' => 0]);
                }
            }
        }, 3);
    }

    /** @param Collection<int, NativeAdmissionWish> $wishes
     * @param  list<int>  $expected
     */
    private function assertOrder(Collection $wishes, array $expected): void
    {
        if ($wishes->modelKeys() !== $expected) {
            $this->fail('Danh sách đã thay đổi. Hãy tải lại trước khi chỉnh sửa.');
        }
    }

    public function submit(int $applicationId, ?string $expectedCatalogFingerprint = null): void
    {
        DB::transaction(function () use ($applicationId, $expectedCatalogFingerprint): void {
            $application = $this->lockApplication($applicationId);
            if ($expectedCatalogFingerprint !== null && ! hash_equals(self::catalogFingerprint($application->admissionRound), $expectedCatalogFingerprint)) {
                $this->fail('Danh mục phương thức hoặc rule đã thay đổi. Kiểm tra lại hồ sơ và phương thức trước khi nộp.');
            }
            Gate::authorize('submit', $application);
            $errors = CandidateApplications::submissionErrors($application->candidateProfile, $application, $application->admissionRound);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $wishes = $application->nativeWishes()->orderBy('priority')->lockForUpdate()->get();
            if ($wishes->isEmpty() || $wishes->pluck('priority')->all() !== range(1, $wishes->count())) {
                $this->fail('Cần có nguyện vọng với thứ tự liên tục từ 1.');
            }
            $manifest = [];
            $catalog = [];
            foreach ($wishes as $wish) {
                $offering = CandidateMajorOffering::query()->lockForUpdate()->findOrFail($wish->candidate_major_offering_id);
                if ($offering->admission_round_id !== $application->admission_round_id || ! $offering->is_selectable || ! $offering->major->is_active) {
                    $this->fail('Ngành đã ngừng đăng ký hoặc không thuộc đợt này.');
                }
                $programs = self::programs($offering, true);
                if ($programs->isEmpty()) {
                    $this->fail('Ngành chưa có phương thức được chấp nhận.');
                }
                $methods = [];
                foreach ($programs as $program) {
                    $rule = EvaluationRuleVersion::query()->lockForUpdate()->find($program->evaluation_rule_version_id);
                    if (! app(EvaluationTemplateRegistry::class)->validApproved($rule, $program->admission_method_id)) {
                        $this->fail('Cấu hình native chưa sẵn sàng: mỗi phương thức cần quy tắc đã phê duyệt, thuộc template được cho phép.');
                    }
                    assert($rule !== null);
                    $methods[] = [
                        'program_id' => $program->id, 'method_id' => $program->admission_method_id,
                        'method_code' => $program->admissionMethod->code, 'method_name' => $program->admissionMethod->name,
                        'rule_id' => $rule->id, 'rule_hash' => $rule->content_hash,
                        'template' => [$rule->template_identifier, $rule->template_version],
                        'ranking_contract' => [$rule->ranking_contract, $rule->ranking_contract_version],
                    ];
                }
                $catalog[] = app(AdmissionQuotaManagement::class)->catalogFingerprint($offering);
                $manifest[] = [
                    'live_wish_id' => $wish->id, 'offering_id' => $offering->id, 'round_id' => $offering->admission_round_id,
                    'major_id' => $offering->major_id, 'major_code' => $offering->major->code,
                    'major_name' => $offering->major->name, 'priority' => $wish->priority, 'methods' => $methods,
                ];
            }
            $previous = $application->submissionSnapshots()->orderByDesc('submission_version')->lockForUpdate()->first();
            $snapshot = ApplicationSubmissionSnapshot::query()->create([
                'application_id' => $application->id, 'submission_version' => ($previous->submission_version ?? 0) + 1,
                'submitted_at' => now(), 'registration_mode' => 'native', 'readiness' => 'rules_pinned',
                'catalog_fingerprint' => self::hash($catalog), 'manifest' => $manifest, 'content_hash' => self::hash($manifest),
                'amendment_metadata' => $previous === null ? null : ['previous_snapshot_id' => $previous->id, 'revision_reason' => $application->revision_reason],
            ]);
            foreach ($manifest as $payload) {
                $entry = $snapshot->entries()->create([
                    'application_id' => $application->id, 'candidate_major_offering_id' => $payload['offering_id'],
                    'admission_round_id' => $payload['round_id'], 'major_id' => $payload['major_id'],
                    'priority' => $payload['priority'], 'live_wish_id' => $payload['live_wish_id'],
                    'payload' => $payload, 'content_hash' => self::hash($payload),
                ]);
                foreach ($payload['methods'] as $method) {
                    $entry->bindings()->create([
                        'admission_round_id' => $payload['round_id'], 'major_id' => $payload['major_id'],
                        'admission_program_id' => $method['program_id'], 'admission_method_id' => $method['method_id'],
                        'evaluation_rule_version_id' => $method['rule_id'], 'catalog_reference' => $method,
                        'binding_hash' => self::hash($method),
                    ]);
                }
            }
            $snapshot->update(['sealed_at' => now()]);
            $application->update(['status' => ApplicationStatus::Submitted, 'submitted_at' => now()]);
            $application->candidateProfile->user->notify(new ApplicationSubmitted($application->id));
        }, 3);
    }
}
