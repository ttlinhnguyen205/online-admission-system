<?php

namespace App\Actions;

use App\Enums\AdmissionRoundStatus;
use App\Models\ActivityLog;
use App\Models\AdmissionProgram;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\ApplicationSubmissionSnapshot;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class NativeRoundActivation
{
    public function __construct(private NativeRegistrationReadiness $readiness) {}

    /** @return array<string, mixed> */
    public function check(AdmissionRound $round): array
    {
        $schemaReady = Schema::hasColumns('admission_rounds', ['native_registration_state', 'native_activated_by', 'native_activated_at']);
        $offerings = $round->candidateMajorOfferings()->with('major')->orderBy('id')->get();
        $programs = $round->programs()->with(['admissionMethod', 'evaluationRule'])->orderBy('id')->get();
        $states = [];
        $catalog = [];
        foreach ($offerings as $offering) {
            $catalog[] = $offering->only(['id', 'major_id', 'is_selectable', 'admission_program_id']);
            if (! $offering->is_selectable) {
                continue;
            }
            $state = $this->readiness->check($offering, $programs);
            if (! $offering->major->is_active) {
                $state['status'] = 'NOT READY';
            }
            $states[] = ['id' => $offering->id, 'name' => $offering->major->name, ...$state];
        }
        $legacy = $round->applications()->where('registration_mode', 'legacy')->count();
        $native = $round->applications()->where('registration_mode', 'native')->count();
        $legacyWishes = AdmissionWish::query()->whereHas('application', fn ($q) => $q->where('admission_round_id', $round->id))
            ->orWhereHas('admissionProgram', fn ($q) => $q->where('admission_round_id', $round->id))->count();
        $legacySnapshots = ApplicationSubmissionSnapshot::query()->where('registration_mode', 'legacy')
            ->whereIn('application_id', $round->applications()->select('id'))->count();
        $results = AdmissionResult::query()->whereHas('admissionWish', fn ($q) => $q
            ->whereHas('application', fn ($a) => $a->where('admission_round_id', $round->id))
            ->orWhereHas('admissionProgram', fn ($p) => $p->where('admission_round_id', $round->id)))->get();
        $runs = ActivityLog::query()->where('subject_type', $round->getMorphClass())->where('subject_id', $round->id)
            ->whereIn('action', ['admission_engine.started', 'admission_engine.completed'])->orderBy('id')->get();
        $conflicts = [];
        if (! $schemaReady) {
            $conflicts[] = 'Migration activation chưa được áp dụng; không thể thay đổi chế độ.';
        }
        if (! in_array($round->getAttribute('status'), [AdmissionRoundStatus::Draft, AdmissionRoundStatus::Open], true)) {
            $conflicts[] = 'Vòng đời đợt phải là Nháp hoặc Đang mở để cấu hình native.';
        }
        if ($legacy > 0) {
            $conflicts[] = "Có {$legacy} hồ sơ legacy; không chuyển đổi dữ liệu cũ.";
        }
        if ($legacyWishes > 0) {
            $conflicts[] = "Có {$legacyWishes} nguyện vọng legacy liên quan đợt.";
        }
        if ($legacySnapshots > 0) {
            $conflicts[] = "Có {$legacySnapshots} submission snapshot legacy.";
        }
        if ($results->isNotEmpty()) {
            $conflicts[] = $results->contains(fn (AdmissionResult $result): bool => $result->published_at !== null || $result->confirmed_at !== null)
                ? 'Có kết quả legacy đã công bố/xác nhận.' : 'Có kết quả legacy; không thay đổi phạm vi engine cũ.';
        }
        if ($runs->isNotEmpty()) {
            $conflicts[] = $runs->where('action', 'admission_engine.started')->count() > $runs->where('action', 'admission_engine.completed')->count()
                ? 'Có engine run đang xử lý hoặc chưa hoàn tất.' : 'Có lịch sử engine run; dùng đợt demo mới.';
        }
        if ($round->nativeRegistrationState() === 'legacy' && $native > 0) {
            $conflicts[] = 'Có hồ sơ native trong round legacy; cần xử lý xung đột thủ công.';
        }
        $blockers = $conflicts;
        if (! NativeWishRegistration::enabled()) {
            $blockers[] = 'Global feature flag native đang OFF; chỉ quản trị viên bật thủ công sau kiểm tra safeguards.';
        }
        if ($round->getAttribute('status') !== AdmissionRoundStatus::Open) {
            $blockers[] = 'Vòng đời đợt chưa Đang mở.';
        }
        if ($round->end_date->lessThanOrEqualTo($round->start_date) || now()->greaterThan($round->end_date)) {
            $blockers[] = 'Thời gian nhận hồ sơ không hợp lệ hoặc đã kết thúc; không tự đổi ngày.';
        }
        if ($states === []) {
            $blockers[] = 'Chưa có offering được chọn nhận đăng ký native.';
        }
        foreach ($states as $state) {
            if ($state['status'] !== 'READY') {
                $blockers[] = $state['name'].': NOT READY — thiếu rule hợp lệ, template chưa hỗ trợ hoặc ngành ngừng hoạt động.';
            }
        }
        $programManifest = $programs->map(fn (AdmissionProgram $program): array => [
            $program->only(['id', 'major_id', 'status', 'admission_method_id', 'evaluation_rule_version_id']),
            $program->admissionMethod->only(['id', 'is_active']), $program->evaluationRule?->getAttributes(),
        ])->all();
        $ready = count(array_filter($states, fn (array $state): bool => $state['status'] === 'READY'));
        $fingerprint = NativeWishRegistration::hash([$round->getAttributes(), $catalog, $programManifest, $states,
            $legacy, $native, $legacyWishes, $legacySnapshots, $results->modelKeys(), $runs->modelKeys(), NativeWishRegistration::enabled(), $schemaReady]);

        return ['state' => $round->nativeRegistrationState(), 'ready' => $ready, 'not_ready' => count($states) - $ready,
            'legacy' => $legacy, 'native' => $native, 'offerings' => $states, 'conflicts' => $conflicts,
            'blockers' => $blockers, 'fingerprint' => $fingerprint, 'time_open' => CandidateApplications::roundIsOpen($round),
            'status' => $blockers === [] ? 'READY' : 'NOT READY'];
    }

    public function transition(int $roundId, string $action, string $expectedFingerprint, string $confirmationCode, bool $confirmed, string $reason): void
    {
        Auth::user()?->refresh();
        Gate::authorize('manageNativeRegistration', AdmissionRound::query()->findOrFail($roundId));
        Validator::make(compact('action', 'confirmed', 'reason'), [
            'action' => ['required', 'in:prepare,activate,close'], 'confirmed' => ['accepted'],
            'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
        ])->validate();
        DB::transaction(function () use ($roundId, $action, $expectedFingerprint, $confirmationCode, $reason): void {
            AdmissionCatalogLock::acquire();
            $round = AdmissionRound::query()->lockForUpdate()->findOrFail($roundId);
            Gate::authorize('manageNativeRegistration', $round);
            if ($confirmationCode !== $round->code) {
                $this->fail('Nhập chính xác mã đợt để xác nhận phạm vi demo.');
            }
            $check = $this->check($round);
            if (! hash_equals($check['fingerprint'], $expectedFingerprint)) {
                $this->fail('Cấu hình hoặc dữ liệu đã thay đổi. Kiểm tra lại trước khi xác nhận.');
            }
            $state = $round->nativeRegistrationState();
            if ($action === 'close') {
                if ($state !== 'native_open') {
                    $this->fail('Chỉ đóng đăng ký khi chế độ đang native_open.');
                }
                $next = 'native_closed';
            } else {
                $blockers = $action === 'prepare' ? $check['conflicts'] : $check['blockers'];
                if ($blockers !== []) {
                    $this->fail(implode(' ', $blockers));
                }
                if ($action === 'prepare' && $state !== 'legacy') {
                    $this->fail('Đợt đã được chuẩn bị native.');
                }
                if ($action === 'activate' && ! in_array($state, ['native_draft', 'native_closed'], true)) {
                    $this->fail('Cần chuẩn bị native trước khi kích hoạt.');
                }
                $next = $action === 'prepare' ? 'native_draft' : 'native_open';
            }
            $round->setAttribute('native_registration_state', $next);
            if ($action === 'activate') {
                $round->setAttribute('native_activated_by', Auth::id());
                $round->setAttribute('native_activated_at', now());
            }
            $round->save();
            ActivityLog::query()->create(['user_id' => Auth::id(), 'action' => 'native_registration.'.$action,
                'subject_type' => $round->getMorphClass(), 'subject_id' => $round->id,
                'old_values' => ['state' => $state], 'new_values' => ['state' => $next, 'reason' => trim($reason),
                    'confirmed_round_code' => $confirmationCode, 'demo_only' => true, 'catalog_fingerprint' => $check['fingerprint']], 'created_at' => now()]);
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['native' => $message]);
    }
}
