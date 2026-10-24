<?php

namespace Database\Seeders;

use App\Actions\AdmissionCatalogLock;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\CandidateMajorOffering;
use App\Models\Major;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RealisticAdmissionCatalogSeeder extends Seeder
{
    public const PREFIX = 'RAC26-';

    public const MARKER = 'realistic_admission_catalog.v1.seeded';

    /**
     * Illustrative local catalog, not an institution's official admission plan.
     * Dates, annual VND tuition, quotas and historical cutoffs are fictional.
     * Legacy pathways deliberately reuse approved engine codes without changing them.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Danh mục minh họa chỉ dành cho local/testing.');
        }

        DB::transaction(function (): void {
            AdmissionCatalogLock::acquire();
            $marker = DB::table('activity_logs')->where('action', self::MARKER)->first();
            if ($marker !== null) {
                $manifest = json_decode($marker->new_values, true, flags: JSON_THROW_ON_ERROR);
                foreach (['admission_rounds', 'majors', 'admission_methods', 'admission_programs', 'candidate_major_offerings'] as $table) {
                    $ids = $manifest[$table] ?? [];
                    if ($ids === [] || DB::table($table)->whereIn('id', $ids)->count() !== count($ids)) {
                        throw new RuntimeException('Danh mục RAC26 thiếu bản ghi; không tự khôi phục hoặc ghi đè.');
                    }
                }

                return;
            }
            foreach ([AdmissionRound::class, Major::class, AdmissionMethod::class] as $model) {
                if ($model::where('code', 'like', self::PREFIX.'%')->exists()) {
                    throw new RuntimeException('Định danh RAC26 đã có nhưng thiếu dấu hoàn tất; không ghi đè dữ liệu.');
                }
            }
            $methods = [];
            foreach ([
                'DEMO-THPT-A00' => ['type' => 'weighted', 'score_type' => 'thpt', 'subjects' => ['MATH', 'PHYSICS', 'CHEMISTRY']],
                'DEMO-HB-D01' => ['type' => 'weighted', 'score_type' => 'hoc_ba', 'subjects' => ['MATH', 'LITERATURE', 'ENG']],
            ] as $code => $descriptor) {
                $method = AdmissionMethod::where('code', $code)->lockForUpdate()->first();
                $weights = $code === 'DEMO-THPT-A00' ? ['MATH' => 1, 'PHYSICS' => 1, 'CHEMISTRY' => 1] : ['MATH' => 1, 'LITERATURE' => 1, 'ENG' => 2];
                if ($method === null || ! $method->is_active || $method->score_config != ['weights' => $weights]
                    || config('admission_engine.methods.'.$code) !== $descriptor) {
                    throw new RuntimeException('Cần phương thức đang hoạt động và hợp đồng engine tương thích: '.$code.'. Không tự sửa danh mục hoặc cấu hình.');
                }
                $methods[$code] = $method;
            }
            $manifest = array_fill_keys(['admission_rounds', 'majors', 'admission_methods', 'admission_programs', 'candidate_major_offerings'], []);
            foreach ([
                ['DGNL', 'Xét kết quả đánh giá năng lực', 'Chưa xác định HSA/V-ACT và chính sách quy đổi. Không dùng điểm khác thang để xếp hạng chung.'],
                ['DGTD', 'Xét kết quả đánh giá tư duy', 'Chưa có hợp đồng tính điểm DGTD trong engine legacy; không tạo kết quả giả.'],
                ['THANG', 'Xét tuyển thẳng', 'Chưa có quy tắc tự động đánh giá điều kiện thành tích/hồ sơ để phân bổ kết quả.'],
            ] as [$code, $name, $reason]) {
                $method = AdmissionMethod::create(['code' => self::PREFIX.$code, 'name' => $name.' (Danh mục minh họa)',
                    'description' => $reason.' Chỉ hiển thị quản trị; ngừng nhận đăng ký. Không phải chính sách tuyển sinh chính thức 2026.',
                    'score_config' => null, 'is_active' => false]);
                $manifest['admission_methods'][] = $method->id;
            }
            $majors = [];
            foreach ($this->majors() as $row) {
                [$code, $name, $description, $fee] = $row;
                $major = Major::create(['code' => self::PREFIX.$code, 'name' => $name,
                    'description' => 'Mã ngành đào tạo: '.$code.'. RAC26 là tiền tố nội bộ của dữ liệu demo. '.$description
                        .' Học phí minh họa theo năm; không phải thông báo tuyển sinh chính thức.',
                    'default_tuition_fee' => $fee, 'is_active' => true]);
                $majors[$code] = $major;
                $manifest['majors'][] = $major->id;
            }
            foreach ([
                ['ENGINE', 'Đợt 1 - Xét tuyển chính quy 2026 (Minh họa, chỉ tiêu thu nhỏ)', '2026-07-15 08:00:00', '2026-08-20 17:00:00', '2026-08-25 08:00:00', 'closed'],
                ['REGISTER', 'Đợt 2 - Đăng ký bổ sung 2026 (Lịch minh họa)', '2026-09-01 08:00:00', '2026-11-15 17:00:00', '2026-11-20 08:00:00', 'open'],
                ['PLAN', 'Đợt 3 - Dự kiến tuyển bổ sung 2026 (Lịch minh họa)', '2026-11-21 08:00:00', '2026-12-10 17:00:00', '2026-12-15 08:00:00', 'draft'],
            ] as [$key, $name, $start, $end, $result, $status]) {
                $round = AdmissionRound::create(['code' => self::PREFIX.$key, 'name' => $name, 'year' => 2026,
                    'start_date' => $start, 'end_date' => $end, 'result_date' => $result, 'status' => $status]);
                $manifest['admission_rounds'][] = $round->id;
                foreach ($this->majors() as $index => [$code, $majorName, $description, $fee, $methodCode, $quota, $minimum, $cutoff]) {
                    $program = AdmissionProgram::create(['admission_round_id' => $round->id, 'major_id' => $majors[$code]->id,
                        'admission_method_id' => $methods[$methodCode]->id,
                        'quota' => $key === 'ENGINE' ? ($index < 3 ? 1 : 3 + $index % 5) : ($key === 'PLAN' ? intdiv($quota, 2) : $quota),
                        'minimum_score' => $minimum, 'previous_cutoff_score' => $cutoff, 'tuition_fee' => $fee,
                        'status' => $key === 'PLAN' ? 'inactive' : 'active']);
                    $manifest['admission_programs'][] = $program->id;
                    $offering = CandidateMajorOffering::create(['admission_round_id' => $round->id, 'major_id' => $majors[$code]->id,
                        'admission_program_id' => $program->id, 'is_selectable' => $key === 'REGISTER']);
                    $manifest['candidate_major_offerings'][] = $offering->id;
                }
            }
            DB::table('activity_logs')->insert(['action' => self::MARKER, 'new_values' => json_encode($manifest, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        });
    }

    /**
     * Codes verified against university publications, accessed 2026-10-23:
     * https://pdaotao.huit.edu.vn/tin-tuc/danh-muc-nganh-dao-tao-trinh-do-dai-hoc
     * https://admission.tdtu.edu.vn/sites/admission/files/Tuyen-sinh/2026/TB-Xettuyenthang/Danh-muc-nganh-xet-tuyen-thang-2026.pdf
     * Only code/name mappings use these sources; all other values are illustrative.
     * THPT A00: sum /30. Legacy HB D01: Math + Literature + 2*English /40.
     * No priority bonus, certificate conversion or multi-method normalization is implied.
     *
     * @return list<array{string, string, string, int, string, int, int, float}>
     */
    private function majors(): array
    {
        return [
            ['7480201', 'Công nghệ thông tin', 'Ứng dụng công nghệ vào phần mềm và hệ thống doanh nghiệp.', 32000000, 'DEMO-THPT-A00', 120, 18, 24.5],
            ['7480101', 'Khoa học máy tính', 'Nền tảng thuật toán, tính toán và nghiên cứu máy tính.', 36000000, 'DEMO-THPT-A00', 90, 19, 25.5],
            ['7480103', 'Kỹ thuật phần mềm', 'Phân tích, thiết kế, kiểm thử và vận hành sản phẩm phần mềm.', 34000000, 'DEMO-THPT-A00', 100, 20, 25.0],
            ['7480107', 'Trí tuệ nhân tạo', 'Học máy và phát triển ứng dụng trí tuệ nhân tạo.', 38000000, 'DEMO-THPT-A00', 60, 22, 26.5],
            ['7480202', 'An toàn thông tin', 'Bảo vệ dữ liệu, hệ thống và ứng phó sự cố an ninh mạng.', 35000000, 'DEMO-THPT-A00', 70, 21, 25.0],
            ['7340101', 'Quản trị kinh doanh', 'Tổ chức hoạt động kinh doanh và quản trị doanh nghiệp.', 28000000, 'DEMO-HB-D01', 120, 24, 29.0],
            ['7340301', 'Kế toán', 'Kế toán tài chính, kế toán quản trị và kiểm soát nội bộ.', 26000000, 'DEMO-HB-D01', 80, 24, 28.0],
            ['7340201', 'Tài chính - Ngân hàng', 'Phân tích tài chính và nghiệp vụ ngân hàng.', 29000000, 'DEMO-HB-D01', 90, 25, 29.5],
            ['7510605', 'Logistics và Quản lý chuỗi cung ứng', 'Tổ chức vận tải, kho vận và chuỗi cung ứng.', 33000000, 'DEMO-HB-D01', 100, 26, 31.0],
            ['7340115', 'Marketing', 'Nghiên cứu thị trường, thương hiệu và truyền thông tiếp thị.', 31000000, 'DEMO-HB-D01', 100, 26, 31.5],
            ['7220201', 'Ngôn ngữ Anh', 'Năng lực ngôn ngữ và ứng dụng tiếng Anh trong công việc.', 30000000, 'DEMO-HB-D01', 80, 27, 32.0],
            ['7340122', 'Thương mại điện tử', 'Vận hành nền tảng và phát triển kinh doanh trực tuyến.', 32000000, 'DEMO-HB-D01', 90, 25, 30.5],
            ['7340120', 'Kinh doanh quốc tế', 'Giao dịch thương mại và quản trị hoạt động kinh doanh quốc tế.', 32000000, 'DEMO-HB-D01', 80, 26, 31.0],
            ['7460108', 'Khoa học dữ liệu', 'Thống kê, xử lý và phân tích dữ liệu phục vụ quyết định.', 36000000, 'DEMO-THPT-A00', 60, 21, 25.5],
            ['7340205', 'Công nghệ tài chính', 'Ứng dụng công nghệ trong sản phẩm và dịch vụ tài chính.', 35000000, 'DEMO-HB-D01', 60, 26, 31.0],
        ];
    }
}
