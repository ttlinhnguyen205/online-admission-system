<?php

namespace App\Actions;

use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateMajorOffering;
use App\Models\NativeMethodEvaluation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NativeRoundScoringReport
{
    public function __construct(private NativeAdmissionScoring $scoring) {}

    /** @return array<string, mixed> */
    public function build(int $roundId): array
    {
        AdmissionEngineSnapshot::actor();

        return DB::transaction(function () use ($roundId): array {
            AdmissionCatalogLock::acquire();
            $round = AdmissionRound::query()->lockForUpdate()->findOrFail($roundId);
            abort_if($round->nativeRegistrationState() === 'legacy', 422);
            $blockers = [NativeAllocationCertification::BLOCKER];
            if ($round->nativeRegistrationState() !== 'native_closed' || $round->getRawOriginal('status') !== 'closed') {
                $blockers[] = 'Cần đóng đăng ký Native và lifecycle đợt trước khi xét tuyển.';
            }
            $applications = Application::query()->where('admission_round_id', $roundId)->orderBy('id')->get();
            if ($applications->contains(fn ($application) => $application->registration_mode !== 'native')) {
                $blockers[] = 'Có hồ sơ legacy trong đợt Native.';
            }
            $rows = [];
            $quotas = [];
            foreach (CandidateMajorOffering::query()->where('admission_round_id', $roundId)->with('quotaVersions.limits')->get() as $offering) {
                $approved = $offering->quotaVersions->where('status', 'approved');
                if ($approved->count() !== 1) {
                    $blockers[] = 'Offering #'.$offering->id.' cần đúng một phiên bản Q/q approved.';

                    continue;
                }
                $quota = $approved->first();
                $quotas[] = ['offering_id' => $offering->id, 'version' => $quota->version, 'total_quota' => $quota->total_quota];
                if (! hash_equals($quota->catalog_fingerprint, (new AdmissionQuotaManagement)->catalogFingerprint($offering))) {
                    $blockers[] = 'Chỉ tiêu offering #'.$offering->id.' không còn khớp catalog.';
                }
            }
            $counts = ['eligible' => 0, 'ineligible' => 0, 'pending_data' => 0, 'unsupported' => 0, 'needs_resolution' => 0, 'not_scored' => 0, 'stale' => 0];
            foreach ($applications->where('registration_mode', 'native')->whereNotNull('submitted_at') as $candidate) {
                $application = (new AdmissionReviewSnapshot)->load($candidate->id, true);
                if ($application->getRawOriginal('status') !== 'verified') {
                    $blockers[] = 'Hồ sơ #'.$application->id.' chưa được Staff xác minh.';
                }
                $snapshot = $application->submissionSnapshots()->orderByDesc('submission_version')->with('entries.bindings')->first();
                if ($snapshot === null || $snapshot->sealed_at === null || $snapshot->registration_mode !== 'native'
                    || ! hash_equals($snapshot->content_hash, NativeWishRegistration::hash($snapshot->getAttribute('manifest')))
                    || $snapshot->getAttribute('manifest') !== $snapshot->entries->sortBy('priority')->pluck('payload')->values()->all()) {
                    $blockers[] = 'Snapshot hồ sơ #'.$application->id.' thiếu hoặc không toàn vẹn.';

                    continue;
                }
                foreach ($snapshot->entries as $entry) {
                    if ($entry->application_id !== $application->id || $entry->admission_round_id !== $roundId
                        || ! hash_equals($entry->content_hash, NativeWishRegistration::hash($entry->getAttribute('payload')))
                        || $entry->getAttribute('payload')['methods'] !== $entry->bindings->sortBy('id')->pluck('catalog_reference')->values()->all()) {
                        $blockers[] = 'Nguyện vọng #'.$entry->id.' không toàn vẹn.';

                        continue;
                    }
                    foreach ($entry->bindings as $binding) {
                        $reference = $binding->getAttribute('catalog_reference');
                        if (! hash_equals($binding->binding_hash, NativeWishRegistration::hash($reference)) || $binding->admission_round_id !== $roundId
                            || $binding->major_id !== $entry->major_id || $reference['rule_id'] !== $binding->evaluation_rule_version_id
                            || $reference['method_id'] !== $binding->admission_method_id || $reference['program_id'] !== $binding->admission_program_id) {
                            $blockers[] = 'Binding #'.$binding->id.' không toàn vẹn.';

                            continue;
                        }
                        $stored = Schema::hasTable('native_method_evaluations') ? NativeMethodEvaluation::query()->where('wish_method_binding_id', $binding->id)
                            ->where('algorithm_version', NativeAdmissionScoring::ALGORITHM)->lockForUpdate()->first() : null;
                        $status = 'not_scored';
                        if ($stored !== null) {
                            $result = $this->scoring->evaluate($binding, $application->candidate_profile_id);
                            $fingerprint = NativeWishRegistration::hash([$snapshot->content_hash, $binding->binding_hash, NativeAdmissionScoring::ALGORITHM, $result]);
                            $status = hash_equals($stored->input_fingerprint, $fingerprint) && $stored->evaluation_rule_version_id === $binding->evaluation_rule_version_id
                                && $stored->status === $result['status'] && $stored->score === $result['score'] && $stored->getAttribute('provenance') === $result['provenance'] && $stored->getAttribute('reasons') === $result['reasons']
                                ? $stored->status : 'stale';
                        }
                        $counts[$status]++;
                        $rows[] = ['application_id' => $application->id, 'snapshot_version' => $snapshot->submission_version, 'priority' => $entry->priority,
                            'major_id' => $entry->major_id, 'method_id' => $binding->admission_method_id, 'rule_id' => $binding->evaluation_rule_version_id,
                            'binding_id' => $binding->id, 'status' => $status, 'score' => $status === 'stale' ? null : $stored?->score];
                    }
                }
            }
            if (array_sum(array_diff_key($counts, array_flip(['eligible', 'ineligible']))) > 0) {
                $blockers[] = 'Còn phương thức chưa chấm, thiếu dữ liệu, chưa hỗ trợ, cần xử lý hoặc điểm đã stale.';
            }

            return ['native' => true, 'round_id' => $roundId, 'round_status' => $round->getRawOriginal('status'), 'mode' => $round->nativeRegistrationState(),
                'applications' => $applications->count(), 'counts' => $counts, 'quotas' => $quotas, 'rows' => $rows, 'blockers' => array_values(array_unique($blockers))];
        }, 3);
    }
}
