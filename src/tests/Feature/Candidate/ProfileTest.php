<?php

use App\Actions\CandidateFiles;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Candidate\Profile;
use App\Models\CandidateProfile;
use App\Models\HighSchool;
use App\Models\Province;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function phaseFourProfileData(): array
{
    return [
        'date_of_birth' => '2008-01-02',
        'gender' => 'female',
        'ethnicity' => 'Kinh',
        'religion' => null,
        'citizen_id' => '001234567890',
        'citizen_id_issued_date' => '2022-01-02',
        'citizen_id_issued_place' => 'Cục Cảnh sát QLHC về TTXH',
        'phone' => '+84 901 234 567',
        'address' => 'Hà Nội',
        'province_code' => null,
        'high_school_code' => null,
        'high_school_name' => null,
        'graduation_year' => 2026,
        'priority_area' => null,
        'priority_object' => null,
    ];
}
function createProfileLocationFixtures(): array
{
    $haNoi = Province::create([
        'code' => '01',
        'name' => 'Thành phố Hà Nội',
    ]);

    $phanDinhPhung = HighSchool::create([
        'province_id' => $haNoi->id,
        'code' => '0103',
        'name' => 'THPT Phan Đình Phùng',
    ]);

    $caoBang = Province::create([
        'code' => '04',
        'name' => 'Tỉnh Cao Bằng',
    ]);

    $caoBangSchool = HighSchool::create([
        'province_id' => $caoBang->id,
        'code' => '0401',
        'name' => 'THPT Cao Bằng Test',
    ]);

    return [
        'haNoi' => $haNoi,
        'phanDinhPhung' => $phanDinhPhung,
        'caoBang' => $caoBang,
        'caoBangSchool' => $caoBangSchool,
    ];
}
test('candidate profile saves province and high school from selected lookup ids', function () {
    $locations = createProfileLocationFixtures();

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('selectedProvinceId', $locations['haNoi']->id)
        ->set('selectedHighSchoolId', $locations['phanDinhPhung']->id)
        ->call('save')
        ->assertHasNoErrors();

    $profile = $user->candidateProfile()->sole();

    expect($profile->province_code)->toBe('01');
    expect($profile->high_school_code)->toBe('0103');
    expect($profile->high_school_name)->toBe('THPT Phan Đình Phùng');
});
test('only active candidates without profiles may create profiles', function (UserRole $role, UserStatus $status) {
    $user = User::factory()->create(['role' => $role, 'status' => $status]);
    expect(Gate::forUser($user)->allows('create', CandidateProfile::class))->toBe($role === UserRole::Candidate && $status === UserStatus::Active);
    CandidateProfile::factory()->for($user)->create();
    expect(Gate::forUser($user)->allows('create', CandidateProfile::class))->toBeFalse();
})->with(UserRole::cases())->with(UserStatus::cases());
test('changing province clears the selected high school', function () {
    $locations = createProfileLocationFixtures();

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('selectedProvinceId', $locations['haNoi']->id)
        ->set('selectedHighSchoolId', $locations['phanDinhPhung']->id)
        ->assertSet(
            'selectedHighSchoolId',
            $locations['phanDinhPhung']->id
        )
        ->set('selectedProvinceId', $locations['caoBang']->id)
        ->assertSet('selectedHighSchoolId', null)
        ->assertSet('form.province_code', null)
        ->assertSet('form.high_school_code', null)
        ->assertSet('form.high_school_name', null);
});
test('candidate profile rejects a high school from another province', function () {
    $locations = createProfileLocationFixtures();

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('selectedProvinceId', $locations['haNoi']->id)
        ->set('selectedHighSchoolId', $locations['caoBangSchool']->id)
        ->call('save')
        ->assertHasErrors('selectedHighSchoolId');

    $this->assertDatabaseCount('candidate_profiles', 0);
});
test('existing profile restores selected province and high school', function () {
    $locations = createProfileLocationFixtures();

    $user = User::factory()->create();

    CandidateProfile::factory()
        ->for($user)
        ->create([
            'province_code' => '01',
            'high_school_code' => '0103',
            'high_school_name' => 'THPT Phan Đình Phùng',
        ]);

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->assertSet(
            'selectedProvinceId',
            $locations['haNoi']->id
        )
        ->assertSet(
            'selectedHighSchoolId',
            $locations['phanDinhPhung']->id
        );
});
test('visiting the profile does not create it and explicit saves create only one owned profile', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('candidate.profile.edit'))->assertOk();
    $this->assertDatabaseCount('candidate_profiles', 0);
    $page = Livewire::test(Profile::class)->call('save')->assertHasNoErrors();
    $profile = $user->candidateProfile()->sole();
    expect($profile->candidate_code)->toStartWith('C')->toHaveLength(27);
    expect($profile->profile_status)->toBe(ProfileStatus::Incomplete);
    $page->set('form.phone', '0901234567')->call('save')->assertHasNoErrors();
    $this->assertDatabaseCount('candidate_profiles', 1);
    expect($profile->fresh()->candidate_code)->toBe($profile->candidate_code);
});

