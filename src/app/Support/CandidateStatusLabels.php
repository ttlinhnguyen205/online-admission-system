<?php

namespace App\Support;

use App\Enums\AdmissionDecision;
use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\ProfileStatus;
use App\Enums\VerificationStatus;

class CandidateStatusLabels
{
    public static function application(ApplicationStatus $status): string
    {
        return match ($status) {
            ApplicationStatus::Draft => 'Bản nháp',
            ApplicationStatus::Submitted => 'Đã nộp',
            ApplicationStatus::UnderReview => 'Đang xét duyệt',
            ApplicationStatus::NeedsRevision => 'Cần bổ sung',
            ApplicationStatus::Verified => 'Đã xác minh',
            ApplicationStatus::Processing => 'Đang xét tuyển',
            ApplicationStatus::Completed => 'Hoàn tất xét tuyển',
        };
    }

    public static function round(AdmissionRoundStatus $status): string
    {
        return match ($status) {
            AdmissionRoundStatus::Draft => 'Bản nháp',
            AdmissionRoundStatus::Open => 'Đang nhận hồ sơ',
            AdmissionRoundStatus::Closed => 'Đã đóng',
            AdmissionRoundStatus::Processing => 'Đang xét tuyển',
            AdmissionRoundStatus::Published => 'Đã công bố',
        };
    }

    public static function result(AdmissionDecision $decision): string
    {
        return match ($decision) {
            AdmissionDecision::Waiting => 'Chờ kết quả',
            AdmissionDecision::Admitted => 'Trúng tuyển',
            AdmissionDecision::NotAdmitted => 'Không trúng tuyển',
        };
    }

    public static function document(DocumentStatus $status): string
    {
        return match ($status) {
            DocumentStatus::Pending => 'Chờ xét duyệt',
            DocumentStatus::Verified => 'Đã xác minh',
            DocumentStatus::Rejected => 'Bị từ chối',
        };
    }

    public static function profile(ProfileStatus $status): string
    {
        return match ($status) {
            ProfileStatus::Incomplete => 'Chưa hoàn thiện',
            ProfileStatus::Complete => 'Đã hoàn thiện',
            ProfileStatus::NeedsRevision => 'Cần bổ sung',
            ProfileStatus::Verified => 'Đã xác minh',
            ProfileStatus::Rejected => 'Bị từ chối',
        };
    }

    public static function verification(VerificationStatus $status): string
    {
        return match ($status) {
            VerificationStatus::Pending => 'Chờ xét duyệt',
            VerificationStatus::Verified => 'Đã xác minh',
            VerificationStatus::Rejected => 'Bị từ chối',
        };
    }
}
