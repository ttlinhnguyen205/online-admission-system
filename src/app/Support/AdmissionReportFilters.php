<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Models\AdmissionRound;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class AdmissionReportFilters
{
    public function __construct(public string $roundFilter = '', public string $statusFilter = '', public string $search = '') {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $data = Validator::make(['filters' => $input], [
            'filters' => ['array:roundFilter,statusFilter,search'],
            'filters.roundFilter' => ['nullable', 'integer', 'min:1', Rule::exists(AdmissionRound::class, 'id')],
            'filters.statusFilter' => ['nullable', Rule::in(['all', ...array_map(fn (ApplicationStatus $status): string => $status->value, self::statuses())])],
            'filters.search' => ['nullable', 'string', 'max:100'],
        ], [], [
            'filters.roundFilter' => 'đợt tuyển sinh', 'filters.statusFilter' => 'trạng thái hồ sơ', 'filters.search' => 'từ khóa',
        ])->validate()['filters'];

        return new self((string) ($data['roundFilter'] ?? ''), (string) ($data['statusFilter'] ?? ''), trim($data['search'] ?? ''));
    }

    /** @return list<ApplicationStatus> */
    public static function statuses(): array
    {
        return array_values(array_filter(ApplicationStatus::cases(), fn (ApplicationStatus $status): bool => $status !== ApplicationStatus::Draft));
    }

    /** @return array{roundFilter: string, statusFilter: string, search: string} */
    public function parameters(): array
    {
        return ['roundFilter' => $this->roundFilter, 'statusFilter' => $this->statusFilter, 'search' => $this->search];
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        $round = $this->roundFilter === '' ? null : AdmissionRound::query()->find((int) $this->roundFilter);

        return [
            'Đợt tuyển sinh' => $round === null ? 'Tất cả đợt' : $round->name.' ('.$round->code.')',
            'Trạng thái hồ sơ' => in_array($this->statusFilter, ['', 'all'], true) ? 'Tất cả hồ sơ đã nộp' : CandidateStatusLabels::application(ApplicationStatus::from($this->statusFilter)),
            'Tìm kiếm' => $this->search === '' ? 'Không giới hạn' : $this->search,
        ];
    }
}