test('a second open creation form cannot create a duplicate profile', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $page = Livewire::test(Profile::class);
    CandidateProfile::factory()->for($user)->create();
    $page->call('save')->assertForbidden();
    $this->assertDatabaseCount('candidate_profiles', 1);
});

test('profile creation refuses an injected owner or system identifier', function (string $field) {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user);
    Livewire::test(Profile::class)->set('form.'.$field, $other->id)->call('save')->assertHasErrors('form');
    $this->assertDatabaseCount('candidate_profiles', 0);
})->with(['user_id', 'candidate_code', 'profile_status']);

test('profile fields accept schema length boundaries without inventing vocabularies', function () {
    $this->travelTo(now()->setDate(2026, 9, 14));
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    Livewire::test(Profile::class)->set('form', [
        'date_of_birth' => '1900-01-01',
        'gender' => 'male',
        'citizen_id' => str_repeat('0', 20),
        'phone' => '+123456789012345',
        'address' => str_repeat('a', 500),
        'province_code' => str_repeat('0', 20),
        'high_school_code' => str_repeat('s', 30),
        'high_school_name' => str_repeat('n', 255),
        'graduation_year' => 2027,
        'priority_area' => 'KV1',
        'priority_object' => '01',
    ])->call('save')->assertHasNoErrors();
    expect($profile->fresh()->citizen_id)->toBe(str_repeat('0', 20));
    expect($profile->fresh()->graduation_year)->toBe(2027);
});

