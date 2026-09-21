<?php

namespace App\Livewire\Candidate;

use App\Actions\CandidateFiles;
use App\Enums\ProfileStatus;
use App\Models\CandidateProfile;
use App\Models\HighSchool;
use App\Models\Province;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\WithFileUploads;
use Throwable;

#[Title('Hồ sơ cá nhân')]
class Profile extends CandidatePage
{
    use WithFileUploads;

    public const FIELDS = [
        'date_of_birth',
        'gender',
        'ethnicity',
        'religion',
        'citizen_id',
        'citizen_id_issued_date',
        'citizen_id_issued_place',
        'phone',
        'address',
        'province_code',
        'high_school_code',
        'high_school_name',
        'graduation_year',
        'priority_area',
        'priority_object',
    ];

    public const COMPLETION = [
        'date_of_birth',
        'gender',
        'ethnicity',
        'citizen_id',
        'citizen_id_issued_date',
        'citizen_id_issued_place',
        'phone',
        'address',
        'province_code',
        'high_school_name',
        'graduation_year',
        'photo_path',
        'citizen_id_front_path',
        'citizen_id_back_path',
    ];

    /** @var array<string, mixed> */
    public array $form = [];

    public mixed $photo = null;

    public mixed $citizenIdFront = null;

    public mixed $citizenIdBack = null;

    #[Locked]
    public ?int $profileId = null;

    public ?int $selectedProvinceId = null;

    public ?int $selectedHighSchoolId = null;

    public function mount(): void
    {
        $profile = $this->candidate()->candidateProfile()->first();

        if ($profile !== null) {
            Gate::authorize('view', $profile);
        }

        $this->profileId = $profile?->getKey();

        $this->form = $profile?->only(self::FIELDS)
            ?? array_fill_keys(self::FIELDS, null);

        $this->form['date_of_birth'] = $profile
            ?->getAttribute('date_of_birth')
            ?->format('Y-m-d');

        $this->form['citizen_id_issued_date'] = $profile
            ?->getAttribute('citizen_id_issued_date')
            ?->format('Y-m-d');
        if ($profile !== null) {
            $provinceCode = $profile->getAttribute('province_code');

            if (filled($provinceCode)) {
                $province = Province::where('code', $provinceCode)->first();

                $this->selectedProvinceId = $province?->getKey();

                if ($province !== null) {
                    $schoolCode = $profile->getAttribute('high_school_code');

                    if (filled($schoolCode)) {
                        $school = HighSchool::where(
                            'province_id',
                            $province->getKey()
                        )
                            ->where('code', $schoolCode)
                            ->first();

                        $this->selectedHighSchoolId = $school?->getKey();
                    }
                }
            }
        }
    }

    public function updatedSelectedProvinceId(): void
    {
        $this->selectedHighSchoolId = null;

        $this->form['province_code'] = null;
        $this->form['high_school_code'] = null;
        $this->form['high_school_name'] = null;
    }

    /** @return array<string, mixed> */
    protected function profileRules(?CandidateProfile $profile): array
    {
        return [
            'form' => [
                'required',
                'array:'.implode(',', self::FIELDS),
            ],

            'selectedProvinceId' => [
                'nullable',
                'integer',
                Rule::exists(Province::class, 'id'),
            ],

            'selectedHighSchoolId' => [
                'nullable',
                'integer',
                Rule::exists(HighSchool::class, 'id'),
            ],

            'form.date_of_birth' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:1900-01-01',
                'before:today',
            ],

            'form.gender' => [
                'nullable',
                Rule::in(['male', 'female']),
            ],

            'form.ethnicity' => [
                'nullable',
                'string',
                'max:100',
            ],

            'form.religion' => [
                'nullable',
                'string',
                'max:100',
            ],

            'form.citizen_id' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique(CandidateProfile::class, 'citizen_id')
                    ->ignore($profile),
            ],

