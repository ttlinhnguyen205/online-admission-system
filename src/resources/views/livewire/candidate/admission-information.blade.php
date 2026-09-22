<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Thông tin tuyển sinh') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Chỉ cung cấp những thông tin phù hợp với hồ sơ của bạn. Bạn có thể bỏ qua các mục không áp dụng.') }}</flux:text>
    </div>
    @if ($profile === null)
        <flux:callout variant="warning">{{ __('Vui lòng hoàn thiện hồ sơ cá nhân trước khi khai báo thông tin tuyển sinh.') }}</flux:callout>
    @else
    <flux:error name="form" />
    <flux:error name="cleanup" />

    <div data-admission-section="certificates" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><flux:heading size="lg">{{ __('Chứng chỉ') }}</flux:heading><flux:text>{{ __('Bạn có thể bỏ qua mục này nếu không có chứng chỉ.') }}</flux:text></div>
            @if ($certificates->isEmpty() && ! $showCertificateEditor)<flux:button wire:click="createCertificate">{{ __('Khai báo chứng chỉ') }}</flux:button>@endif
        </div>
        <div class="space-y-3">
            @forelse ($certificates as $certificate)
                <article wire:key="certificate-{{ $certificate->id }}" class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <strong>{{ $certificateTypes[$certificate->certificate_type->value]['label'] }}</strong>
                        <flux:badge>{{ App\Support\CandidateStatusLabels::verification($certificate->status) }}</flux:badge>
                    </div>
                    <flux:text>{{ __('Điểm: :score', ['score' => $certificate->score]) }}</flux:text>
                    @if ($certificate->certificate_number)<flux:text>{{ __('Số chứng chỉ: :number', ['number' => $certificate->certificate_number]) }}</flux:text>@endif
                    <flux:text>{{ __('Ngày cấp: :date', ['date' => $certificate->issued_at?->format('d/m/Y') ?? '—']) }} · {{ __('Ngày hết hạn: :date', ['date' => $certificate->expires_at?->format('d/m/Y') ?? '—']) }}</flux:text>
                    @if ($certificate->rejection_reason)<flux:callout variant="warning">{{ __('Lý do từ chối: :reason', ['reason' => $certificate->rejection_reason]) }}</flux:callout>@endif
                    <div class="flex flex-wrap gap-2">
                        @if ($certificate->evidence_path)<flux:button size="sm" :href="route('candidate.admission-information.evidence', ['type' => 'certificates', 'record' => $certificate->id])" target="_blank">{{ __('Xem minh chứng') }}</flux:button>@endif
                        @if ($certificate->status !== App\Enums\VerificationStatus::Verified)
                            <flux:button size="sm" wire:click="editCertificate({{ $certificate->id }})">{{ __('Chỉnh sửa') }}</flux:button>
                            <flux:button size="sm" variant="danger" wire:click="deleteCertificate({{ $certificate->id }})" wire:confirm="{{ __('Bạn có chắc muốn xóa chứng chỉ này?') }}">{{ __('Xóa') }}</flux:button>
                        @endif
                    </div>
                </article>
            @empty
                <flux:text>{{ __('Chưa có chứng chỉ nào.') }}</flux:text>
            @endforelse
        </div>
        @if ($showCertificateEditor)
        <form wire:submit="saveCertificate" class="space-y-4">
            <flux:heading>{{ $certificateId ? __('Chỉnh sửa chứng chỉ') : __('Thêm chứng chỉ') }}</flux:heading>
            <flux:select wire:model="certificateForm.certificate_type" :label="__('Loại chứng chỉ')" required><option value="">{{ __('Chọn loại chứng chỉ') }}</option>@foreach ($certificateTypes as $value => $definition)<option value="{{ $value }}">{{ $definition['label'] }}</option>@endforeach</flux:select>
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="certificateForm.score" type="number" step="0.001" min="0" :label="__('Điểm')" required /><flux:input wire:model="certificateForm.certificate_number" :label="__('Số chứng chỉ (không bắt buộc)')" /></div>
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="certificateForm.issued_at" type="date" :label="__('Ngày cấp')" /><flux:input wire:model="certificateForm.expires_at" type="date" :label="__('Ngày hết hạn')" /></div>
            <flux:input wire:model="certificateEvidence" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" :label="$certificateId ? __('Thay ảnh minh chứng (không bắt buộc)') : __('Ảnh minh chứng')" />
            <flux:error name="certificateEvidence" /><flux:error name="certificateForm.certificate_type" /><flux:error name="certificateForm.score" />
            <div class="flex justify-end gap-3"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Lưu') }}</flux:button></div>
        </form>
        @endif
    </div>

    <div data-admission-section="claims" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><flux:heading size="lg">{{ __('Xét tuyển thẳng & ưu tiên xét tuyển') }}</flux:heading><flux:text>{{ __('Bạn có thể bỏ qua mục này nếu không thuộc diện xét tuyển thẳng hoặc ưu tiên xét tuyển.') }}</flux:text></div>

        </div>
        <flux:callout>{{ __('Đây là thông tin bạn khai báo, không có nghĩa là tự động đủ điều kiện hoặc trúng tuyển. Nhà trường sẽ kiểm tra minh chứng và xác minh theo quy định áp dụng.') }}</flux:callout>
        @foreach (['direct_admission' => 'Tôi đăng ký xét tuyển thẳng', 'priority_admission' => 'Tôi đăng ký ưu tiên xét tuyển'] as $type => $label)
            @php($declared = $claims->firstWhere('claim_type', $type))
            @php($immutable = $declared?->status === App\Enums\VerificationStatus::Verified)
            <div wire:key="declaration-{{ $type }}" class="space-y-3">
                <flux:checkbox wire:model.live="claimSelections.{{ $type }}" :label="$label" :disabled="$immutable" />
                @if ($claimSelections[$type])
                    @if ($immutable)
                        <flux:badge>{{ __('Đã xác minh') }}</flux:badge>
                        <flux:text>{{ $declared->description }}</flux:text>
                    @else
                        <form wire:submit="saveDeclaration('{{ $type }}')" class="space-y-3">
                            <flux:textarea wire:model="declarations.{{ $type }}.description" :label="__('Thông tin đề nghị và căn cứ minh chứng')" maxlength="5000" />
                            <flux:input wire:model="declarations.{{ $type }}.claim_code" :label="__('Mã trên giấy tờ (nếu có)')" :description="__('Chỉ ghi mã có trên giấy tờ của bạn; đây không phải mã diện do hệ thống xác định.')" />
                            <flux:input wire:model="declarationEvidence.{{ $type }}" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" :label="$declared ? __('Thay ảnh minh chứng (không bắt buộc)') : __('Ảnh minh chứng')" />
                            <flux:error name="declarationEvidence.{{ $type }}" />
                            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Lưu khai báo') }}</flux:button>
                        </form>
                    @endif
                    @if ($declared)
                        <flux:badge>{{ App\Support\CandidateStatusLabels::verification($declared->status) }}</flux:badge>
                        @if ($declared->rejection_reason)<flux:callout variant="warning">{{ $declared->rejection_reason }}</flux:callout>@endif
                        @if ($declared->evidence_path)<flux:button size="sm" :href="route('candidate.admission-information.evidence', ['type' => 'admission-claims', 'record' => $declared->id])" target="_blank">{{ __('Xem minh chứng') }}</flux:button>@endif
                        @unless ($immutable)<flux:button size="sm" variant="danger" wire:click="deleteClaim({{ $declared->id }})" wire:confirm="{{ __('Xóa khai báo đã lưu?') }}">{{ __('Xóa khai báo đã lưu') }}</flux:button>@endunless
                    @endif
                @endif
            </div>
        @endforeach
        <flux:text>{{ __('Bỏ chọn chỉ ẩn biểu mẫu, không xóa khai báo đã lưu. Dùng nút xóa nếu bạn muốn rút khai báo chưa xác minh.') }}</flux:text>
        <div class="space-y-3">
            @forelse ($claims->reject(fn ($claim) => in_array($claim->claim_type, ['direct_admission', 'priority_admission'], true)) as $claim)
                <article wire:key="claim-{{ $claim->id }}" class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex flex-wrap items-center justify-between gap-2"><strong>{{ $claim->claim_type }}</strong><flux:badge>{{ App\Support\CandidateStatusLabels::verification($claim->status) }}</flux:badge></div>
                    @if ($claim->claim_code)<flux:text>{{ __('Mã diện: :code', ['code' => $claim->claim_code]) }}</flux:text>@endif
                    @if ($claim->description)<p class="whitespace-pre-wrap text-sm">{{ $claim->description }}</p>@endif
                    @if ($claim->rejection_reason)<flux:callout variant="warning">{{ __('Lý do từ chối: :reason', ['reason' => $claim->rejection_reason]) }}</flux:callout>@endif
                    <div class="flex flex-wrap gap-2">
                        @if ($claim->evidence_path)<flux:button size="sm" :href="route('candidate.admission-information.evidence', ['type' => 'admission-claims', 'record' => $claim->id])" target="_blank">{{ __('Xem minh chứng') }}</flux:button>@endif
                        @if ($claim->status !== App\Enums\VerificationStatus::Verified)
                            <flux:button size="sm" wire:click="editClaim({{ $claim->id }})">{{ __('Chỉnh sửa') }}</flux:button>
                            <flux:button size="sm" variant="danger" wire:click="deleteClaim({{ $claim->id }})" wire:confirm="{{ __('Bạn có chắc muốn xóa thông tin này?') }}">{{ __('Xóa') }}</flux:button>
                        @endif
                    </div>
                </article>
            @empty

            @endforelse
        </div>
    </div>

    <div data-admission-section="competency" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><flux:heading size="lg">{{ __('Điểm ĐGNL/ĐGTD/V-SAT/SPT') }}</flux:heading><flux:text>{{ __('Khai báo điểm tổng của kỳ thi phù hợp với bạn.') }}</flux:text></div>
            @if ($competencyResults->isEmpty() && ! $showCompetencyEditor)<flux:button wire:click="createCompetency">{{ __('Khai báo kết quả kỳ thi') }}</flux:button>@endif
        </div>
        <div class="space-y-3">
            @forelse ($competencyResults as $result)
                <article wire:key="competency-{{ $result->id }}" class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex flex-wrap items-center justify-between gap-2"><strong>{{ $examTypes[$result->exam_type->value]['label'] }}</strong><flux:badge>{{ App\Support\CandidateStatusLabels::verification($result->status) }}</flux:badge></div>
                    <flux:text>{{ __('Điểm: :score · Năm thi: :year', ['score' => $result->overall_score, 'year' => $result->exam_year]) }}</flux:text>
                    <flux:text>{{ __('Đợt thi: :session', ['session' => $result->exam_session ?? '—']) }} · {{ __('Mã dự thi: :number', ['number' => $result->registration_number ?? '—']) }}</flux:text>
                    @if ($result->rejection_reason)<flux:callout variant="warning">{{ __('Lý do từ chối: :reason', ['reason' => $result->rejection_reason]) }}</flux:callout>@endif
                    <div class="flex flex-wrap gap-2">
                        @if ($result->evidence_path)<flux:button size="sm" :href="route('candidate.admission-information.evidence', ['type' => 'exam-results', 'record' => $result->id])" target="_blank">{{ __('Xem minh chứng') }}</flux:button>@endif
                        @if ($result->status !== App\Enums\VerificationStatus::Verified)
                            <flux:button size="sm" wire:click="editCompetency({{ $result->id }})">{{ __('Chỉnh sửa') }}</flux:button>
                            <flux:button size="sm" variant="danger" wire:click="deleteCompetency({{ $result->id }})" wire:confirm="{{ __('Bạn có chắc muốn xóa kết quả này?') }}">{{ __('Xóa') }}</flux:button>
                        @endif
                    </div>
                </article>
            @empty
                <flux:text>{{ __('Chưa có kết quả kỳ thi ĐGNL, ĐGTD, V-SAT hoặc SPT.') }}</flux:text>
            @endforelse
        </div>
        @if ($showCompetencyEditor)
        <form wire:submit="saveCompetency" class="space-y-4">
            <flux:heading>{{ $competencyId ? __('Chỉnh sửa kết quả kỳ thi') : __('Thêm kết quả kỳ thi') }}</flux:heading>
            <flux:select wire:model="competencyForm.exam_type" :label="__('Loại kỳ thi')" required><option value="">{{ __('Chọn loại kỳ thi') }}</option>@foreach (['dgnl', 'dgtd', 'vsat', 'spt'] as $value)<option value="{{ $value }}">{{ $examTypes[$value]['label'] }}</option>@endforeach</flux:select>
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="competencyForm.overall_score" type="number" step="0.001" min="0" :label="__('Điểm')" required /><flux:input wire:model="competencyForm.exam_year" type="number" :label="__('Năm thi')" required /></div>
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="competencyForm.exam_date" type="date" :label="__('Ngày thi (không bắt buộc)')" /><flux:input wire:model="competencyForm.exam_session" :label="__('Đợt thi (không bắt buộc)')" /></div>
            <flux:input wire:model="competencyForm.registration_number" :label="__('Số báo danh / mã dự thi (không bắt buộc)')" />
            <flux:input wire:model="competencyEvidence" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" :label="$competencyId ? __('Thay ảnh minh chứng (không bắt buộc)') : __('Ảnh minh chứng')" />
            <flux:error name="competencyEvidence" /><flux:error name="competencyForm.exam_type" /><flux:error name="competencyForm.overall_score" />
            <div class="flex justify-end gap-3"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Lưu') }}</flux:button></div>
        </form>
        @endif
    </div>

    <div data-admission-section="transcripts" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><flux:heading size="lg">{{ __('Điểm tổng kết học bạ THPT') }}</flux:heading><flux:text>{{ __('Chỉ thêm những môn bạn cần sử dụng để đăng ký xét tuyển.') }}</flux:text></div>
            <flux:button variant="primary" wire:click="createTranscript">{{ __('Thêm học bạ') }}</flux:button>
        </div>
        @forelse ($transcripts as $transcript)
            <article wire:key="transcript-{{ $transcript->id }}" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex flex-wrap items-center justify-between gap-2"><strong>{{ $transcript->school_name ?? __('Chưa ghi tên trường') }} · {{ $transcript->graduation_year }}</strong><flux:badge>{{ App\Support\CandidateStatusLabels::verification($transcript->status) }}</flux:badge></div>
                <div class="overflow-x-auto"><table class="w-full min-w-xl text-left text-sm"><thead><tr><th class="p-2">{{ __('Môn học') }}</th><th class="p-2">{{ __('Lớp 10') }}</th><th class="p-2">{{ __('Lớp 11') }}</th><th class="p-2">{{ __('Lớp 12') }}</th></tr></thead><tbody>
                    @foreach ($transcript->scores->groupBy('subject_code') as $subjectCode => $scores)
                        <tr wire:key="transcript-{{ $transcript->id }}-{{ $subjectCode }}"><td class="p-2">{{ $subjects[$subjectCode] ?? $scores->first()->subject_name }}</td><td class="p-2">{{ $scores->firstWhere('grade_level', 10)?->score ?? '—' }}</td><td class="p-2">{{ $scores->firstWhere('grade_level', 11)?->score ?? '—' }}</td><td class="p-2">{{ $scores->firstWhere('grade_level', 12)?->score ?? '—' }}</td></tr>
                    @endforeach
                </tbody></table></div>
                @if ($transcript->rejection_reason)<flux:callout variant="warning">{{ __('Lý do từ chối: :reason', ['reason' => $transcript->rejection_reason]) }}</flux:callout>@endif
                <div class="flex flex-wrap gap-2">
                    @foreach ($transcript->evidenceImages as $image)
                        <a wire:key="saved-transcript-image-{{ $image->id }}" href="{{ route('candidate.admission-information.evidence', ['type' => 'transcript-images', 'record' => $image->id]) }}" target="_blank" rel="noopener">
                            <img src="{{ route('candidate.admission-information.evidence', ['type' => 'transcript-images', 'record' => $image->id]) }}" alt="{{ __('Trang học bạ :page', ['page' => $loop->iteration]) }}" class="h-32 w-24 rounded object-contain" />
                        </a>
                    @endforeach
                    @if ($transcript->evidence_path)<flux:button size="sm" :href="route('candidate.admission-information.evidence', ['type' => 'transcripts', 'record' => $transcript->id])" target="_blank">{{ __('Xem minh chứng') }}</flux:button>@endif
                    @if ($transcript->status !== App\Enums\VerificationStatus::Verified)
                        <flux:button size="sm" wire:click="editTranscript({{ $transcript->id }})">{{ __('Chỉnh sửa') }}</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="deleteTranscript({{ $transcript->id }})" wire:confirm="{{ __('Bạn có chắc muốn xóa học bạ này?') }}">{{ __('Xóa') }}</flux:button>
                    @endif
                </div>
            </article>
        @empty
            <flux:text>{{ __('Chưa có điểm học bạ THPT.') }}</flux:text>
        @endforelse
    </div>



    <flux:modal wire:model="showClaimEditor" class="w-full md:max-w-2xl">
        <form wire:submit="saveClaim" class="space-y-4">
            <flux:heading>{{ $claimId ? __('Chỉnh sửa thông tin') : __('Thêm thông tin') }}</flux:heading>
            <flux:input wire:model="claimForm.claim_type" :label="__('Loại/diện xét tuyển')" :description="__('Mô tả theo giấy tờ bạn đang có; nhà trường sẽ đối chiếu sau.')" required />
            <flux:input wire:model="claimForm.claim_code" :label="__('Mã diện (không bắt buộc)')" />
            <flux:textarea wire:model="claimForm.description" :label="__('Thông tin mô tả (không bắt buộc)')" maxlength="5000" />
            <flux:input wire:model="claimEvidence" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" :label="$claimId ? __('Thay ảnh minh chứng (không bắt buộc)') : __('Ảnh minh chứng')" />
            <flux:error name="claimEvidence" /><flux:error name="claimForm.claim_type" />
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Lưu') }}</flux:button></div>
        </form>
    </flux:modal>





    <flux:modal wire:model="showTranscriptEditor" class="w-full md:max-w-5xl">
        <form wire:submit="saveTranscript" class="space-y-4">
            <flux:heading>{{ $transcriptId ? __('Chỉnh sửa học bạ') : __('Thêm học bạ') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="transcriptForm.school_name" :label="__('Tên trường')" /><flux:input wire:model="transcriptForm.graduation_year" type="number" :label="__('Năm tốt nghiệp')" required /></div>
            <div class="space-y-3">
                @foreach ([10, 11, 12] as $grade)
                    <div wire:key="transcript-grade-{{ $grade }}" x-data="{ expanded: {{ $grade === 10 ? 'true' : 'false' }} }" class="border-b border-zinc-200 pb-4 dark:border-zinc-700">
                        <button type="button" class="flex w-full items-center justify-between py-3 text-start font-semibold" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded" aria-controls="transcript-grade-{{ $grade }}-scores">
                            <span>{{ __('Lớp :grade', ['grade' => $grade]) }}</span>
                            <span x-text="expanded ? '−' : '+'" aria-hidden="true"></span>
                        </button>
                        <div id="transcript-grade-{{ $grade }}-scores" x-show="expanded" @if ($grade !== 10) x-cloak @endif class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            @foreach ($transcriptForm['subjects'] ?? [] as $index => $row)
                                <div wire:key="transcript-cell-{{ $grade }}-{{ $row['subject_code'] }}">
                                    <flux:input wire:model.live.debounce.300ms="transcriptForm.subjects.{{ $index }}.grade_{{ $grade }}" type="number" step="0.001" min="0" :label="$subjects[$row['subject_code']] ?? $row['subject_code']" />
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <flux:error name="transcriptForm.subjects" />
            @foreach ($errors->get('transcriptForm.subjects.*') as $messages)
                @foreach ($messages as $message)<p role="alert" class="text-sm text-red-600">{{ $message }}</p>@endforeach
            @endforeach
            <div x-data>
                <label for="transcript-evidence" class="mb-2 block text-sm font-medium text-zinc-800 dark:text-white">{{ __('Ảnh minh chứng học bạ (có thể chọn nhiều trang)') }}</label>
                <div class="flex w-full items-center gap-4">
                    <input id="transcript-evidence" x-ref="input" x-on:click="$refs.input.value = null" wire:model="transcriptEvidence" type="file" multiple accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="sr-only" />
                    <flux:button type="button" x-on:click="$refs.input.click()">{{ __('Chọn tệp') }}</flux:button>
                    <span class="truncate text-sm font-medium text-zinc-500 dark:text-zinc-400">
                        @if (count($transcriptEvidence) === 0)
                            {{ __('Chưa chọn tệp') }}
                        @elseif (count($transcriptEvidence) === 1)
                            {{ App\Actions\CandidateFiles::displayName($transcriptEvidence[0]->getClientOriginalName()) }}
                        @else
                            {{ __(':count tệp', ['count' => count($transcriptEvidence)]) }}
                        @endif
                    </span>
                </div>
            </div>
            <flux:text>{{ __('JPEG/JPG/PNG, tối đa 2 MiB mỗi ảnh. Ảnh mới được thêm khi lưu; đánh dấu ảnh cũ cần xóa để thay thế.') }}</flux:text>
            <div role="status" wire:loading.delay wire:target="transcriptEvidence">{{ __('Đang tải ảnh học bạ...') }}</div>
            <flux:error name="transcriptEvidence" />
            <div class="flex flex-wrap gap-4">
                @foreach ($transcriptEvidence as $index => $upload)
                    <div wire:key="transcript-upload-{{ $index }}" class="space-y-2">
                        <div class="relative h-32 w-24">
                            @if (! $errors->has('transcriptEvidence.'.$index) && $upload->isPreviewable())
                                <img src="{{ $upload->temporaryUrl() }}" alt="{{ __('Trang học bạ mới :page', ['page' => $index + 1]) }}" class="h-full w-full rounded object-contain" />
                            @else
                                <div class="flex h-full w-full items-center justify-center rounded border border-zinc-200 text-xs text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">{{ __('Không xem trước') }}</div>
                            @endif
                        </div>
                        <div class="max-w-32 truncate text-xs text-zinc-500 dark:text-zinc-400">{{ App\Actions\CandidateFiles::displayName($upload->getClientOriginalName()) }}</div>
                        <flux:error name="transcriptEvidence.{{ $index }}" />
                        <button type="button" wire:click="removeTranscriptUpload({{ $index }})" wire:loading.attr="disabled" wire:target="removeTranscriptUpload({{ $index }})" class="inline-flex items-center justify-center rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 shadow-sm transition duration-150 hover:-translate-y-0.5 hover:border-red-300 hover:bg-red-100 hover:shadow focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:pointer-events-none disabled:translate-y-0 disabled:opacity-60 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300 dark:hover:border-red-800 dark:hover:bg-red-900/50 dark:focus:ring-offset-zinc-900">
                            <span wire:loading.remove wire:target="removeTranscriptUpload({{ $index }})">{{ __('Xóa ảnh') }}</span>
                            <span wire:loading wire:target="removeTranscriptUpload({{ $index }})">{{ __('Đang xóa...') }}</span>
                        </button>
                    </div>
                @endforeach
                @php($editingTranscript = $transcripts->firstWhere('id', $transcriptId))
                @foreach ($editingTranscript?->evidenceImages ?? [] as $image)
                    <div wire:key="edit-transcript-image-{{ $image->id }}" class="space-y-2">
                        <img src="{{ route('candidate.admission-information.evidence', ['type' => 'transcript-images', 'record' => $image->id]) }}" alt="{{ __('Trang học bạ đã lưu :page', ['page' => $loop->iteration]) }}" class="h-32 w-24 rounded object-contain" />
                        <flux:checkbox wire:model="removedTranscriptEvidence" :value="$image->id" :label="__('Xóa ảnh này khi lưu')" />
                    </div>
                @endforeach
            </div>
            @if ($editingTranscript?->evidence_path)
                <flux:button size="sm" :href="route('candidate.admission-information.evidence', ['type' => 'transcripts', 'record' => $editingTranscript->id])" target="_blank">{{ __('Xem ảnh học bạ cũ') }}</flux:button>
                <flux:checkbox wire:model="removeLegacyTranscriptEvidence" :label="__('Xóa ảnh học bạ cũ khi lưu')" />
            @endif
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveTranscript,transcriptEvidence">{{ __('Lưu') }}</flux:button></div>
        </form>
    </flux:modal>
    @endif
    <div class="flex justify-end">
        <flux:button :href="route('candidate.applications.index')" wire:navigate>{{ __('Tiếp theo: Đăng ký nguyện vọng') }}</flux:button>
    </div>
</section>
