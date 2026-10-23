<?php

namespace Database\Seeders;

use App\Models\HighSchool;
use App\Models\Province;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class HighSchoolSeeder extends Seeder
{
    /**
     * Import the reviewed subset only. Provenance and withheld source records are
     * stored alongside the catalog. Never rename/recode existing schools: profiles
     * retain school codes rather than foreign keys to this table.
     */
    public function run(): void
    {
        $catalog = json_decode(file_get_contents(database_path('data/high_schools_2026.json')), true, flags: JSON_THROW_ON_ERROR);
        $rows = $catalog['schools'];
        $keys = [];
        $names = [];

        foreach ($rows as $row) {
            $key = $row['province_code'].':'.$row['code'];
            $name = $row['province_code'].':'.$this->nameKey($row['name']);
            if (! preg_match('/\A[0-9]{2}\z/', $row['province_code'])
                || ! preg_match('/\A[0-9]{3}\z/', $row['code'])
                || $this->nameKey($row['name']) === '' || mb_strlen($row['name']) > 255
                || isset($keys[$key]) || isset($names[$name])) {
                throw new RuntimeException('Danh mục trường không hợp lệ hoặc trùng mã/tên: '.$key);
            }
            $keys[$key] = true;
            $names[$name] = true;
        }

        DB::transaction(function () use ($rows): void {
            $provinces = Province::query()->orderBy('id')->lockForUpdate()->get()->keyBy('code');
            foreach (array_unique(array_column($rows, 'province_code')) as $code) {
                if (! $provinces->has($code)) {
                    throw new RuntimeException("Không tìm thấy tỉnh/thành có mã {$code}. Hãy chạy ProvinceSeeder trước.");
                }
            }

            $byCode = [];
            $byName = [];
            foreach (HighSchool::query()->orderBy('id')->lockForUpdate()->get() as $school) {
                $codeKey = $school->province_id.':'.$school->code;
                $nameKey = $school->province_id.':'.$this->nameKey($school->name);
                if (isset($byName[$nameKey])) {
                    throw new RuntimeException('Có trường trùng tên cần kiểm tra trước khi nhập: '.$school->name);
                }
                $byCode[$codeKey] = $school;
                $byName[$nameKey] = $school;
            }

            foreach ($rows as $row) {
                $provinceId = $provinces->get($row['province_code'])->id;
                $codeKey = $provinceId.':'.$row['code'];
                $nameKey = $provinceId.':'.$this->nameKey($row['name']);
                $sameCode = $byCode[$codeKey] ?? null;
                $sameName = $byName[$nameKey] ?? null;
                if ($sameCode !== null && ($this->nameKey($sameCode->name) !== $this->nameKey($row['name'])
                    || ($sameName !== null && $sameName->id !== $sameCode->id))) {
                    throw new RuntimeException('Mã trường đã thuộc bản ghi khác, không ghi đè: '.$row['province_code'].':'.$row['code']);
                }
                if ($sameCode !== null || $sameName !== null) {
                    continue;
                }

                $school = HighSchool::query()->create([
                    'province_id' => $provinceId,
                    'code' => $row['code'],
                    'name' => $row['name'],
                ]);
                $byCode[$codeKey] = $school;
                $byName[$nameKey] = $school;
            }
        });
    }

    private function nameKey(string $name): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', Str::lower($name));
    }
}