            'form.citizen_id_issued_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:1900-01-01',
                'before_or_equal:today',
            ],

            'form.citizen_id_issued_place' => [
                'nullable',
                'string',
                'max:255',
            ],

            'form.phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/\A\+?[0-9 ()\-]+\z/D',
                function (
                    string $attribute,
                    mixed $value,
                    \Closure $fail
                ): void {
                    $digits = preg_replace('/\D/', '', (string) $value);

                    if (strlen($digits) < 8 || strlen($digits) > 15) {
                        $fail(
                            __('Số điện thoại phải chứa từ 8 đến 15 chữ số.')
                        );
                    }
                },
            ],

            'form.address' => [
                'nullable',
                'string',
                'max:500',
            ],

            'form.province_code' => [
                'nullable',
                'string',
                'max:20',
            ],

            'form.high_school_code' => [
                'nullable',
                'string',
                'max:30',
            ],

            'form.high_school_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'form.graduation_year' => [
                'nullable',
                'integer',
                'between:1900,'.(now()->year + 1),
            ],

            'form.priority_area' => [
                'nullable',
                Rule::in([
                    'KV1',
                    'KV2-NT',
                    'KV2',
                    'KV3',
                ]),
            ],
            'form.priority_object' => [
                'nullable',
                Rule::in([
                    '01',
                    '02',
                    '03',
                    '04',
                    '05',
                    '06',
                ]),
            ],

            'photo' => CandidateFiles::photoRules(),
            'citizenIdFront' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png',
                'max:5120',
            ],

            'citizenIdBack' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png',
                'max:5120',
            ],
        ];
    }

    public function save(CandidateFiles $files): void
    {
        $user = $this->candidate();

        $newPath = null;
        $oldPath = null;

        $newFrontPath = null;
        $oldFrontPath = null;

        $newBackPath = null;
        $oldBackPath = null;

        try {
            $profile = $user->getConnection()->transaction(
                function () use (
                    $user,
                    $files,
                    &$newPath,
                    &$oldPath,
                    &$newFrontPath,
                    &$oldFrontPath,
                    &$newBackPath,
                    &$oldBackPath
                ): CandidateProfile {
                    $user->newQuery()
                        ->whereKey($user->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    $user->refresh();

                    $profile = $this->profileId === null
                        ? null
                        : $user->candidateProfile()
                            ->whereKey($this->profileId)
                            ->lockForUpdate()
                            ->firstOrFail();

                    Gate::authorize(
                        $profile === null ? 'create' : 'update',
                        $profile ?? CandidateProfile::class
                    );

                    $this->form = $this->normalize($this->form);

                    $validated = $this->validate(
                        $this->profileRules($profile)
                    );

                    $attributes = array_intersect_key(
                        $validated['form'],
                        array_flip(self::FIELDS)
                    );
                    $province = null;
                    $school = null;

                    if ($this->selectedProvinceId !== null) {
                        $province = Province::query()
                            ->whereKey($this->selectedProvinceId)
                            ->firstOrFail();

                        $attributes['province_code'] = $province->code;
                    } else {
                        $attributes['province_code'] = null;
                    }

                    if ($this->selectedHighSchoolId !== null) {
                        if ($province === null) {
                            throw ValidationException::withMessages([
                                'selectedProvinceId' => __('Vui lòng chọn tỉnh/thành phố trước.'),
                            ]);
                        }

                        $school = HighSchool::query()
                            ->whereKey($this->selectedHighSchoolId)
                            ->where('province_id', $province->getKey())
                            ->first();

                        if ($school === null) {
                            throw ValidationException::withMessages([
                                'selectedHighSchoolId' => __('Trường THPT không thuộc tỉnh/thành phố đã chọn.'),
                            ]);
                        }

                        $attributes['high_school_code'] = $school->code;
                        $attributes['high_school_name'] = $school->name;
                    } else {
                        $attributes['high_school_code'] = null;
                        $attributes['high_school_name'] = null;
                    }

                    if (
                        ($attributes['date_of_birth'] ?? null) !== null
                        && ($attributes['graduation_year'] ?? null) !== null
                        && (int) $attributes['graduation_year']
                            <= (int) substr(
                                $attributes['date_of_birth'],
                                0,
                                4
                            )
                    ) {
                        throw ValidationException::withMessages([
                            'form.graduation_year' => __('Năm tốt nghiệp phải sau năm sinh.'),
                        ]);
                    }

                    if ($profile === null) {
                        $profile = $user->candidateProfile()->make([
                            'candidate_code' => 'C'.Str::ulid(),
                        ]);
                    }

                    $profile->fill($attributes);

                    if ($this->photo instanceof UploadedFile) {
                        $oldPath = $profile->getAttribute('photo_path');

                        $newPath = $files->store(
                            $this->photo,
                            'candidate-photos/'.$user->getKey(),
                            'photo'
                        );

                        $profile->setAttribute('photo_path', $newPath);
                    }
                    if ($this->citizenIdFront instanceof UploadedFile) {
                        $oldFrontPath = $profile->getAttribute('citizen_id_front_path');

                        $newFrontPath = $files->store(
                            $this->citizenIdFront,
                            'candidate-citizen-ids/'.$user->getKey(),
                            'front'
                        );

                        $profile->setAttribute(
                            'citizen_id_front_path',
                            $newFrontPath
                        );
                    }

                    if ($this->citizenIdBack instanceof UploadedFile) {
                        $oldBackPath = $profile->getAttribute('citizen_id_back_path');

                        $newBackPath = $files->store(
                            $this->citizenIdBack,
                            'candidate-citizen-ids/'.$user->getKey(),
                            'back'
                        );

                        $profile->setAttribute(
                            'citizen_id_back_path',
                            $newBackPath
                        );
                    }

                    if (
                        $profile->getAttribute('profile_status')
                        !== ProfileStatus::Verified
                    ) {
                        $complete = collect(self::COMPLETION)->every(
                            fn (string $field): bool => filled($profile->getAttribute($field))
                        );

                        $profile->setAttribute(
                            'profile_status',
                            $complete
                                ? ProfileStatus::Complete
                                : ProfileStatus::Incomplete
                        );
                    }

                    if (! $profile->save()) {
                        throw ValidationException::withMessages([
                            'form' => __('Không thể lưu hồ sơ.'),
                        ]);
                    }

                    return $profile;
                }
            );
        } catch (Throwable $exception) {
            $files->remove($newPath);
            $files->remove($newFrontPath);
            $files->remove($newBackPath);

            if (
                $exception
                instanceof UniqueConstraintViolationException
            ) {
                throw ValidationException::withMessages([
                    'form.citizen_id' => __('Số CCCD này đã được sử dụng.'),
                ]);
            }

            throw $exception;
        }

        $this->profileId = $profile->getKey();
        $this->photo = null;
        $this->citizenIdFront = null;
        $this->citizenIdBack = null;

        if (! $files->remove($oldPath)) {
            $this->addError(
                'cleanup',
                __('Đã lưu hồ sơ nhưng không thể xóa ảnh cũ.')
            );
        }
        if (! $files->remove($oldFrontPath)) {
            $this->addError(
                'cleanup',
                __('Đã lưu hồ sơ nhưng không thể xóa ảnh CCCD mặt trước cũ.')
            );
        }

        if (! $files->remove($oldBackPath)) {
            $this->addError(
                'cleanup',
                __('Đã lưu hồ sơ nhưng không thể xóa ảnh CCCD mặt sau cũ.')
            );
        }

        Flux::toast(
            variant: 'success',
            text: __('Đã lưu hồ sơ.')
        );
    }

    public function render(): View
    {
        $profile = $this->candidate()
            ->candidateProfile()
            ->first();

        if ($profile !== null) {
            Gate::authorize('view', $profile);
        }

        $provinces = Province::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $highSchools = $this->selectedProvinceId === null
            ? collect()
            : HighSchool::query()
                ->where('province_id', $this->selectedProvinceId)
                ->orderBy('name')
                ->get(['id', 'code', 'name']);

        return view('livewire.candidate.profile', [
            'profile' => $profile,
            'completionFields' => self::COMPLETION,
            'provinces' => $provinces,
            'highSchools' => $highSchools,
        ]);
    }
}
