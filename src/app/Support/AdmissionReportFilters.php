<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Models\AdmissionRound;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class AdmissionReportFilters
{
    public function __construct(public string $roundFilter = '', public string $statusFilter = '', public string $search = '', public string $yearFilter = '') {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $roundExists = Rule::exists(AdmissionRound::class, 'id');
        $year = $input['yearFilter'] ?? '';
        if (is_scalar($year) && (string) $year !== '') {
            $roundExists->where('year', (string) $year);
        }
        $data = Validator::make(['filters' => $input], [
            'filters' => ['array:roundFilter,statusFilter,search,yearFilter'],
            'filters.yearFilter' => ['bail', 'nullable', 'integer', Rule::exists(AdmissionRound::class, 'year')],
            'filters.roundFilter' => ['nullable', 'integer', 'min:1', $roundExists],
            'filters.statusFilter' => ['nullable', Rule::in(['all', ...array_map(fn (ApplicationStatus $status): string => $status->value, self::statuses())])],
            'filters.search' => ['nullable', 'string', 'max:100'],
        ], [], [
            'filters.roundFilter' => 'đợt tuyển sinh', 'filters.statusFilter' => 'trạng thái hồ sơ', 'filters.search' => 'từ khóa',
            'filters.yearFilter' => 'năm tuyển sinh',
        ])->validate()['filters'];

        return new self((string) ($data['roundFilter'] ?? ''), (string) ($data['statusFilter'] ?? ''), trim($data['search'] ?? ''), (string) ($data['yearFilter'] ?? ''));
    }

    /** @return list<ApplicationStatus> */
    public static function statuses(): array
    {
        return array_values(array_filter(ApplicationStatus::cases(), fn (ApplicationStatus $status): bool => $status !== ApplicationStatus::Draft));
    }

    /** @return array{roundFilter: string, statusFilter: string, search: string, yearFilter?: string} */
    public function parameters(): array
    {
        return ['roundFilter' => $this->roundFilter, 'statusFilter' => $this->statusFilter, 'search' => $this->search,
            ...($this->yearFilter === '' ? [] : ['yearFilter' => $this->yearFilter])];
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        $round = $this->roundFilter === '' ? null : AdmissionRound::query()->find((int) $this->roundFilter);

        return [
            ...($this->yearFilter === '' ? [] : ['Năm tuyển sinh' => $this->yearFilter]),
            'Đợt tuyển sinh' => $round === null ? 'Tất cả đợt' : $round->name.' ('.$round->code.')',
            'Trạng thái hồ sơ' => in_array($this->statusFilter, ['', 'all'], true) ? 'Tất cả hồ sơ đã nộp' : CandidateStatusLabels::application(ApplicationStatus::from($this->statusFilter)),
            'Tìm kiếm' => $this->search === '' ? 'Không giới hạn' : $this->search,
        ];
    }
}
