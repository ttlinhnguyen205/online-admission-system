<?php

namespace App\Support;

use App\Actions\AdmissionStatistics;
use App\Enums\AdmissionDecision;
use App\Enums\ApplicationStatus;
use App\Enums\WishStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options as PdfOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class AdmissionReportWriter
{
    public function __construct(private AdmissionStatistics $statistics) {}

    public function write(User $actor, AdmissionReportFilters $filters, string $format, string $path): void
    {
        $actor = $this->statistics->authorize($actor);
        if ($format === 'xlsx' && ! extension_loaded('zip')) {
            throw ValidationException::withMessages(['export' => __('PHP server chưa nạp extension ZIP. Hãy khởi động lại PHP server sau khi bật ZIP để xuất Excel.')]);
        }
        $summary = $this->statistics->build($actor, $filters);
        $maximum = max(1, (int) config('admission_reports.'.$format.'_max_rows'));
        $total = $summary['metrics']['Hồ sơ đã nộp'] + $summary['metrics']['Kết quả xét tuyển'];
        if ($format === 'xlsx') {
            $total += $summary['metrics']['Nguyện vọng'];
        }
        $this->checkLimit($total, $maximum);
        $generatedAt = now()->format('d/m/Y H:i:s').' ('.config('app.timezone').')';
        $scope = $actor->isAdmin() ? 'Bao gồm kết quả nội bộ chưa công bố; chỉ dùng cho quản trị.' : 'Chỉ bao gồm kết quả đã công bố.';
        $applications = $this->applicationRows($actor, $filters);
        $results = $this->resultRows($actor, $filters);
        $headers = $this->headers();

        if ($format === 'pdf') {
            $applicationRows = [];
            $resultRows = [];
            $count = 0;
            foreach ($applications as $row) {
                $this->checkLimit(++$count, $maximum);
                $applicationRows[] = $row;
            }
            foreach ($results as $row) {
                $this->checkLimit(++$count, $maximum);
                $resultRows[] = $row;
            }
            $options = new PdfOptions;
            $options->setDefaultFont('DejaVu Sans');
            $options->setIsRemoteEnabled(false);
            $options->setIsPhpEnabled(false);
            $options->setIsJavascriptEnabled(false);
            $options->setTempDir(dirname($path));
            $options->setFontCache(dirname($path));
            $options->setChroot([base_path('vendor/dompdf/dompdf/lib/fonts'), dirname($path)]);
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view('reports.admissions', compact('summary', 'filters', 'generatedAt', 'scope', 'applicationRows', 'resultRows', 'headers'))->render(), 'UTF-8');
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();
            file_put_contents($path, $pdf->output());

            return;
        }

        $options = new Options;
        $options->setTempFolder(dirname($path));
        $options->DEFAULT_ROW_STYLE = (new Style)->setFontSize(11)->setShouldWrapText();
        $options->DEFAULT_COLUMN_WIDTH = 24;
        $writer = new Writer($options);
        $writer->openToFile($path);
        try {
            $writer->getCurrentSheet()->setName('Tổng quan');
            $this->addRow($writer, ['BÁO CÁO TUYỂN SINH'], true);
            $this->addRow($writer, ['Thời gian tạo', $generatedAt]);
            $this->addRow($writer, ['Phạm vi kết quả', $scope]);
            foreach ($filters->labels() as $label => $value) {
                $this->addRow($writer, [$label, $value]);
            }
            $this->addRow($writer, ['Chỉ số', 'Số lượng'], true);
            foreach ($summary['metrics'] as $label => $value) {
                $this->addRow($writer, [$label, $value]);
            }
            foreach ($summary['charts'] as $title => $rows) {
                $this->addRow($writer, [$title, 'Số nguyện vọng'], true);
                foreach ($rows as $row) {
                    $this->addRow($writer, [$row['label'], $row['count']]);
                }
            }
            $count = 0;
            foreach (['Hồ sơ' => $applications, 'Nguyện vọng' => $this->wishRows($actor, $filters), 'Kết quả' => $results] as $sheet => $rows) {
                $writer->addNewSheetAndMakeItCurrent()->setName($sheet);
                $this->addRow($writer, $headers[$sheet], true);
                $empty = true;
                foreach ($rows as $row) {
                    $this->checkLimit(++$count, $maximum);
                    $this->addRow($writer, $row);
                    $empty = false;
                }
                if ($empty) {
                    $this->addRow($writer, ['Không có dữ liệu phù hợp.']);
                }
            }
        } finally {
            $writer->close();
        }
    }

    /** @return array<string, list<string>> */
    private function headers(): array
    {
        return [
            'Hồ sơ' => ['Mã hồ sơ', 'Mã thí sinh', 'Họ và tên', 'Đợt tuyển sinh', 'Trạng thái hồ sơ', 'Ngày nộp', 'Ngày xét duyệt'],
            'Nguyện vọng' => ['Mã hồ sơ', 'Mã thí sinh', 'Thứ tự nguyện vọng', 'Mã ngành', 'Ngành', 'Phương thức', 'Chương trình', 'Trạng thái nguyện vọng'],
            'Kết quả' => ['Mã hồ sơ', 'Mã thí sinh', 'Ngành', 'Phương thức', 'Kết quả', 'Điểm xét tuyển', 'Thứ hạng', 'Công bố', 'Xác nhận nhập học'],
        ];
    }

    /** @return iterable<list<string|int|float|null>> */
    private function applicationRows(User $actor, AdmissionReportFilters $filters): iterable
    {
        foreach ($this->statistics->applications($actor, $filters)->with(['candidateProfile.user', 'admissionRound'])->lazyById($this->chunkSize()) as $application) {
            yield [$application->application_code, $application->candidateProfile->candidate_code, $application->candidateProfile->user->name,
                $application->admissionRound->name.' ('.$application->admissionRound->code.')', CandidateStatusLabels::application(ApplicationStatus::from($application->getRawOriginal('status'))),
                $this->date($application, 'submitted_at'), $this->date($application, 'reviewed_at')];
        }
    }

    /** @return iterable<list<string|int|float|null>> */
    private function wishRows(User $actor, AdmissionReportFilters $filters): iterable
    {
        foreach ($this->statistics->wishes($actor, $filters)->with(['application.candidateProfile', 'admissionProgram.major', 'admissionProgram.admissionMethod', 'admissionProgram.admissionRound'])->lazyById($this->chunkSize()) as $wish) {
            $program = $wish->admissionProgram;
            $status = match (WishStatus::from($wish->getRawOriginal('status'))) {
                WishStatus::Pending => 'Chờ xét tuyển', WishStatus::Eligible => 'Đủ điều kiện', WishStatus::Ineligible => 'Không đủ điều kiện',
                WishStatus::Admitted => 'Trúng tuyển', WishStatus::Rejected => 'Không trúng tuyển',
            };
            yield [$wish->application->application_code, $wish->application->candidateProfile->candidate_code, $wish->priority,
                $program->major->code, $program->major->name, $program->admissionMethod->name,
                $program->major->name.' — '.$program->admissionMethod->name.' — '.$program->admissionRound->code, $status];
        }
    }

    /** @return iterable<list<string|int|float|null>> */
    private function resultRows(User $actor, AdmissionReportFilters $filters): iterable
    {
        foreach ($this->statistics->results($actor, $filters)->with(['admissionWish.application.candidateProfile', 'admissionWish.application.admissionRound', 'admissionWish.admissionProgram.major', 'admissionWish.admissionProgram.admissionMethod'])->lazyById($this->chunkSize()) as $result) {
            $wish = $result->admissionWish;
            $published = $result->published_at !== null && CarbonImmutable::parse($result->published_at)->lessThanOrEqualTo(now())
                && $wish->application->admissionRound->getRawOriginal('status') === 'published';
            yield [$wish->application->application_code, $wish->application->candidateProfile->candidate_code,
                $wish->admissionProgram->major->name, $wish->admissionProgram->admissionMethod->name, CandidateStatusLabels::result(AdmissionDecision::from($result->getRawOriginal('decision'))),
                (float) $result->final_score, $result->rank,
                $published ? $this->date($result, 'published_at') : 'Chưa công bố', $this->date($result, 'confirmed_at')];
        }
    }

    /** @param list<string|int|float|null> $values */
    private function addRow(Writer $writer, array $values, bool $header = false): void
    {
        $style = $header ? (new Style)->setFontBold()->setBackgroundColor('E2E8F0')->setShouldWrapText() : null;
        $cells = array_map(fn (string|int|float|null $value): Cell => is_string($value) ? new StringCell($value, null) : Cell::fromValue($value), $values);
        $writer->addRow(new Row($cells, $style));
    }

    private function date(Model $model, string $attribute): string
    {
        $value = $model->getAttribute($attribute);

        return $value === null ? 'Chưa có' : CarbonImmutable::parse($value)->format('d/m/Y H:i');
    }

    private function chunkSize(): int
    {
        return max(1, (int) config('admission_reports.chunk_size', 250));
    }

    private function checkLimit(int $count, int $maximum): void
    {
        if ($count > $maximum) {
            throw ValidationException::withMessages(['export' => 'Báo cáo vượt giới hạn '.$maximum.' dòng. Vui lòng thu hẹp bộ lọc hoặc dùng Excel cho báo cáo lớn.']);
        }
    }
}
