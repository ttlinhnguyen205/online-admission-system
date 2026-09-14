<section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Hồ sơ thí sinh') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Complete your admission profile. You can save your progress at any time.') }}</flux:text>
    </div>
    <div class="flex flex-wrap items-center gap-3">
        <flux:badge>{{ $profile?->candidate_code ?? __('Not created yet') }}</flux:badge>
        <flux:badge :color="$profile?->profile_status === App\Enums\ProfileStatus::Verified ? 'green' : 'zinc'">{{ $profile ? __(ucfirst($profile->profile_status->value)) : __('Incomplete') }}</flux:badge>
        <flux:text>{{ auth()->user()->name }} · {{ auth()->user()->email }}</flux:text>
        <flux:button size="sm" :href="route('profile.edit')" wire:navigate>{{ __('Account settings') }}</flux:button>
    </div>
    <flux:error name="cleanup" />
    <form wire:submit="save" class="grid gap-6 lg:grid-cols-3" x-data="{ uploading: false, progress: 0 }"
        x-on:livewire-upload-start="uploading = true; progress = 0"
        x-on:livewire-upload-finish="uploading = false"
        x-on:livewire-upload-cancel="uploading = false"
        x-on:livewire-upload-error="uploading = false"
        x-on:livewire-upload-progress="progress = $event.detail.progress">
        <div class="flex flex-col gap-5 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 lg:col-span-2">
            <flux:error name="form" />
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="form.date_of_birth" type="date" :label="__('Ngày sinh / Date of birth')" />
                <flux:input wire:model="form.gender" :label="__('Giới tính / Gender')" maxlength="20" />
                <flux:input wire:model="form.citizen_id" :label="__('CCCD / Citizen ID')" maxlength="20" />
                <flux:input wire:model="form.phone" type="tel" :label="__('Điện thoại / Phone')" maxlength="20" />
                <div class="sm:col-span-2"><flux:textarea wire:model="form.address" :label="__('Địa chỉ / Address')" maxlength="500" rows="3" /></div>
                <flux:input wire:model="form.province_code" :label="__('Mã tỉnh / Province code')" maxlength="20" />
                <flux:input wire:model="form.high_school_code" :label="__('Mã trường THPT (optional)')" maxlength="30" />
                <flux:input wire:model="form.high_school_name" :label="__('Tên trường THPT / School name')" maxlength="255" />
                <flux:input wire:model="form.graduation_year" type="number" min="1900" :max="now()->year + 1" :label="__('Năm tốt nghiệp / Graduation year')" />
                <flux:input wire:model="form.priority_area" :label="__('Khu vực ưu tiên (optional)')" maxlength="20" />
                <flux:input wire:model="form.priority_object" :label="__('Đối tượng ưu tiên (optional)')" maxlength="30" />
            </div>
            <flux:button variant="primary" type="submit" x-bind:disabled="uploading" wire:loading.attr="disabled" wire:target="save,photo">{{ __('Lưu hồ sơ / Save profile') }}</flux:button>
            <span role="status" wire:loading wire:target="save">{{ __('Saving profile...') }}</span>
        </div>
        <div class="flex flex-col gap-5">
            <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>{{ __('Ảnh 3×4 / Profile photo') }}</flux:heading>
                @if ($profile?->photo_path)
                    <img src="{{ route('admission.profiles.photo', ['profile' => $profile->id, 'v' => hash('sha256', $profile->photo_path)]) }}" alt="{{ __('Profile photo') }}" class="aspect-[3/4] w-36 rounded-lg object-cover" />
                @endif
                <flux:input wire:model="photo" type="file" accept="image/jpeg,image/png" :label="__('Choose photo')" />
                <flux:text>{{ __('JPEG/PNG, up to 2 MiB. Ratio 3:4; 300×400 to 3000×4000 pixels. The photo is saved with your profile.') }}</flux:text>
                <div x-show="uploading" x-cloak role="status"><span>{{ __('Uploading:') }}</span> <span x-text="progress + '%' "></span><progress x-bind:value="progress" max="100" class="w-full"></progress></div>
            </div>
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>{{ __('Saved profile checklist') }}</flux:heading>
                <flux:text class="my-3">{{ __('Verification status is separate from this completion checklist.') }}</flux:text>
                @php($labels = ['date_of_birth' => 'Date of birth', 'gender' => 'Gender', 'citizen_id' => 'Citizen ID', 'phone' => 'Phone', 'address' => 'Address', 'province_code' => 'Province code', 'high_school_name' => 'School name', 'graduation_year' => 'Graduation year', 'photo_path' => 'Photo'])
                <ul class="space-y-2 text-sm">
                    @foreach ($completionFields as $field)
                        <li wire:key="check-{{ $field }}">{{ filled($profile?->getAttribute($field)) ? '✓' : '○' }} {{ __($labels[$field]) }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </form>
</section>
