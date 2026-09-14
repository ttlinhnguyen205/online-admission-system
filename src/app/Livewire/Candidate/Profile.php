<?php

namespace App\Livewire\Candidate;

use App\Actions\CandidateFiles;
use App\Enums\ProfileStatus;
use App\Models\CandidateProfile;
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

#[Title('Hồ sơ thí sinh')]
class Profile extends CandidatePage
{
    use WithFileUploads;

    public const FIELDS = ['date_of_birth', 'gender', 'citizen_id', 'phone', 'address', 'province_code',
        'high_school_code', 'high_school_name', 'graduation_year', 'priority_area', 'priority_object'];

    public const COMPLETION = ['date_of_birth', 'gender', 'citizen_id', 'phone', 'address', 'province_code',
        'high_school_name', 'graduation_year', 'photo_path'];

    /** @var array<string, mixed> */
    public array $form = [];

    public mixed $photo = null;

    #[Locked]
    public ?int $profileId = null;

    public function mount(): void
    {
        $profile = $this->candidate()->candidateProfile()->first();
        if ($profile !== null) {
            Gate::authorize('view', $profile);
        }
        $this->profileId = $profile?->getKey();
        $this->form = $profile?->only(self::FIELDS) ?? array_fill_keys(self::FIELDS, null);
        $this->form['date_of_birth'] = $profile?->getAttribute('date_of_birth')?->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    protected function profileRules(?CandidateProfile $profile): array
    {
        return [
            'form' => ['required', 'array:'.implode(',', self::FIELDS)],
            'form.date_of_birth' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before:today'],
            'form.gender' => ['nullable', 'string', 'max:20'],
            'form.citizen_id' => ['nullable', 'string', 'max:20', Rule::unique(CandidateProfile::class, 'citizen_id')->ignore($profile)],
            'form.phone' => ['nullable', 'string', 'max:20', 'regex:/\A\+?[0-9 ()\-]+\z/D', function (string $attribute, mixed $value, \Closure $fail): void {
                $digits = preg_replace('/\D/', '', (string) $value);
                if (strlen($digits) < 8 || strlen($digits) > 15) {
                    $fail(__('The phone number must contain 8 to 15 digits.'));
                }
            }],
            'form.address' => ['nullable', 'string', 'max:500'],
            'form.province_code' => ['nullable', 'string', 'max:20'],
            'form.high_school_code' => ['nullable', 'string', 'max:30'],
            'form.high_school_name' => ['nullable', 'string', 'max:255'],
            'form.graduation_year' => ['nullable', 'integer', 'between:1900,'.(now()->year + 1)],
            'form.priority_area' => ['nullable', 'string', 'max:20'],
            'form.priority_object' => ['nullable', 'string', 'max:30'],
            'photo' => CandidateFiles::photoRules(),
        ];
    }

    public function save(CandidateFiles $files): void
    {
        $user = $this->candidate();
        $newPath = null;
        $oldPath = null;
        try {
            $profile = $user->getConnection()->transaction(function () use ($user, $files, &$newPath, &$oldPath): CandidateProfile {
                $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $user->refresh();
                $profile = $this->profileId === null ? null : $user->candidateProfile()->whereKey($this->profileId)->lockForUpdate()->firstOrFail();
                Gate::authorize($profile === null ? 'create' : 'update', $profile ?? CandidateProfile::class);
                $this->form = $this->normalize($this->form);
                $validated = $this->validate($this->profileRules($profile));
                $attributes = array_intersect_key($validated['form'], array_flip(self::FIELDS));
                if (($attributes['date_of_birth'] ?? null) !== null && ($attributes['graduation_year'] ?? null) !== null
                    && (int) $attributes['graduation_year'] <= (int) substr($attributes['date_of_birth'], 0, 4)) {
                    throw ValidationException::withMessages(['form.graduation_year' => __('Graduation year must be after the birth year.')]);
                }
                if ($profile === null) {
                    $profile = $user->candidateProfile()->make(['candidate_code' => 'C'.Str::ulid()]);
                }
                $profile->fill($attributes);
                if ($this->photo instanceof UploadedFile) {
                    $oldPath = $profile->getAttribute('photo_path');
                    $newPath = $files->store($this->photo, 'candidate-photos/'.$user->getKey(), 'photo');
                    $profile->setAttribute('photo_path', $newPath);
                }
                if ($profile->getAttribute('profile_status') !== ProfileStatus::Verified) {
                    $complete = collect(self::COMPLETION)->every(fn (string $field): bool => filled($profile->getAttribute($field)));
                    $profile->setAttribute('profile_status', $complete ? ProfileStatus::Complete : ProfileStatus::Incomplete);
                }
                if (! $profile->save()) {
                    throw ValidationException::withMessages(['form' => __('The profile could not be saved.')]);
                }

                return $profile;
            });
        } catch (Throwable $exception) {
            $files->remove($newPath);
            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['form.citizen_id' => __('The citizen ID or profile identifier is already in use. Reload the page and try again.')]);
            }
            throw $exception;
        }
        $this->profileId = $profile->getKey();
        $this->photo = null;
        if (! $files->remove($oldPath)) {
            $this->addError('cleanup', __('Saved, but the previous file could not be removed. Please contact support.'));
        }
        Flux::toast(variant: 'success', text: __('Profile saved.'));
    }

    public function render(): View
    {
        $profile = $this->candidate()->candidateProfile()->first();
        if ($profile !== null) {
            Gate::authorize('view', $profile);
        }

        return view('livewire.candidate.profile', ['profile' => $profile, 'completionFields' => self::COMPLETION]);
    }
}
