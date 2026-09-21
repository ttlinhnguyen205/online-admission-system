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

    <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><flux:heading size="lg">{{ __('Chứng chỉ') }}</flux:heading><flux:text>{{ __('Bạn có thể bỏ qua mục này nếu không có chứng chỉ.') }}</flux:text></div>
            <flux:button variant="primary" wire:click="createCertificate">{{ __('Thêm chứng chỉ') }}</flux:button>
        </div>
        <div class="grid gap-3 md:grid-cols-2">
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
    </div>

    <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><flux:heading size="lg">{{ __('Xét tuyển thẳng & ưu tiên xét tuyển') }}</flux:heading><flux:text>{{ __('Bạn có thể bỏ qua mục này nếu không thuộc diện xét tuyển thẳng hoặc ưu tiên xét tuyển.') }}</flux:text></div>
            <flux:button variant="primary" wire:click="createClaim">{{ __('Thêm thông tin') }}</flux:button>
        </div>
        <flux:callout>{{ __('Loại/diện xét tuyển do bạn mô tả để nhà trường đối chiếu; đây chưa phải danh mục diện xét tuyển chính thức.') }}</flux:callout>
        <div class="space-y-3">
            @forelse ($claims as $claim)
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
                <flux:text>{{ __('Chưa có thông tin xét tuyển thẳng hoặc ưu tiên xét tuyển.') }}</flux:text>
            @endforelse
        </div>
    </div>

    <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><flux:heading size="lg">{{ __('Điểm thi tốt nghiệp THPT') }}</flux:heading><flux:text>{{ __('Khai báo các môn có trên cùng một phiếu kết quả thi.') }}</flux:text></div>
            <flux:button variant="primary" wire:click="createThpt">{{ __('Thêm kết quả THPT') }}</flux:button>
        </div>
        @forelse ($thptResults as $result)
            <article wire:key="thpt-{{ $result->id }}" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex flex-wrap items-center justify-between gap-2"><strong>{{ __('Năm thi :year', ['year' => $result->exam_year]) }}</strong><flux:badge>{{ App\Support\CandidateStatusLabels::verification($result->status) }}</flux:badge></div>
                <flux:text>{{ __('Ngày thi: :date', ['date' => $result->exam_date?->format('d/m/Y') ?? '—']) }} · {{ __('Số báo danh: :number', ['number' => $result->registration_number ?? '—']) }}</flux:text>
                <div class="flex flex-wrap gap-2">@foreach ($result->subjectScores as $score)<flux:badge wire:key="thpt-score-{{ $score->id }}">{{ $subjects[$score->subject_code] ?? $score->subject_name }}: {{ $score->score }}</flux:badge>@endforeach</div>
                @if ($result->rejection_reason)<flux:callout variant="warning">{{ __('Lý do từ chối: :reason', ['reason' => $result->rejection_reason]) }}</flux:callout>@endif
                <div class="flex flex-wrap gap-2">
                    @if ($result->evidence_path)<flux:button size="sm" :href="route('candidate.admission-information.evidence', ['type' => 'exam-results', 'record' => $result->id])" target="_blank">{{ __('Xem minh chứng') }}</flux:button>@endif
                    @if ($result->status !== App\Enums\VerificationStatus::Verified)
                        <flux:button size="sm" wire:click="editThpt({{ $result->id }})">{{ __('Chỉnh sửa') }}</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="deleteThpt({{ $result->id }})" wire:confirm="{{ __('Bạn có chắc muốn xóa kết quả này?') }}">{{ __('Xóa') }}</flux:button>
                    @endif
                </div>
            </article>
        @empty
            <flux:text>{{ __('Chưa có điểm thi tốt nghiệp THPT.') }}</flux:text>
        @endforelse
    </div>

    <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><flux:heading size="lg">{{ __('Điểm ĐGNL/ĐGTD/V-SAT/SPT') }}</flux:heading><flux:text>{{ __('Khai báo điểm tổng của kỳ thi phù hợp với bạn.') }}</flux:text></div>
            <flux:button variant="primary" wire:click="createCompetency">{{ __('Thêm kết quả kỳ thi') }}</flux:button>
        </div>
        <div class="grid gap-3 md:grid-cols-2">
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
    </div>

    <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
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

    <flux:modal wire:model="showCertificateEditor" class="w-full md:max-w-2xl">
        <form wire:submit="saveCertificate" class="space-y-4">
            <flux:heading>{{ $certificateId ? __('Chỉnh sửa chứng chỉ') : __('Thêm chứng chỉ') }}</flux:heading>
            <flux:select wire:model="certificateForm.certificate_type" :label="__('Loại chứng chỉ')" required><option value="">{{ __('Chọn loại chứng chỉ') }}</option>@foreach ($certificateTypes as $value => $definition)<option value="{{ $value }}">{{ $definition['label'] }}</option>@endforeach</flux:select>
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="certificateForm.score" type="number" step="0.001" min="0" :label="__('Điểm')" required /><flux:input wire:model="certificateForm.certificate_number" :label="__('Số chứng chỉ (không bắt buộc)')" /></div>
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="certificateForm.issued_at" type="date" :label="__('Ngày cấp')" /><flux:input wire:model="certificateForm.expires_at" type="date" :label="__('Ngày hết hạn')" /></div>
            <flux:input wire:model="certificateEvidence" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" :label="$certificateId ? __('Thay ảnh minh chứng (không bắt buộc)') : __('Ảnh minh chứng')" />
            <flux:error name="certificateEvidence" /><flux:error name="certificateForm.certificate_type" /><flux:error name="certificateForm.score" />
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Lưu') }}</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showClaimEditor" class="w-full md:max-w-2xl">
        <form wire:submit="saveClaim" class="space-y-4">
            <flux:heading>{{ $claimId ? __('Chỉnh sửa thông tin') : __('Thêm thông tin') }}</flux:heading>
            <flux:input wire:model="claimForm.claim_type" :label="__('Loại/diện xét tuyển')" :description="__('Mô tả theo giấy tờ bạn đang có; nhà trường sẽ đối chiếu sau.')" required />
            <flux:input wire:model="claimForm.claim_code" :label="__('Mã diện (không bắt buộc)')" />
            <flux:textarea wire:model="claimForm.description" :label="__('Thông tin mô tả (không bắt buộc)')" maxlength="5000" />
            <flux:input wire:model="claimEvidence" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" :label="$claimId ? __('Thay ảnh minh chứng (không bắt buộc)') : __('Ảnh minh chứng')" />
            <flux:error name="claimEvidence" /><flux:error name="claimForm.claim_type" />
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Lưu') }}</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showThptEditor" class="w-full md:max-w-3xl">
        <form wire:submit="saveThpt" class="space-y-4">
            <flux:heading>{{ $thptId ? __('Chỉnh sửa kết quả THPT') : __('Thêm kết quả THPT') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-3"><flux:input wire:model="thptForm.exam_year" type="number" :label="__('Năm thi')" required /><flux:input wire:model="thptForm.exam_date" type="date" :label="__('Ngày thi (không bắt buộc)')" /><flux:input wire:model="thptForm.registration_number" :label="__('Số báo danh (không bắt buộc)')" /></div>
            <div class="space-y-3">
                <div class="flex items-center justify-between"><flux:heading size="sm">{{ __('Điểm các môn') }}</flux:heading><flux:button type="button" size="sm" wire:click="addThptSubject">{{ __('Thêm môn') }}</flux:button></div>
                @foreach ($thptForm['subjects'] ?? [] as $index => $row)
                    <div wire:key="thpt-form-{{ $index }}" class="grid gap-3 sm:grid-cols-[1fr_1fr_auto]"><flux:select wire:model="thptForm.subjects.{{ $index }}.subject_code" :label="__('Môn học')"><option value="">{{ __('Chọn môn') }}</option>@foreach ($subjects as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</flux:select><flux:input wire:model="thptForm.subjects.{{ $index }}.score" type="number" step="0.001" min="0" :label="__('Điểm')" /><flux:button type="button" variant="danger" class="self-end" wire:click="removeThptSubject({{ $index }})">{{ __('Xóa') }}</flux:button></div>
                    <flux:error name="thptForm.subjects.{{ $index }}.subject_code" /><flux:error name="thptForm.subjects.{{ $index }}.score" />
                @endforeach
            </div>
            <flux:input wire:model="thptEvidence" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" :label="$thptId ? __('Thay ảnh phiếu kết quả (không bắt buộc)') : __('Ảnh phiếu kết quả')" />
            <flux:error name="thptEvidence" /><flux:error name="thptForm.subjects" />
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Lưu') }}</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showCompetencyEditor" class="w-full md:max-w-2xl">
        <form wire:submit="saveCompetency" class="space-y-4">
            <flux:heading>{{ $competencyId ? __('Chỉnh sửa kết quả kỳ thi') : __('Thêm kết quả kỳ thi') }}</flux:heading>
            <flux:select wire:model="competencyForm.exam_type" :label="__('Loại kỳ thi')" required><option value="">{{ __('Chọn loại kỳ thi') }}</option>@foreach (['dgnl', 'dgtd', 'vsat', 'spt'] as $value)<option value="{{ $value }}">{{ $examTypes[$value]['label'] }}</option>@endforeach</flux:select>
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="competencyForm.overall_score" type="number" step="0.001" min="0" :label="__('Điểm')" required /><flux:input wire:model="competencyForm.exam_year" type="number" :label="__('Năm thi')" required /></div>
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="competencyForm.exam_date" type="date" :label="__('Ngày thi (không bắt buộc)')" /><flux:input wire:model="competencyForm.exam_session" :label="__('Đợt thi (không bắt buộc)')" /></div>
            <flux:input wire:model="competencyForm.registration_number" :label="__('Số báo danh / mã dự thi (không bắt buộc)')" />
            <flux:input wire:model="competencyEvidence" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" :label="$competencyId ? __('Thay ảnh minh chứng (không bắt buộc)') : __('Ảnh minh chứng')" />
            <flux:error name="competencyEvidence" /><flux:error name="competencyForm.exam_type" /><flux:error name="competencyForm.overall_score" />
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Lưu') }}</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showTranscriptEditor" class="w-full md:max-w-5xl">
        <form wire:submit="saveTranscript" class="space-y-4">
            <flux:heading>{{ $transcriptId ? __('Chỉnh sửa học bạ') : __('Thêm học bạ') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="transcriptForm.school_name" :label="__('Tên trường')" /><flux:input wire:model="transcriptForm.graduation_year" type="number" :label="__('Năm tốt nghiệp')" required /></div>
            <div class="space-y-3 overflow-x-auto">
                <div class="flex items-center justify-between"><flux:heading size="sm">{{ __('Điểm tổng kết') }}</flux:heading><flux:button type="button" size="sm" wire:click="addTranscriptSubject">{{ __('Thêm môn') }}</flux:button></div>
                @foreach ($transcriptForm['subjects'] ?? [] as $index => $row)
                    <div wire:key="transcript-form-{{ $index }}" class="grid min-w-3xl gap-3 md:grid-cols-[1.4fr_1fr_1fr_1fr_auto]"><flux:select wire:model="transcriptForm.subjects.{{ $index }}.subject_code" :label="__('Môn học')"><option value="">{{ __('Chọn môn') }}</option>@foreach ($subjects as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</flux:select><flux:input wire:model="transcriptForm.subjects.{{ $index }}.grade_10" type="number" step="0.001" min="0" :label="__('Lớp 10')" /><flux:input wire:model="transcriptForm.subjects.{{ $index }}.grade_11" type="number" step="0.001" min="0" :label="__('Lớp 11')" /><flux:input wire:model="transcriptForm.subjects.{{ $index }}.grade_12" type="number" step="0.001" min="0" :label="__('Lớp 12')" /><flux:button type="button" variant="danger" class="self-end" wire:click="removeTranscriptSubject({{ $index }})">{{ __('Xóa') }}</flux:button></div>
                    <flux:error name="transcriptForm.subjects.{{ $index }}.subject_code" />
                @endforeach
            </div>
            <flux:error name="transcriptForm.subjects" />
            <flux:input wire:model="transcriptEvidence" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" :label="$transcriptId ? __('Thay ảnh học bạ (không bắt buộc)') : __('Ảnh minh chứng học bạ')" />
            <flux:error name="transcriptEvidence" />
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button>{{ __('Hủy') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Lưu') }}</flux:button></div>
        </form>
    </flux:modal>
    @endif
</section>
