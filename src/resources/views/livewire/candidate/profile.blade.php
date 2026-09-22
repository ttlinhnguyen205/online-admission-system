<section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Hồ sơ thí sinh') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Hoàn thiện hồ sơ tuyển sinh. Bạn có thể lưu tiến độ bất cứ lúc nào.') }}</flux:text>
    </div>
    <div class="flex flex-wrap items-center gap-3">
        <flux:badge>{{ $profile?->candidate_code ?? __('Chưa tạo') }}</flux:badge>
        <flux:badge :color="$profile?->profile_status === App\Enums\ProfileStatus::Verified ? 'green' : 'zinc'">{{ $profile ? ($profile->profile_status === App\Enums\ProfileStatus::Verified ? 'Đã xác minh' : 'Chưa hoàn thiện') : __('Chưa hoàn thiện') }}</flux:badge>
        <flux:text>{{ auth()->user()->name }} · {{ auth()->user()->email }}</flux:text>
        <flux:button size="sm" :href="route('profile.edit')" wire:navigate>{{ __('Cài đặt tài khoản') }}</flux:button>
    </div>
    <flux:error name="cleanup" />
    <form wire:submit="save" class="grid gap-6 lg:grid-cols-3" x-data="{ uploading: false, progress: 0, photoName: '' }"
        x-on:livewire-upload-start="uploading = true; progress = 0"
        x-on:livewire-upload-finish="uploading = false"
        x-on:livewire-upload-cancel="uploading = false"
        x-on:livewire-upload-error="uploading = false"
        x-on:livewire-upload-progress="progress = $event.detail.progress">
        <div class="flex flex-col gap-5 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 lg:col-span-2">
            <flux:error name="form" />
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="form.date_of_birth" type="date" :label="__('Ngày sinh')" />
                <flux:input wire:model="form.gender" :label="__('Giới tính')" maxlength="20" />
                <flux:input wire:model="form.citizen_id" :label="__('CCCD')" maxlength="20" />
                <flux:input wire:model="form.phone" type="tel" :label="__('Điện thoại')" maxlength="20" />
                <div class="sm:col-span-2"><flux:textarea wire:model="form.address" :label="__('Địa chỉ')" maxlength="500" rows="3" /></div>
                <flux:input wire:model="form.province_code" :label="__('Mã tỉnh')" maxlength="20" />
                <flux:input wire:model="form.high_school_code" :label="__('Mã trường THPT (không bắt buộc)')" maxlength="30" />
                <flux:input wire:model="form.high_school_name" :label="__('Tên trường THPT')" maxlength="255" />
                <flux:input wire:model="form.graduation_year" type="number" min="1900" :max="now()->year + 1" :label="__('Năm tốt nghiệp')" />
                <flux:input wire:model="form.priority_area" :label="__('Khu vực ưu tiên (không bắt buộc)')" maxlength="20" />
                <flux:input wire:model="form.priority_object" :label="__('Đối tượng ưu tiên (không bắt buộc)')" maxlength="30" />
            </div>
            <flux:button variant="primary" type="submit" x-bind:disabled="uploading" wire:loading.attr="disabled" wire:target="save,photo">{{ __('Lưu hồ sơ') }}</flux:button>
            <span role="status" wire:loading wire:target="save">{{ __('Đang lưu hồ sơ...') }}</span>
        </div>
        <div class="flex flex-col gap-5">
            <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>{{ __('Ảnh 3×4') }}</flux:heading>
                @if ($profile?->photo_path)
                    <img src="{{ route('admission.profiles.photo', ['profile' => $profile->id, 'v' => hash('sha256', $profile->photo_path)]) }}" alt="{{ __('Ảnh hồ sơ') }}" class="aspect-[3/4] w-36 rounded-lg object-cover" />
                @endif
                <div>
                    <input id="profile-photo" wire:model="photo" type="file" accept="image/jpeg,image/png" class="sr-only" x-on:change="photoName = $event.target.files[0]?.name ?? ''" />
                    <label for="profile-photo" class="inline-flex h-10 cursor-pointer items-center rounded-lg border border-zinc-200 bg-white px-4 text-sm font-medium text-zinc-800 shadow-xs hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-white dark:hover:bg-zinc-600/75">{{ __('Chọn ảnh') }}</label>
                    <span class="ms-3 text-sm text-zinc-500 dark:text-zinc-300" x-text="photoName || 'Chưa chọn tệp nào'"></span>
                </div>
                <flux:text>{{ __('Chấp nhận JPEG/PNG, tối đa 2 MiB. Tỷ lệ 3:4; kích thước từ 300×400 đến 3000×4000 pixel. Ảnh được lưu cùng hồ sơ.') }}</flux:text>
                <div x-show="uploading" x-cloak role="status"><span>{{ __('Đang tải lên:') }}</span> <span x-text="progress + '%' "></span><progress x-bind:value="progress" max="100" class="w-full"></progress></div>
            </div>
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>{{ __('Danh sách kiểm tra hồ sơ') }}</flux:heading>
                <flux:text class="my-3">{{ __('Trạng thái xác minh độc lập với danh sách hoàn thiện này.') }}</flux:text>
                @php($labels = ['date_of_birth' => 'Ngày sinh', 'gender' => 'Giới tính', 'citizen_id' => 'CCCD', 'phone' => 'Điện thoại', 'address' => 'Địa chỉ', 'province_code' => 'Mã tỉnh', 'high_school_name' => 'Tên trường THPT', 'graduation_year' => 'Năm tốt nghiệp', 'photo_path' => 'Ảnh'])
                <ul class="space-y-2 text-sm">
                    @foreach ($completionFields as $field)
                        <li wire:key="check-{{ $field }}">{{ filled($profile?->getAttribute($field)) ? '✓' : '○' }} {{ __($labels[$field]) }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </form>
</section>
