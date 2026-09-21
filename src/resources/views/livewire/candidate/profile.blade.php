<section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">
            {{ __('Hồ sơ cá nhân') }}
        </flux:heading>

        <flux:text class="mt-2">
            {{ __('Hoàn thiện hồ sơ cá nhân để đăng ký xét tuyển. Bạn có thể lưu thông tin bất cứ lúc nào.') }}
        </flux:text>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <flux:badge>
            {{ $profile?->candidate_code ?? __('Chưa tạo hồ sơ') }}
        </flux:badge>

        <flux:badge
            :color="$profile?->profile_status === App\Enums\ProfileStatus::Verified ? 'green' : 'zinc'"
        >
            {{ $profile ? App\Support\CandidateStatusLabels::profile($profile->profile_status) : __('Chưa hoàn thiện') }}
        </flux:badge>

        <flux:text>
            {{ auth()->user()->name }} · {{ auth()->user()->email }}
        </flux:text>

        <flux:button
            size="sm"
            :href="route('profile.edit')"
            wire:navigate
        >
            {{ __('Cài đặt tài khoản') }}
        </flux:button>
    </div>

    <flux:error name="cleanup" />

    <form
        wire:submit="save"
        class="grid gap-6 lg:grid-cols-3"
        x-data="{ uploading: false, progress: 0 }"
        x-on:livewire-upload-start="uploading = true; progress = 0"
        x-on:livewire-upload-finish="uploading = false"
        x-on:livewire-upload-cancel="uploading = false"
        x-on:livewire-upload-error="uploading = false"
        x-on:livewire-upload-progress="progress = $event.detail.progress"
    >
        <div class="flex flex-col gap-5 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 lg:col-span-2">

            <flux:error name="form" />

            <div class="grid gap-5 sm:grid-cols-2">

                {{-- Ngày sinh --}}
                <flux:input
                    wire:model="form.date_of_birth"
                    type="date"
                    :label="__('Ngày sinh')"
                />

                {{-- Giới tính --}}
                <flux:select
                    wire:model="form.gender"
                    :label="__('Giới tính')"
                >
                    <flux:select.option value="">
                        {{ __('Chọn giới tính') }}
                    </flux:select.option>

                    <flux:select.option value="male">
                        {{ __('Nam') }}
                    </flux:select.option>

                    <flux:select.option value="female">
                        {{ __('Nữ') }}
                    </flux:select.option>
                </flux:select>

                {{-- Dân tộc --}}
                <flux:input
                    wire:model="form.ethnicity"
                    :label="__('Dân tộc')"
                    maxlength="100"
                    placeholder="Ví dụ: Kinh"
                />

                {{-- Tôn giáo --}}
                <flux:input
                    wire:model="form.religion"
                    :label="__('Tôn giáo (không bắt buộc)')"
                    maxlength="100"
                    placeholder="Ví dụ: Không"
                />

                {{-- CCCD --}}
                <flux:input
                    wire:model="form.citizen_id"
                    :label="__('Số CCCD')"
                    maxlength="20"
                />

                {{-- Ngày cấp CCCD --}}
                <flux:input
                    wire:model="form.citizen_id_issued_date"
                    type="date"
                    :label="__('Ngày cấp CCCD')"
                />

                {{-- Nơi cấp CCCD --}}
                <div class="sm:col-span-2">
                    <flux:input
                        wire:model="form.citizen_id_issued_place"
                        :label="__('Nơi cấp CCCD')"
                        maxlength="255"
                    />
                </div>

                {{-- Điện thoại --}}
                <flux:input
                    wire:model="form.phone"
                    type="tel"
                    :label="__('Số điện thoại')"
                    maxlength="20"
                />

                {{-- Địa chỉ --}}
                <div class="sm:col-span-2">
                    <flux:textarea
                        wire:model="form.address"
                        :label="__('Địa chỉ')"
                        maxlength="500"
                        rows="3"
                    />
                </div>

                {{-- Tỉnh / Thành phố --}}
                <flux:select
                    wire:model.live="selectedProvinceId"
                    :label="__('Tỉnh / Thành phố')"
                >
                    <flux:select.option value="">
                        {{ __('Chọn tỉnh / thành phố') }}
                    </flux:select.option>

                    @foreach ($provinces as $province)
                        <flux:select.option
                            :value="$province->id"
                            wire:key="province-{{ $province->id }}"
                        >
                            {{ $province->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                {{-- Trường THPT --}}
                <flux:select
                    wire:model="selectedHighSchoolId"
                    :label="__('Trường THPT')"
                    :disabled="$selectedProvinceId === null"
                >
                    <flux:select.option value="">
                        {{ $selectedProvinceId === null
                            ? __('Vui lòng chọn tỉnh / thành phố trước')
                            : __('Chọn trường THPT') }}
                    </flux:select.option>

                    @foreach ($highSchools as $school)
                        <flux:select.option
                            :value="$school->id"
                            wire:key="high-school-{{ $school->id }}"
                        >
                            {{ $school->code }} - {{ $school->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                {{-- Năm tốt nghiệp --}}
                <flux:input
                    wire:model="form.graduation_year"
                    type="number"
                    min="1900"
                    :max="now()->year + 1"
                    :label="__('Năm tốt nghiệp')"
                />

                {{-- Khu vực ưu tiên --}}
                <flux:select
                    wire:model="form.priority_area"
                    :label="__('Khu vực ưu tiên (không bắt buộc)')"
                >
                    <flux:select.option value="">
                        {{ __('Không thuộc diện ưu tiên khu vực') }}
                    </flux:select.option>

                    <flux:select.option value="KV1">
                        KV1 - Khu vực 1
                    </flux:select.option>

                    <flux:select.option value="KV2-NT">
                        KV2-NT - Khu vực 2 nông thôn
                    </flux:select.option>

                    <flux:select.option value="KV2">
                        KV2 - Khu vực 2
                    </flux:select.option>

                    <flux:select.option value="KV3">
                        KV3 - Khu vực 3
                    </flux:select.option>
                </flux:select>

                {{-- Đối tượng ưu tiên --}}
                <flux:select
                    wire:model="form.priority_object"
                    :label="__('Đối tượng ưu tiên (optional)')"
                >
                    <flux:select.option value="">
                        {{ __('Không thuộc đối tượng ưu tiên') }}
                    </flux:select.option>

                    <flux:select.option value="01">
                        01 - Nhóm ưu tiên UT1
                    </flux:select.option>

                    <flux:select.option value="02">
                        02 - Nhóm ưu tiên UT1
                    </flux:select.option>

                    <flux:select.option value="03">
                        03 - Nhóm ưu tiên UT1
                    </flux:select.option>

                    <flux:select.option value="04">
                        04 - Nhóm ưu tiên UT2
                    </flux:select.option>

                    <flux:select.option value="05">
                        05 - Nhóm ưu tiên UT2
                    </flux:select.option>

                    <flux:select.option value="06">
                        06 - Nhóm ưu tiên UT2
                    </flux:select.option>
                </flux:select>

            </div>

            <flux:button
                variant="primary"
                type="submit"
                x-bind:disabled="uploading"
                wire:loading.attr="disabled"
                wire:target="save,photo"
            >
                {{ __('Lưu hồ sơ') }}
            </flux:button>

            <span
                role="status"
                wire:loading
                wire:target="save"
            >
                {{ __('Đang lưu hồ sơ...') }}
            </span>
        </div>

        <div class="flex flex-col gap-5">

            {{-- Ảnh hồ sơ --}}
            <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">

                <flux:heading>
                    {{ __('Ảnh hồ sơ 3×4') }}
                </flux:heading>

                @if ($profile?->photo_path)
                    <img
                        src="{{ route('admission.profiles.photo', [
                            'profile' => $profile->id,
                            'v' => hash('sha256', $profile->photo_path)
                        ]) }}"
                        alt="{{ __('Ảnh hồ sơ') }}"
                        class="aspect-[3/4] w-36 rounded-lg object-cover"
                    />
                @endif

                <flux:input
                    wire:model="photo"
                    type="file"
                    accept="image/jpeg,image/png"
                    :label="__('Chọn ảnh')"
                />

                <flux:text>
                    {{ __('Ảnh JPEG hoặc PNG, tối đa 2 MiB, tỷ lệ 3:4 và kích thước từ 300×400 đến 3000×4000 điểm ảnh. Ảnh được lưu cùng hồ sơ của bạn.') }}
                </flux:text>

                <div
                    x-show="uploading"
                    x-cloak
                    role="status"
                >
                    <span>
                        {{ __('Đang tải lên:') }}
                    </span>

                    <span x-text="progress + '%'"></span>

                    <progress
                        x-bind:value="progress"
                        max="100"
                        class="w-full"
                    ></progress>
                </div>
            </div>
            {{-- CCCD --}}
            <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">

                <flux:heading>
                    {{ __('Ảnh CCCD') }}
                </flux:heading>

                <flux:text>
                    {{ __('Tải lên ảnh rõ nét mặt trước và mặt sau của CCCD để nhân viên đối chiếu thông tin hồ sơ.') }}
                </flux:text>

                <div>
                    <flux:input
                        wire:model="citizenIdFront"
                        type="file"
                        accept="image/jpeg,image/png"
                        :label="__('CCCD mặt trước')"
                    />

                    @if ($profile?->citizen_id_front_path)
                        <flux:text class="mt-2">
                            ✓ {{ __('Đã tải lên CCCD mặt trước') }}
                        </flux:text>
                    @endif
                </div>

                <div>
                    <flux:input
                        wire:model="citizenIdBack"
                        type="file"
                        accept="image/jpeg,image/png"
                        :label="__('CCCD mặt sau')"
                    />

                    @if ($profile?->citizen_id_back_path)
                        <flux:text class="mt-2">
                            ✓ {{ __('Đã tải lên CCCD mặt sau') }}
                        </flux:text>
                    @endif
                </div>

                <flux:text>
                    {{ __('JPEG/PNG, tối đa 5 MiB cho mỗi ảnh.') }}
                </flux:text>

            </div>

            {{-- Checklist --}}
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">

                <flux:heading>
                    {{ __('Thông tin hồ sơ đã lưu') }}
                </flux:heading>

                <flux:text class="my-3">
                    {{ __('Trạng thái xác minh được theo dõi riêng với mức độ hoàn thiện hồ sơ.') }}
                </flux:text>

                @php($labels = [
                    'date_of_birth' => 'Ngày sinh',
                    'gender' => 'Giới tính',
                    'ethnicity' => 'Dân tộc',
                    'citizen_id' => 'Số CCCD',
                    'citizen_id_issued_date' => 'Ngày cấp CCCD',
                    'citizen_id_issued_place' => 'Nơi cấp CCCD',
                    'phone' => 'Số điện thoại',
                    'address' => 'Địa chỉ',
                    'province_code' => 'Mã tỉnh/thành phố',
                    'high_school_name' => 'Trường THPT',
                    'graduation_year' => 'Năm tốt nghiệp',
                    'citizen_id_front_path' => 'CCCD mặt trước',
                    'citizen_id_back_path' => 'CCCD mặt sau',
                    'photo_path' => 'Ảnh hồ sơ',
                ])

                <ul class="space-y-2 text-sm">
                    @foreach ($completionFields as $field)
                        <li wire:key="check-{{ $field }}">
                            {{ filled($profile?->getAttribute($field)) ? '✓' : '○' }}
                            {{ __($labels[$field]) }}
                        </li>
                    @endforeach
                </ul>

            </div>
        </div>
    </form>
</section>
