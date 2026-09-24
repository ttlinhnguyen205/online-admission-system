<?php

namespace Database\Seeders;

use App\Models\HighSchool;
use App\Models\Province;
use Illuminate\Database\Seeder;
use RuntimeException;

class HighSchoolSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Danh sách trường THPT theo tỉnh/thành
        |--------------------------------------------------------------------------
        |
        | Key bên ngoài phải trùng với "code" trong ProvinceSeeder.
        | Mỗi trường gồm:
        | - code: mã trường dùng trong hệ thống
        | - name: tên trường hiển thị cho thí sinh
        |
        */

        $schoolsByProvince = [

            // 01 - Thành phố Hà Nội
            '01' => [
                ['code' => 'HN001', 'name' => 'THPT Chu Văn An'],
                ['code' => 'HN002', 'name' => 'THPT Phan Đình Phùng'],
                ['code' => 'HN003', 'name' => 'THPT Việt Đức'],
                ['code' => 'HN004', 'name' => 'THPT Kim Liên'],
                ['code' => 'HN005', 'name' => 'THPT Thăng Long'],
            ],

            // 04 - Cao Bằng
            '04' => [
                ['code' => 'CB001', 'name' => 'THPT Thành phố Cao Bằng'],
                ['code' => 'CB002', 'name' => 'THPT Bế Văn Đàn'],
                ['code' => 'CB003', 'name' => 'THPT Hòa An'],
            ],

            // 08 - Tuyên Quang
            '08' => [
                ['code' => 'TQ001', 'name' => 'THPT Tân Trào'],
                ['code' => 'TQ002', 'name' => 'THPT Nguyễn Văn Huyên'],
                ['code' => 'TQ003', 'name' => 'THPT Sơn Dương'],
            ],

            // 11 - Điện Biên
            '11' => [
                ['code' => 'DB001', 'name' => 'THPT Thành phố Điện Biên Phủ'],
                ['code' => 'DB002', 'name' => 'THPT Phan Đình Giót'],
                ['code' => 'DB003', 'name' => 'THPT Thanh Chăn'],
            ],

            // 12 - Lai Châu
            '12' => [
                ['code' => 'LC001', 'name' => 'THPT Thành phố Lai Châu'],
                ['code' => 'LC002', 'name' => 'THPT Quyết Thắng'],
                ['code' => 'LC003', 'name' => 'THPT Tân Uyên'],
            ],

            // 14 - Sơn La
            '14' => [
                ['code' => 'SL001', 'name' => 'THPT Tô Hiệu'],
                ['code' => 'SL002', 'name' => 'THPT Chiềng Sinh'],
                ['code' => 'SL003', 'name' => 'THPT Mai Sơn'],
            ],

            // 15 - Lào Cai
            '15' => [
                ['code' => 'LCA001', 'name' => 'THPT Số 1 Thành phố Lào Cai'],
                ['code' => 'LCA002', 'name' => 'THPT Số 2 Thành phố Lào Cai'],
                ['code' => 'LCA003', 'name' => 'THPT Bảo Thắng'],
            ],

            // 19 - Thái Nguyên
            '19' => [
                ['code' => 'TN001', 'name' => 'THPT Lương Ngọc Quyến'],
                ['code' => 'TN002', 'name' => 'THPT Gang Thép'],
                ['code' => 'TN003', 'name' => 'THPT Dương Tự Minh'],
            ],

            // 20 - Lạng Sơn
            '20' => [
                ['code' => 'LS001', 'name' => 'THPT Việt Bắc'],
                ['code' => 'LS002', 'name' => 'THPT Hoàng Văn Thụ'],
                ['code' => 'LS003', 'name' => 'THPT Cao Lộc'],
            ],

            // 22 - Quảng Ninh
            '22' => [
                ['code' => 'QN001', 'name' => 'THPT Hòn Gai'],
                ['code' => 'QN002', 'name' => 'THPT Bãi Cháy'],
                ['code' => 'QN003', 'name' => 'THPT Uông Bí'],
            ],

            // 24 - Bắc Ninh
            '24' => [
                ['code' => 'BN001', 'name' => 'THPT Hàn Thuyên'],
                ['code' => 'BN002', 'name' => 'THPT Lý Nhân Tông'],
                ['code' => 'BN003', 'name' => 'THPT Nguyễn Đăng Đạo'],
            ],

            // 25 - Phú Thọ
            '25' => [
                ['code' => 'PT001', 'name' => 'THPT Việt Trì'],
                ['code' => 'PT002', 'name' => 'THPT Công Nghiệp Việt Trì'],
                ['code' => 'PT003', 'name' => 'THPT Long Châu Sa'],
            ],

            // 31 - Hải Phòng
            '31' => [
                ['code' => 'HP001', 'name' => 'THPT Thái Phiên'],
                ['code' => 'HP002', 'name' => 'THPT Lê Quý Đôn'],
                ['code' => 'HP003', 'name' => 'THPT Trần Nguyên Hãn'],
                ['code' => 'HP004', 'name' => 'THPT Ngô Quyền'],
            ],

            // 33 - Hưng Yên
            '33' => [
                ['code' => 'HY001', 'name' => 'THPT Hưng Yên'],
                ['code' => 'HY002', 'name' => 'THPT Nguyễn Siêu'],
                ['code' => 'HY003', 'name' => 'THPT Văn Giang'],
            ],

            // 37 - Ninh Bình
            '37' => [
                ['code' => 'NB001', 'name' => 'THPT Đinh Tiên Hoàng'],
                ['code' => 'NB002', 'name' => 'THPT Hoa Lư A'],
                ['code' => 'NB003', 'name' => 'THPT Nho Quan A'],
            ],

            // 38 - Thanh Hóa
            '38' => [
                ['code' => 'TH001', 'name' => 'THPT Đào Duy Từ'],
                ['code' => 'TH002', 'name' => 'THPT Hàm Rồng'],
                ['code' => 'TH003', 'name' => 'THPT Nguyễn Trãi'],
            ],

            // 40 - Nghệ An
            '40' => [
                ['code' => 'NA001', 'name' => 'THPT Huỳnh Thúc Kháng'],
                ['code' => 'NA002', 'name' => 'THPT Hà Huy Tập'],
                ['code' => 'NA003', 'name' => 'THPT Lê Viết Thuật'],
            ],

            // 42 - Hà Tĩnh
            '42' => [
                ['code' => 'HT001', 'name' => 'THPT Phan Đình Phùng'],
                ['code' => 'HT002', 'name' => 'THPT Thành Sen'],
                ['code' => 'HT003', 'name' => 'THPT Nguyễn Văn Trỗi'],
            ],

            // 44 - Quảng Trị
            '44' => [
                ['code' => 'QT001', 'name' => 'THPT Đông Hà'],
                ['code' => 'QT002', 'name' => 'THPT Lê Lợi'],
                ['code' => 'QT003', 'name' => 'THPT Gio Linh'],
            ],

            // 46 - Thành phố Huế
            '46' => [
                ['code' => 'HUE001', 'name' => 'THPT Nguyễn Huệ'],
                ['code' => 'HUE002', 'name' => 'THPT Hai Bà Trưng'],
                ['code' => 'HUE003', 'name' => 'THPT Gia Hội'],
            ],

            // 48 - Thành phố Đà Nẵng
            '48' => [
                ['code' => 'DN001', 'name' => 'THPT Phan Châu Trinh'],
                ['code' => 'DN002', 'name' => 'THPT Hoàng Hoa Thám'],
                ['code' => 'DN003', 'name' => 'THPT Nguyễn Trãi'],
                ['code' => 'DN004', 'name' => 'THPT Thanh Khê'],
            ],

            // 51 - Quảng Ngãi
            '51' => [
                ['code' => 'QNG001', 'name' => 'THPT Trần Quốc Tuấn'],
                ['code' => 'QNG002', 'name' => 'THPT Võ Nguyên Giáp'],
                ['code' => 'QNG003', 'name' => 'THPT Sơn Tịnh 1'],
            ],

            // 52 - Gia Lai
            '52' => [
                ['code' => 'GL001', 'name' => 'THPT Pleiku'],
                ['code' => 'GL002', 'name' => 'THPT Nguyễn Chí Thanh'],
                ['code' => 'GL003', 'name' => 'THPT Lê Lợi'],
            ],

            // 56 - Khánh Hòa
            '56' => [
                ['code' => 'KH001', 'name' => 'THPT Lý Tự Trọng'],
                ['code' => 'KH002', 'name' => 'THPT Nguyễn Văn Trỗi'],
                ['code' => 'KH003', 'name' => 'THPT Hoàng Văn Thụ'],
            ],

            // 66 - Đắk Lắk
            '66' => [
                ['code' => 'DL001', 'name' => 'THPT Buôn Ma Thuột'],
                ['code' => 'DL002', 'name' => 'THPT Hồng Đức'],
                ['code' => 'DL003', 'name' => 'THPT Lê Quý Đôn'],
            ],

            // 68 - Lâm Đồng
            '68' => [
                ['code' => 'LD001', 'name' => 'THPT Bùi Thị Xuân'],
                ['code' => 'LD002', 'name' => 'THPT Trần Phú'],
                ['code' => 'LD003', 'name' => 'THPT Đức Trọng'],
            ],

            // 75 - Đồng Nai
            '75' => [
                ['code' => 'DNA001', 'name' => 'THPT Ngô Quyền'],
                ['code' => 'DNA002', 'name' => 'THPT Nguyễn Trãi'],
                ['code' => 'DNA003', 'name' => 'THPT Trấn Biên'],
            ],

            // 79 - Thành phố Hồ Chí Minh
            '79' => [
                ['code' => 'HCM001', 'name' => 'THPT Nguyễn Thượng Hiền'],
                ['code' => 'HCM002', 'name' => 'THPT Bùi Thị Xuân'],
                ['code' => 'HCM003', 'name' => 'THPT Lê Quý Đôn'],
                ['code' => 'HCM004', 'name' => 'THPT Gia Định'],
                ['code' => 'HCM005', 'name' => 'THPT Nguyễn Hữu Cầu'],
            ],

            // 80 - Tây Ninh
            '80' => [
                ['code' => 'TNI001', 'name' => 'THPT Tây Ninh'],
                ['code' => 'TNI002', 'name' => 'THPT Trần Đại Nghĩa'],
                ['code' => 'TNI003', 'name' => 'THPT Lý Thường Kiệt'],
            ],

            // 82 - Đồng Tháp
            '82' => [
                ['code' => 'DT001', 'name' => 'THPT Thành phố Cao Lãnh'],
                ['code' => 'DT002', 'name' => 'THPT Thiên Hộ Dương'],
                ['code' => 'DT003', 'name' => 'THPT Nguyễn Quang Diêu'],
            ],

            // 86 - Vĩnh Long
            '86' => [
                ['code' => 'VL001', 'name' => 'THPT Lưu Văn Liệt'],
                ['code' => 'VL002', 'name' => 'THPT Nguyễn Thông'],
                ['code' => 'VL003', 'name' => 'THPT Vĩnh Long'],
            ],

            // 91 - An Giang
            '91' => [
                ['code' => 'AG001', 'name' => 'THPT Long Xuyên'],
                ['code' => 'AG002', 'name' => 'THPT Nguyễn Hiền'],
                ['code' => 'AG003', 'name' => 'THPT Nguyễn Công Trứ'],
            ],

            // 92 - Thành phố Cần Thơ
            '92' => [
                ['code' => 'CT001', 'name' => 'THPT Châu Văn Liêm'],
                ['code' => 'CT002', 'name' => 'THPT Bùi Hữu Nghĩa'],
                ['code' => 'CT003', 'name' => 'THPT Thốt Nốt'],
                ['code' => 'CT004', 'name' => 'THPT Nguyễn Việt Hồng'],
            ],

            // 96 - Cà Mau
            '96' => [
                ['code' => 'CM001', 'name' => 'THPT Hồ Thị Kỷ'],
                ['code' => 'CM002', 'name' => 'THPT Cà Mau'],
                ['code' => 'CM003', 'name' => 'THPT Nguyễn Việt Khái'],
            ],
        ];

        foreach ($schoolsByProvince as $provinceCode => $schools) {
            $province = Province::where('code', $provinceCode)->first();

            if ($province === null) {
                throw new RuntimeException(
                    "Không tìm thấy tỉnh/thành có mã {$provinceCode}. "
                    . "Hãy chạy ProvinceSeeder trước."
                );
            }

            foreach ($schools as $school) {
                HighSchool::updateOrCreate(
                    [
                        'province_id' => $province->id,
                        'code' => $school['code'],
                    ],
                    [
                        'name' => $school['name'],
                    ]
                );
            }
        }
    }
}