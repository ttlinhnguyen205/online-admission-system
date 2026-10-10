<?php

namespace App\Actions;

use App\Enums\AdmissionDecision;
use App\Enums\ApplicationStatus;
use App\Models\AdmissionResult;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\NativeAdmissionWish;
use App\Models\NativeResultEntry;
use App\Models\User;
use App\Support\AdmissionReportFilters;
use App\Support\CandidateStatusLabels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class AdmissionStatistics
{
    public function authorize(User $user): User
    {
        $actor = $user->fresh();
        abort_unless($actor instanceof User && $actor->isActive() && $actor->hasVerifiedEmail(), 403);
        Gate::forUser($actor)->authorize('viewAny', Application::class);

        return $actor;
    }

    /** @return Builder<Application> */
    public function applications(User $user, AdmissionReportFilters $filters): Builder
    {
        $actor = $this->authorize($user);
        abort_unless($filters->yearFilter === '' || $actor->isAdmin(), 403);

        return $this->filteredApplications($filters)->where('status', '!=', ApplicationStatus::Draft);
    }

    /** @return Builder<Application> */
    private function filteredApplications(AdmissionReportFilters $filters): Builder
    {
        return Application::query()
            ->when($filters->yearFilter !== '', fn ($query) => $query->whereHas('admissionRound', fn ($rounds) => $rounds->where('year', $filters->yearFilter)))
            ->when($filters->roundFilter !== '', fn ($query) => $query->where('admission_round_id', $filters->roundFilter))
            ->when(! in_array($filters->statusFilter, ['', 'all'], true), fn ($query) => $query->where('status', $filters->statusFilter))
            ->when($filters->search !== '', fn ($query) => $query->where(function ($query) use ($filters): void {
                $query->whereLike('application_code', '%'.$filters->search.'%')
                    ->orWhereHas('candidateProfile', fn ($query) => $query->whereLike('candidate_code', '%'.$filters->search.'%'))
                    ->orWhereHas('candidateProfile.user', fn ($query) => $query->where(function ($query) use ($filters): void {
                        $query->whereLike('name', '%'.$filters->search.'%')->orWhereLike('email', '%'.$filters->search.'%');
                    }));
            }));
    }

    /** @return Builder<AdmissionWish> */
    public function wishes(User $user, AdmissionReportFilters $filters): Builder
    {
        return AdmissionWish::query()->whereIn('application_id', $this->applications($user, $filters)->select('applications.id'));
    }

    /** @return Builder<AdmissionResult> */
    public function results(User $user, AdmissionReportFilters $filters): Builder
    {
        $actor = $this->authorize($user);
        $query = AdmissionResult::query()->whereIn('admission_wish_id', $this->wishes($actor, $filters)->select('admission_wishes.id'));
        if (! $actor->isAdmin()) {
            $query->whereNotNull('published_at')->where('published_at', '<=', now())
                ->whereHas('admissionWish.application.admissionRound', fn ($query) => $query->where('status', 'published'));
        }

        return $query;
    }

    /** @return Builder<NativeResultEntry> */
    public function nativeResults(User $user, AdmissionReportFilters $filters): Builder
    {
        return NativeResultEntry::query()->published()->whereIn('application_id', $this->applications($user, $filters)->select('applications.id'));
    }

    /** @return array{metrics: array<string, int>, statuses: list<array{label: string, count: int}>, charts: array<string, list<array{label: string, count: int}>>} */
    public function build(User $user, AdmissionReportFilters $filters): array
    {
        $actor = $this->authorize($user);
        $applications = $this->applications($actor, $filters);
        $wishes = $this->wishes($actor, $filters);
        $results = $this->results($actor, $filters);
        $counts = (clone $applications)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $metrics = [
            'Hồ sơ đã nộp' => (clone $applications)->count(),
            'Thí sinh có hồ sơ' => (clone $applications)->distinct()->count('candidate_profile_id'),
            'Nguyện vọng' => (clone $wishes)->count(),
            'Kết quả xét tuyển' => (clone $results)->count(),
            'Chờ bắt đầu xét duyệt' => (int) ($counts[ApplicationStatus::Submitted->value] ?? 0),
            'Đang xét duyệt' => (int) ($counts[ApplicationStatus::UnderReview->value] ?? 0),
            'Cần bổ sung' => (int) ($counts[ApplicationStatus::NeedsRevision->value] ?? 0),
            'Đã xác minh' => (int) ($counts[ApplicationStatus::Verified->value] ?? 0),
            'Không hợp lệ' => (int) ($counts[ApplicationStatus::Rejected->value] ?? 0),
            'Đang xét tuyển' => (int) ($counts[ApplicationStatus::Processing->value] ?? 0),
            'Hoàn tất xét tuyển' => (int) ($counts[ApplicationStatus::Completed->value] ?? 0),
            'Đã có lần xét duyệt' => (clone $applications)->whereNotNull('reviewed_at')->count(),
        ];
        $nativeApplications = (clone $applications)->where('registration_mode', 'native');
        $nativeCount = (clone $nativeApplications)->count();
        if ($nativeCount > 0) {
            $metrics['Hồ sơ Native đã nộp'] = $nativeCount;
            $metrics['Nguyện vọng Native (theo ngành)'] = NativeAdmissionWish::query()
                ->whereIn('application_id', $nativeApplications->select('applications.id'))->count();
            if (Schema::hasTable('native_result_entries')) {
                $nativeResults = $this->nativeResults($actor, $filters);
                $metrics['Kết quả Native đã công bố'] = (clone $nativeResults)->count();
                $metrics['Trúng tuyển Native đã công bố'] = (clone $nativeResults)->where('decision', 'admitted')->count();
                $metrics['Không trúng tuyển Native đã công bố'] = (clone $nativeResults)->where('decision', 'not_admitted')->count();
            }
        }
        foreach (AdmissionDecision::cases() as $decision) {
            $metrics[CandidateStatusLabels::result($decision)] = (clone $results)->where('decision', $decision)->count();
        }
        $metrics['Kết quả đã công bố'] = (clone $results)->whereNotNull('published_at')->where('published_at', '<=', now())
            ->whereHas('admissionWish.application.admissionRound', fn ($query) => $query->where('status', 'published'))->count();
        $metrics['Đã xác nhận nhập học'] = (clone $results)->where('decision', AdmissionDecision::Admitted)->whereNotNull('confirmed_at')->count();
        if ($actor->isAdmin()) {
            $metrics['Bản nháp chưa nộp (thống kê riêng)'] = $this->filteredApplications($filters)->where('status', ApplicationStatus::Draft)->count();
        }
        $statuses = array_map(fn (ApplicationStatus $status): array => [
            'label' => CandidateStatusLabels::application($status), 'count' => (int) ($counts[$status->value] ?? 0),
        ], AdmissionReportFilters::statuses());

        return ['metrics' => $metrics, 'statuses' => $statuses, 'charts' => $actor->isAdmin() ? [
            'Nguyện vọng theo ngành' => $this->chart($wishes, 'major'),
            'Nguyện vọng theo phương thức' => $this->chart($wishes, 'method'),
            'Nguyện vọng theo chương trình' => $this->chart($wishes, 'program'),
        ] : []];
    }

    /** @param Builder<AdmissionWish> $wishes
     * @return list<array{label: string, count: int}>
     */
    private function chart(Builder $wishes, string $dimension): array
    {
        $query = (clone $wishes)->join('admission_programs as programs', 'programs.id', '=', 'admission_wishes.admission_program_id')
            ->join('majors', 'majors.id', '=', 'programs.major_id')
            ->join('admission_methods as methods', 'methods.id', '=', 'programs.admission_method_id')
            ->join('admission_rounds as rounds', 'rounds.id', '=', 'programs.admission_round_id')->toBase();
        $columns = match ($dimension) {
            'major' => ['majors.id', 'majors.name', 'majors.code'],
            'method' => ['methods.id', 'methods.name', 'methods.code'],
            default => ['programs.id', 'majors.name', 'majors.code', 'methods.name as method_name', 'rounds.code as round_code'],
        };
        $groups = array_map(fn (string $column): string => explode(' as ', $column)[0], $columns);

        return array_values($query->select($columns)->selectRaw('COUNT(admission_wishes.id) as total')->groupBy($groups)
            ->orderByDesc('total')->orderBy($groups[0])->get()->map(fn (object $record): array => [
                'label' => $record->name.' ('.$record->code.')'.($dimension === 'program' ? ' — '.$record->method_name.' — '.$record->round_code : ''),
                'count' => (int) $record->total,
            ])->all());
    }
}