test('profile completion follows saved checklist and normalizes optional blanks', function () {
    Storage::fake(CandidateFiles::DISK);

    $this->freezeTime();
    $this->travelTo(now()->setDate(2026, 9, 14));

    $user = User::factory()->create();
    $locations = createProfileLocationFixtures();

    $this->actingAs($user);

    $page = Livewire::test(Profile::class)
        ->set('form', phaseFourProfileData())
        ->set('selectedProvinceId', $locations['haNoi']->id)
        ->set('selectedHighSchoolId', $locations['phanDinhPhung']->id)
        ->call('save')
        ->assertHasNoErrors();

    $profile = $user->candidateProfile()->sole();

    expect($profile->profile_status)
        ->toBe(ProfileStatus::Incomplete);

    $page->set(
        'photo',
        UploadedFile::fake()->createWithContent(
            'portrait.png',
            file_get_contents(base_path('tests/Fixtures/portrait.png'))
        )
    )
        ->set('citizenIdFront', UploadedFile::fake()->createWithContent('front.png', file_get_contents(base_path('tests/Fixtures/small.png'))))
        ->set('citizenIdBack', UploadedFile::fake()->createWithContent('back.png', file_get_contents(base_path('tests/Fixtures/small.png'))))
        ->call('save')
        ->assertHasNoErrors();

    $profile->refresh();

    expect($profile->profile_status)
        ->toBe(ProfileStatus::Complete);

    expect($profile->province_code)->toBe('01');
    expect($profile->high_school_code)->toBe('0103');
    expect($profile->high_school_name)->toBe('THPT Phan Đình Phùng');
    expect($profile->citizen_id)->toBe('001234567890');

    Storage::disk(CandidateFiles::DISK)
        ->assertExists($profile->photo_path);

    $page->set('form.phone', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($profile->fresh()->profile_status)
        ->toBe(ProfileStatus::Incomplete);
});

test('editing a verified profile preserves verification and candidate code', function () {
    $profile = CandidateProfile::factory()->create(['profile_status' => ProfileStatus::Verified]);
    $this->actingAs($profile->user);
    Livewire::test(Profile::class)->set('form.address', 'New address')->call('save')->assertHasNoErrors();
    expect($profile->fresh()->profile_status)->toBe(ProfileStatus::Verified);
    expect($profile->fresh()->candidate_code)->toBe($profile->candidate_code);
});

test('profile rejects system or unexpected fields without persisting', function (string $field) {
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    Livewire::test(Profile::class)->set('form.'.$field, 'tampered')->call('save')->assertHasErrors('form');
    expect($profile->fresh()->candidate_code)->toBe($profile->candidate_code);
    expect($profile->fresh()->profile_status)->toBe(ProfileStatus::Incomplete);
    expect($profile->user->fresh()->role)->toBe(UserRole::Candidate);
})->with(['user_id', 'candidate_profile_id', 'candidate_code', 'profile_status', 'photo_path', 'role', 'status', 'id', 'unexpected']);

test('profile validates field bounds and date sanity', function (string $field, mixed $value) {
    $this->travelTo(now()->setDate(2026, 9, 14));
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    Livewire::test(Profile::class)->set('form', phaseFourProfileData())->set('form.'.$field, $value)->call('save')->assertHasErrors('form.'.$field);
    expect($profile->fresh()->phone)->toBeNull();
})->with([
    ['date_of_birth', '2026-09-14'], ['date_of_birth', '1899-01-01'], ['date_of_birth', '2026-02-30'],
    ['gender', str_repeat('x', 21)], ['citizen_id', str_repeat('1', 21)], ['phone', 'abc12345'], ['phone', '1234567'], ['phone', '1234567890123456'],
    ['address', str_repeat('x', 501)], ['province_code', str_repeat('0', 21)], ['high_school_code', str_repeat('x', 31)],
    ['high_school_name', str_repeat('x', 256)], ['graduation_year', 2028], ['graduation_year', 1899], ['graduation_year', 2008], ['graduation_year', '2026.5'],
    ['priority_area', str_repeat('x', 21)], ['priority_object', str_repeat('x', 31)],
]);

test('citizen id uniqueness allows own value and multiple nulls', function () {
    $profile = CandidateProfile::factory()->create(['citizen_id' => '001234567890']);
    CandidateProfile::factory()->create(['citizen_id' => '009999999999']);
    CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $page = Livewire::test(Profile::class)->call('save')->assertHasNoErrors();
    $page->set('form.citizen_id', '009999999999')->call('save')->assertHasErrors('form.citizen_id');
    $page->set('form.citizen_id', ' ')->call('save')->assertHasNoErrors();
    expect($profile->fresh()->citizen_id)->toBeNull();
});

test('photo replacement removes the old private file', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $page = Livewire::test(Profile::class)->set('photo', UploadedFile::fake()->createWithContent('one.png', file_get_contents(base_path('tests/Fixtures/portrait.png'))))->call('save')->assertHasNoErrors();
    $oldPath = $profile->fresh()->photo_path;
    $page->set('photo', UploadedFile::fake()->createWithContent('two.jpg', file_get_contents(base_path('tests/Fixtures/replacement.jpg'))))->call('save')->assertHasNoErrors();
    Storage::disk(CandidateFiles::DISK)->assertMissing($oldPath);
    Storage::disk(CandidateFiles::DISK)->assertExists($profile->fresh()->photo_path);
    expect($profile->fresh()->photo_path)->not->toContain('two.jpg');
});

