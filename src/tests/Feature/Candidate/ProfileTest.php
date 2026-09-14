<?php

use App\Actions\CandidateFiles;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Candidate\Profile;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function phaseFourProfileData(): array
{
    return ['date_of_birth' => '2008-01-02', 'gender' => 'Nữ', 'citizen_id' => '001234567890',
        'phone' => '+84 901 234 567', 'address' => 'Hà Nội', 'province_code' => '01',
        'high_school_code' => null, 'high_school_name' => 'THPT Demo', 'graduation_year' => 2026,
        'priority_area' => null, 'priority_object' => null];
}

test('only active candidates without profiles may create profiles', function (UserRole $role, UserStatus $status) {
    $user = User::factory()->create(['role' => $role, 'status' => $status]);
    expect(Gate::forUser($user)->allows('create', CandidateProfile::class))->toBe($role === UserRole::Candidate && $status === UserStatus::Active);
    CandidateProfile::factory()->for($user)->create();
    expect(Gate::forUser($user)->allows('create', CandidateProfile::class))->toBeFalse();
})->with(UserRole::cases())->with(UserStatus::cases());

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
        'date_of_birth' => '1900-01-01', 'gender' => str_repeat('g', 20), 'citizen_id' => str_repeat('0', 20),
        'phone' => '+123456789012345', 'address' => str_repeat('a', 500), 'province_code' => str_repeat('0', 20),
        'high_school_code' => str_repeat('s', 30), 'high_school_name' => str_repeat('n', 255), 'graduation_year' => 2027,
        'priority_area' => str_repeat('a', 20), 'priority_object' => str_repeat('o', 30),
    ])->call('save')->assertHasNoErrors();
    expect($profile->fresh()->citizen_id)->toBe(str_repeat('0', 20));
    expect($profile->fresh()->graduation_year)->toBe(2027);
});

test('profile completion follows saved checklist and normalizes optional blanks', function () {
    Storage::fake(CandidateFiles::DISK);
    $this->freezeTime();
    $this->travelTo(now()->setDate(2026, 9, 14));
    $user = User::factory()->create();
    $this->actingAs($user);
    $page = Livewire::test(Profile::class)->set('form', phaseFourProfileData())
        ->set('form.high_school_code', '   ')->call('save')->assertHasNoErrors();
    $profile = $user->candidateProfile()->sole();
    expect($profile->profile_status)->toBe(ProfileStatus::Incomplete);
    $page->set('photo', UploadedFile::fake()->createWithContent('portrait.png', file_get_contents(base_path('tests/Fixtures/portrait.png'))))->call('save')->assertHasNoErrors();
    $profile->refresh();
    expect($profile->profile_status)->toBe(ProfileStatus::Complete);
    expect($profile->high_school_code)->toBeNull();
    expect($profile->citizen_id)->toBe('001234567890');
    Storage::disk(CandidateFiles::DISK)->assertExists($profile->photo_path);
    $page->set('form.phone', '')->call('save')->assertHasNoErrors();
    expect($profile->fresh()->profile_status)->toBe(ProfileStatus::Incomplete);
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
