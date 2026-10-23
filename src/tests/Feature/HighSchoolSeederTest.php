<?php

use App\Models\CandidateProfile;
use App\Models\HighSchool;
use App\Models\Province;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\HighSchoolSeeder;
use Database\Seeders\ProvinceSeeder;

test('default seeding includes schools for all 34 provinces', function () {
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('provinces', 34);
    $this->assertDatabaseCount('high_schools', 2784);
    expect(Province::query()->whereDoesntHave('highSchools')->count())->toBe(0);
    expect(HighSchool::query()->whereDoesntHave('province')->count())->toBe(0);
    foreach (['01' => 231, '31' => 119, '48' => 77, '79' => 275] as $code => $count) {
        expect(Province::query()->where('code', (string) $code)->sole()->highSchools()->count())->toBe($count);
    }
});

test('repeated school seeding preserves existing school identities and candidate references', function () {
    $this->seed(ProvinceSeeder::class);
    $province = Province::query()->where('code', '01')->sole();
    $existing = [
        ['0103', 'THPT Phan Đình Phùng'],
        ['1101', 'THPT Đoàn Kết-Hai Bà Trưng'],
        ['1102', 'THPT Thăng Long'],
        ['1502', 'THPT Nguyễn Gia Thiều'],
        ['0502', 'THPT Chương Mỹ A'],
        ['0503', 'THPT Chương Mỹ B'],
        ['1301', 'THPT Trần Phú-Hoàn Kiếm'],
        ['1302', 'THPT Việt Đức'],
        ['0901', 'THPT Cao Bá Quát-Gia Lâm'],
        ['0902', 'THPT Dương Xá'],
    ];
    foreach ($existing as [$code, $name]) {
        HighSchool::query()->create(['province_id' => $province->id, 'code' => $code, 'name' => $name]);
        CandidateProfile::factory()->create(['province_code' => '01', 'high_school_code' => $code, 'high_school_name' => $name]);
    }
    $outsideCatalog = HighSchool::query()->create(['province_id' => $province->id, 'code' => 'existing-code', 'name' => 'Existing school outside reviewed catalog']);
    $original = HighSchool::query()->orderBy('id')->get()->mapWithKeys(fn (HighSchool $school): array => [$school->id => $school->getAttributes()])->all();
    $profiles = CandidateProfile::query()->orderBy('id')->get()->toArray();

    $this->seed(HighSchoolSeeder::class);
    $ids = HighSchool::query()->orderBy('id')->pluck('id')->all();
    $this->seed(HighSchoolSeeder::class);

    $this->assertDatabaseCount('high_schools', 2785);
    expect(HighSchool::query()->orderBy('id')->pluck('id')->all())->toBe($ids);
    expect(HighSchool::query()->whereKey(array_keys($original))->orderBy('id')->get()->mapWithKeys(fn (HighSchool $school): array => [$school->id => $school->getAttributes()])->all())->toBe($original);
    expect(CandidateProfile::query()->orderBy('id')->get()->toArray())->toBe($profiles);
    $this->assertModelExists($outsideCatalog);
    foreach ($existing as [$code, $name]) {
        expect(HighSchool::query()->where('province_id', $province->id)->where('name', $name)->count())->toBe(1);
        $this->assertDatabaseHas('high_schools', ['province_id' => $province->id, 'code' => $code, 'name' => $name]);
    }
    $this->assertDatabaseMissing('high_schools', ['province_id' => $province->id, 'code' => '066']);
});

test('repeated province seeding preserves the 34 province codes and ids', function () {
    $this->seed(ProvinceSeeder::class);
    $original = Province::query()->orderBy('code')->get(['id', 'code', 'name'])->toArray();

    $this->seed(ProvinceSeeder::class);

    $this->assertDatabaseCount('provinces', 34);
    expect(Province::query()->orderBy('code')->get(['id', 'code', 'name'])->toArray())->toBe($original);
});

test('school seeding fails without partial inserts when a province is missing', function () {
    Province::query()->create(['code' => '01', 'name' => 'Thành phố Hà Nội']);

    expect(fn () => $this->seed(HighSchoolSeeder::class))->toThrow(RuntimeException::class, 'Hãy chạy ProvinceSeeder trước.');

    $this->assertDatabaseCount('high_schools', 0);
});

test('school code conflicts roll back new schools without overwriting existing data', function () {
    $this->seed(ProvinceSeeder::class);
    $province = Province::query()->where('code', '01')->sole();
    $school = HighSchool::query()->create(['province_id' => $province->id, 'code' => '066', 'name' => 'Existing school requiring review']);

    expect(fn () => $this->seed(HighSchoolSeeder::class))->toThrow(RuntimeException::class, 'không ghi đè');

    $this->assertDatabaseCount('high_schools', 1);
    expect($school->fresh()->name)->toBe('Existing school requiring review');
});

test('existing duplicate names require review without deleting either school', function () {
    $this->seed(ProvinceSeeder::class);
    $province = Province::query()->where('code', '01')->sole();
    HighSchool::query()->create(['province_id' => $province->id, 'code' => '0103', 'name' => 'THPT Phan Đình Phùng']);
    HighSchool::query()->create(['province_id' => $province->id, 'code' => 'HN002', 'name' => 'THPT Phan Đình Phùng']);

    expect(fn () => $this->seed(HighSchoolSeeder::class))->toThrow(RuntimeException::class, 'trùng tên cần kiểm tra');

    $this->assertDatabaseCount('high_schools', 2);
});
