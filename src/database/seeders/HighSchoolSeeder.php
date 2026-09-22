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
        $path = database_path('data/high_schools.csv');

        if (! file_exists($path)) {
            throw new RuntimeException(
                'Không tìm thấy file database/data/high_schools.csv.'
            );
        }

        $file = fopen($path, 'r');

        if ($file === false) {
            throw new RuntimeException(
                'Không thể mở file database/data/high_schools.csv.'
            );
        }

        // Đọc dòng tiêu đề CSV.
        $header = fgetcsv($file);

        if ($header !== ['province_code', 'school_code', 'school_name']) {
            fclose($file);

            throw new RuntimeException(
                'CSV phải có tiêu đề: province_code,school_code,school_name'
            );
        }

        $line = 1;

        while (($row = fgetcsv($file)) !== false) {
            $line++;

            if (count($row) !== 3) {
                fclose($file);

                throw new RuntimeException(
                    "Dữ liệu không hợp lệ tại dòng {$line}."
                );
            }

            [$provinceCode, $schoolCode, $schoolName] = array_map(
                static fn (?string $value): string => trim($value ?? ''),
                $row
            );

            if (
                $provinceCode === ''
                || $schoolCode === ''
                || $schoolName === ''
            ) {
                fclose($file);

                throw new RuntimeException(
                    "Thiếu dữ liệu tại dòng {$line}."
                );
            }

            $province = Province::where('code', $provinceCode)->first();

            if ($province === null) {
                fclose($file);

                throw new RuntimeException(
                    "Không tìm thấy mã tỉnh {$provinceCode} tại dòng {$line}."
                );
            }

            HighSchool::updateOrCreate(
                [
                    'province_id' => $province->id,
                    'code' => $schoolCode,
                ],
                [
                    'name' => $schoolName,
                ]
            );
        }

        fclose($file);
    }
}