test('invalid photos do not modify the profile or permanent storage', function (string $kind) {
    Storage::fake(CandidateFiles::DISK);
    config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:12288']]);
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $file = match ($kind) {
        'ratio' => UploadedFile::fake()->createWithContent('photo.png', file_get_contents(base_path('tests/Fixtures/square.png'))),
        'small' => UploadedFile::fake()->createWithContent('photo.png', file_get_contents(base_path('tests/Fixtures/small.png'))),
        'large' => UploadedFile::fake()->createWithContent('photo.png', file_get_contents(base_path('tests/Fixtures/large.png'))),
        'size' => UploadedFile::fake()->createWithContent('photo.png', file_get_contents(base_path('tests/Fixtures/portrait.png')))->size(2049),
        'corrupt' => UploadedFile::fake()->createWithContent('photo.jpg', 'not an image'),
        'svg' => UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
    };
    Livewire::test(Profile::class)->set('photo', $file)->call('save')->assertHasErrors('photo');
    expect($profile->fresh()->photo_path)->toBeNull();
    expect(Storage::disk(CandidateFiles::DISK)->allFiles())->toBe([]);
})->with(['ratio', 'small', 'large', 'size', 'corrupt', 'svg']);

test('failed profile persistence removes the new photo and keeps the old photo', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create(['photo_path' => 'candidate-photos/old.png']);
    Storage::disk(CandidateFiles::DISK)->put($profile->photo_path, 'old');
    $this->actingAs($profile->user);
    Event::listen('eloquent.saving: '.CandidateProfile::class, fn () => false);
    try {
        Livewire::test(Profile::class)->set('photo', UploadedFile::fake()->createWithContent('new.png', file_get_contents(base_path('tests/Fixtures/portrait.png'))))->call('save')->assertHasErrors('form');
        expect($profile->fresh()->photo_path)->toBe('candidate-photos/old.png');
        expect(Storage::disk(CandidateFiles::DISK)->allFiles())->toBe(['candidate-photos/old.png']);
    } finally {
        Event::forget('eloquent.saving: '.CandidateProfile::class);
    }
});
test('candidate profile rejects nonexistent province and high school ids', function () {
    $locations = createProfileLocationFixtures();

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('selectedProvinceId', 999999)
        ->call('save')
        ->assertHasErrors('selectedProvinceId');

    Livewire::test(Profile::class)
        ->set('selectedProvinceId', $locations['haNoi']->id)
        ->set('selectedHighSchoolId', 999999)
        ->call('save')
        ->assertHasErrors('selectedHighSchoolId');
});

test('school options follow province ids rather than administrative codes and support accentless search', function () {
    $schools = [];
    foreach ([
        ['79', 'Thành phố Hồ Chí Minh', 'THPT Nguyễn Thượng Hiền'],
        ['48', 'Thành phố Đà Nẵng', 'THPT Phan Châu Trinh'],
        ['31', 'Thành phố Hải Phòng', 'THPT Thái Phiên'],
        ['01', 'Thành phố Hà Nội', 'THPT Phan Đình Phùng'],
    ] as [$code, $name, $schoolName]) {
        $province = Province::query()->create(['code' => $code, 'name' => $name]);
        $schools[] = HighSchool::query()->create(['province_id' => $province->id, 'code' => '001', 'name' => $schoolName]);
    }
    $this->actingAs(User::factory()->create());
    $page = Livewire::test(Profile::class);

    foreach ($schools as $school) {
        $page->set('selectedProvinceId', $school->province_id)
            ->assertViewHas('highSchools', fn ($options): bool => $options->modelKeys() === [$school->id])
            ->assertSee($school->name)
            ->assertSeeHtml('wire:key="high-school-select-'.$school->province_id.'"');
    }
    $page->set('schoolSearch', 'phan dinh phung')
        ->assertSee('THPT Phan Đình Phùng')
        ->set('schoolSearch', 'no matching school')
        ->assertSee('Không tìm thấy trường phù hợp.')
        ->assertViewHas('highSchools', fn ($options): bool => $options->isEmpty());
});

