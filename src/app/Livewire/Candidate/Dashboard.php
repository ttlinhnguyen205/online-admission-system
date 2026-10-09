<?php

namespace App\Livewire\Candidate;

use App\Actions\BuildAdmissionCounselingContext;
use App\Actions\CandidateApplications;
use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Models\AdmissionResult;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Support\CandidateNotificationDetails;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Livewire\WithPagination;

class Dashboard extends CandidatePage
{
    use WithPagination;

    public function render(CandidateNotificationDetails $notificationDetails): View
    {
        $candidate = $this->candidate();
        $profile = $candidate->candidateProfile()->first();
        if ($profile !== null) {
            Gate::authorize('view', $profile);
        }
        $applications = Application::query()->where('candidate_profile_id', $profile->id ?? 0);
        $results = AdmissionResult::query()->visibleToCandidate($candidate);
        $metrics = [
            'Hồ sơ của tôi' => (clone $applications)->count(),
            'Hồ sơ cần bổ sung' => (clone $applications)->where('status', ApplicationStatus::NeedsRevision)->count(),
            'Nguyện vọng' => AdmissionWish::query()->whereIn('application_id', (clone $applications)->select('id'))->count(),
            'Kết quả đã công bố' => (clone $results)->count(),
            'Thông báo chưa đọc' => $candidate->unreadNotifications()->count(),
        ];
        $notifications = $candidate->notifications()->orderByDesc('created_at')->orderBy('id')->limit(5)->get();
        $applicationPage = (clone $applications)->with(['admissionRound', 'wishes' => fn ($query) => $query->orderBy('priority'),
            'wishes.admissionProgram.major', 'wishes.admissionProgram.admissionMethod'])->orderByDesc('id')->paginate(5);
        $programMethods = [];
        foreach ($applicationPage as $application) {
            if (! in_array($application->admissionRound->getAttribute('status'), [AdmissionRoundStatus::Open, AdmissionRoundStatus::Published], true)) {
                continue;
            }
            foreach ($application->wishes as $wish) {
                $program = $wish->admissionProgram;
                $method = $program->admissionMethod;
                if ((int) $program->admission_round_id !== (int) $application->admission_round_id) {
                    continue;
                }
                $approved = true;
                foreach ([['program', $program], ['method', $method]] as [$type, $model]) {
                    $approval = config('admission_chatbot.publications', [])[$type.':'.$model->getKey().':'.$type] ?? [];
                    if (($approval['approved'] ?? false) !== true || ! is_string($approval['fingerprint'] ?? null)
                        || ! hash_equals(BuildAdmissionCounselingContext::fingerprint($model, $type), $approval['fingerprint'])) {
                        $approved = false;
                    }
                }
                if ($approved) {
                    $programMethods[$program->id] = $method->name;
                }
            }
        }

        return view('livewire.candidate.dashboard', [
            'candidate' => $candidate,
            'profileComplete' => $profile !== null && CandidateApplications::profileIsComplete($profile),
            'revisionApplications' => (clone $applications)->where('status', ApplicationStatus::NeedsRevision)
                ->with('admissionRound')->orderBy('id')->paginate(5, ['*'], 'revisionsPage'),
            'profile' => $profile, 'metrics' => $metrics,
            'applications' => $applicationPage, 'programMethods' => $programMethods,
            'results' => $results->with(['admissionWish.application.admissionRound', 'admissionWish.admissionProgram.major'])
                ->orderByDesc('published_at')->orderBy('id')->limit(5)->get(),
            'notifications' => $notifications,
            'notificationDetails' => $notifications->mapWithKeys(fn (DatabaseNotification $notification): array => [
                $notification->id => $notificationDetails->for($candidate, $notification),
            ]),
        ]);
    }
}
