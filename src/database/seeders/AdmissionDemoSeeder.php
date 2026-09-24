<?php

namespace Database\Seeders;

use App\Enums\AdmissionRoundStatus;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\CandidateMajorOffering;
use App\Models\Major;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdmissionDemoSeeder extends Seeder
{
    /**
     * Seed fictional 2026 admission data. Re-running restores DEMO catalog values.
     * Tuition amounts are illustrative annual fees in VND, not official quotes.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $rounds = [];
            foreach ([
                ['DEMO-2026-D1', 'Đợt 1 - Tuyển sinh chính quy 2026 (Demo)', '2026-07-01 08:00:00', '2026-08-15 17:00:00', '2026-08-20 08:00:00', AdmissionRoundStatus::Published],
                ['DEMO-2026-D2', 'Đợt 2 - Xét tuyển bổ sung 2026 (Demo)', '2026-09-01 08:00:00', '2026-09-30 17:00:00', '2026-10-05 08:00:00', AdmissionRoundStatus::Open],
                ['DEMO-2026-D3', 'Đợt 3 - Dự kiến tuyển bổ sung 2026 (Demo)', '2026-10-10 08:00:00', '2026-10-25 17:00:00', null, AdmissionRoundStatus::Draft],
            ] as [$code, $name, $start, $end, $result, $status]) {
                $rounds[$code] = AdmissionRound::query()->updateOrCreate(['code' => $code], [
                    'name' => $name, 'year' => 2026, 'start_date' => $start,
                    'end_date' => $end, 'result_date' => $result, 'status' => $status,
                ]);
            }

            $majors = [];
            foreach ([
                ['7480201', 'Công nghệ thông tin', 'Phát triển phần mềm và hệ thống thông tin.', 32000000, true],
                ['7480101', 'Khoa học máy tính', 'Thuật toán, trí tuệ nhân tạo và khoa học dữ liệu.', 35000000, true],
                ['7340101', 'Quản trị kinh doanh', 'Quản trị doanh nghiệp và khởi nghiệp.', 28000000, true],
                ['7340201', 'Tài chính - Ngân hàng', 'Tài chính doanh nghiệp và nghiệp vụ ngân hàng.', 29000000, true],
                ['7340301', 'Kế toán', 'Kế toán doanh nghiệp và kiểm toán.', 26000000, true],
                ['7220201', 'Ngôn ngữ Anh', 'Tiếng Anh thương mại và biên phiên dịch.', 30000000, true],
                ['7510605', 'Logistics và Quản lý chuỗi cung ứng', 'Vận tải, kho vận và quản lý chuỗi cung ứng.', 33000000, true],
                ['7810103', 'Quản trị dịch vụ du lịch và lữ hành', 'Tạm ngừng tuyển bổ sung; học phí đang cập nhật.', null, false],
            ] as [$code, $name, $description, $fee, $active]) {
                $majors[$code] = Major::query()->updateOrCreate(['code' => 'DEMO-'.$code], [
                    'name' => $name, 'description' => $description,
                    'default_tuition_fee' => $fee, 'is_active' => $active,
                ]);
            }

            $methods = [];
            foreach ([
                ['THPT-A00', 'Điểm thi tốt nghiệp THPT - A00', 'Toán, Vật lý, Hóa học; thang điểm 30.', ['MATH' => 1, 'PHYSICS' => 1, 'CHEMISTRY' => 1], true],
                ['HB-D01', 'Học bạ THPT - D01', 'Toán, Ngữ văn, Tiếng Anh (hệ số 2); thang điểm 40.', ['MATH' => 1, 'LITERATURE' => 1, 'ENG' => 2], true],
                ['DGNL', 'Đánh giá năng lực', 'Điểm bài thi đánh giá năng lực, thang điểm 1200; không dùng trọng số môn.', null, true],
                ['THANG', 'Xét tuyển thẳng', 'Xét hồ sơ thành tích; tạm ngừng tiếp nhận bổ sung, không áp dụng điểm số.', null, false],
            ] as [$code, $name, $description, $weights, $active]) {
                $methods[$code] = AdmissionMethod::query()->updateOrCreate(['code' => 'DEMO-'.$code], [
                    'name' => $name, 'description' => $description,
                    'score_config' => $weights === null ? null : ['weights' => $weights], 'is_active' => $active,
                ]);
            }

            $programs = [];
            foreach ([
                ['D1', '7480201', 'THPT-A00', 120, 22, 25.750, 32000000, 'active'],
                ['D1', '7480101', 'THPT-A00', 80, 23, 26.125, 35000000, 'active'],
                ['D1', '7340101', 'HB-D01', 100, 24, 28.500, 28000000, 'active'],
                ['D1', '7340201', 'THPT-A00', 90, 20, 23.250, 29000000, 'active'],
                ['D1', '7340301', 'HB-D01', 70, 23, 26.750, 26000000, 'active'],
                ['D1', '7220201', 'HB-D01', 100, 26, 31.250, 30000000, 'active'],
                ['D1', '7510605', 'DGNL', 60, 700, 825, 33000000, 'active'],
                ['D1', '7810103', 'THANG', 5, null, null, null, 'inactive'],
                ['D2', '7480201', 'DGNL', 25, 750, 850, 32000000, 'active'],
                ['D2', '7480101', 'DGNL', 15, 800, 900, 35000000, 'active'],
                ['D2', '7340101', 'THPT-A00', 40, 19, 22.500, 28000000, 'active'],
                ['D2', '7340201', 'HB-D01', 30, 24, 28, 29000000, 'active'],
                ['D2', '7340301', 'THPT-A00', 35, 18, 21.250, 26000000, 'active'],
                ['D2', '7220201', 'HB-D01', 20, 27, 31.250, 31000000, 'active'],
                ['D2', '7510605', 'THPT-A00', 0, 21, 24.500, 33000000, 'inactive'],
                ['D3', '7340101', 'HB-D01', 20, 24, 28.500, 28000000, 'inactive'],
                ['D3', '7340301', 'DGNL', 15, 650, null, 26000000, 'inactive'],
                ['D3', '7810103', 'THANG', 5, null, null, null, 'inactive'],
            ] as [$round, $major, $method, $quota, $minimum, $cutoff, $fee, $status]) {
                $admissionRound = $rounds['DEMO-2026-'.$round]
                    ?? throw new \RuntimeException("Missing demo admission round: DEMO-2026-{$round}");

                $majorModel = $majors[$major]
                    ?? throw new \RuntimeException("Missing demo major: {$major}");

                $admissionMethod = $methods[$method]
                    ?? throw new \RuntimeException("Missing demo admission method: {$method}");

                $programs[$round.':'.$major.':'.$method] = AdmissionProgram::query()->updateOrCreate([
                    'admission_round_id' => $admissionRound->id,
                    'major_id' => $majorModel->id,
                    'admission_method_id' => $admissionMethod->id,
                ], [
                    'quota' => $quota,
                    'minimum_score' => $minimum,
                    'previous_cutoff_score' => $cutoff,
                    'tuition_fee' => $fee,
                    'status' => $status,
                ]);
            }

            /**
             * Explicit transitional mappings for the open demo intake only.
             * Each wish uses ONE legacy pathway, not automatic evaluation under all methods.
             * Existing mappings are preserved on repeat seeds, especially once wishes exist.
             */
            foreach ([
                ['D2', '7480201', 'DGNL'],
                ['D2', '7480101', 'DGNL'],
                ['D2', '7340101', 'THPT-A00'],
                ['D2', '7340201', 'HB-D01'],
                ['D2', '7340301', 'THPT-A00'],
                ['D2', '7220201', 'HB-D01'],
            ] as [$round, $major, $method]) {
                $program = $programs[$round.':'.$major.':'.$method]
                    ?? throw new \RuntimeException('Missing explicit demo compatibility program: '.$round.':'.$major.':'.$method);
                CandidateMajorOffering::query()->firstOrCreate([
                    'admission_round_id' => $program->admission_round_id,
                    'major_id' => $program->major_id,
                ], ['admission_program_id' => $program->id, 'is_selectable' => true]);
            }
        });
    }
}