test('changing province clears search and persisted school while retaining the newly selected province', function () {
    $locations = createProfileLocationFixtures();
    $profile = CandidateProfile::factory()->create([
        'province_code' => '01', 'high_school_code' => '0103', 'high_school_name' => 'THPT Phan Đình Phùng',
    ]);
    $this->actingAs($profile->user);

    Livewire::test(Profile::class)
        ->assertSet('selectedProvinceId', $locations['haNoi']->id)
        ->assertSet('selectedHighSchoolId', $locations['phanDinhPhung']->id)
        ->set('schoolSearch', 'phan dinh')
        ->set('selectedProvinceId', $locations['caoBang']->id)
        ->assertSet('schoolSearch', '')
        ->assertSet('selectedHighSchoolId', null)
        ->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('candidate_profiles', [
        'id' => $profile->id, 'province_code' => '04', 'high_school_code' => null, 'high_school_name' => null,
    ]);
});

test('empty province shows guidance and does not show another provinces schools', function () {
    $locations = createProfileLocationFixtures();
    $empty = Province::query()->create(['code' => '48', 'name' => 'Thành phố Đà Nẵng']);
    $this->actingAs(User::factory()->create());

    Livewire::test(Profile::class)
        ->set('selectedProvinceId', $locations['haNoi']->id)
        ->set('selectedHighSchoolId', $locations['phanDinhPhung']->id)
        ->set('selectedProvinceId', $empty->id)
        ->assertSet('selectedHighSchoolId', null)
        ->assertViewHas('hasHighSchools', false)
        ->assertDontSee('THPT Phan Đình Phùng')
        ->assertSee('Tỉnh/thành này chưa có dữ liệu trường THPT.');
});

test('search keeps a selected school visible without changing its saved identity', function () {
    $locations = createProfileLocationFixtures();
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('selectedProvinceId', $locations['haNoi']->id)
        ->set('selectedHighSchoolId', $locations['phanDinhPhung']->id)
        ->set('schoolSearch', 'another school')
        ->assertViewHas('highSchools', fn ($options): bool => $options->modelKeys() === [$locations['phanDinhPhung']->id])
        ->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('candidate_profiles', ['user_id' => $user->id, 'province_code' => '01', 'high_school_code' => '0103']);
});

test('school search matches names and codes only within the selected province', function (string $search) {
    $locations = createProfileLocationFixtures();
    HighSchool::query()->create(['province_id' => $locations['haNoi']->id, 'code' => '1102', 'name' => 'THPT Thăng Long']);
    HighSchool::query()->create(['province_id' => $locations['caoBang']->id, 'code' => '0103', 'name' => 'THPT Phan Đình Phùng']);
    $this->actingAs(User::factory()->create());

    Livewire::test(Profile::class)
        ->set('selectedProvinceId', $locations['haNoi']->id)
        ->set('schoolSearch', $search)
        ->assertViewHas('highSchools', fn ($options): bool => $options->modelKeys() === [$locations['phanDinhPhung']->id])
        ->assertSee('THPT Phan Đình Phùng')
        ->assertDontSee('THPT Thăng Long');
})->with(['tên có dấu' => 'Phan Đình Phùng', 'tên không dấu' => 'phan dinh phung', 'chữ hoa và khoảng trắng' => '  PHAN   DINH PHUNG  ', 'mã trường' => '0103']);
