<?php

namespace App\Livewire\Admin;

use App\Actions\EvaluationRuleManagement;
use App\Actions\EvaluationTemplateRegistry;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\EvaluationRuleVersion;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class EvaluationRules extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $programId = null;

    #[Locked]
    public ?int $expectedRuleId = null;

    #[Locked]
    public ?int $ruleId = null;

    public string $methodId = '';

    public string $template = 'THPT_SCORE';

    public string $reason = '';

    /** @var array<string, mixed> */
    public array $payload = [];

    public function boot(): void
    {
        Auth::user()?->refresh();
        Gate::authorize('viewAny', EvaluationRuleVersion::class);
    }

    public function mount(?int $programId = null): void
    {
        $this->programId = $programId;
        $this->newDraft();
    }

    public function newDraft(): void
    {
        $this->resetValidation();
        $this->ruleId = null;
        $this->reason = '';
        $this->payload = ['subjects' => ['', '', ''], 'source_year' => '', 'minimum_subject_score' => '', 'minimum_total_score' => '', 'policy_reference' => ''];
        if ($this->programId !== null) {
            $program = $this->program();
            $this->methodId = (string) $program->admission_method_id;
            $this->expectedRuleId = $program->evaluation_rule_version_id;
            if ($program->admissionMethod->code === 'DEMO-HB-EQ1') {
                $this->template = 'TRANSCRIPT_SCORE';
            }
        }
        $this->updatedTemplate();
    }

    public function updatedTemplate(): void
    {
        if ($this->ruleId !== null) {
            $this->template = $this->selected()->template_identifier;

            return;
        }
        unset($this->payload['grade_level']);
        if ($this->template === 'TRANSCRIPT_SCORE') {
            $this->payload['grade_level'] = '';
        }
    }

    public function selectRule(int $id): void
    {
        $rule = $this->scopedRule($id);
        $this->resetValidation();
        $this->ruleId = $rule->id;
        $this->methodId = (string) $rule->admission_method_id;
        $this->template = $rule->template_identifier;
        $this->payload = $rule->payload;
        $this->reason = '';
    }

    public function saveDraft(EvaluationRuleManagement $rules): void
    {
        if ($this->ruleId === null) {
            $this->validate(['methodId' => ['required', 'integer', Rule::exists(AdmissionMethod::class, 'id')]]);
            $methodId = $this->programId === null ? (int) $this->methodId : $this->program()->admission_method_id;
            if (AdmissionMethod::query()->findOrFail($methodId)->code === 'DEMO-HB-EQ1' && $this->template !== 'TRANSCRIPT_SCORE') {
                throw ValidationException::withMessages(['rules' => 'DEMO-HB-EQ1 cần template TRANSCRIPT_SCORE.']);
            }
            $rule = $rules->createDraft($methodId, $this->template, 1, $this->payload, $this->reason);
            $this->selectRule($rule->id);
        } else {
            $rules->updateDraft($this->selected()->id, $this->payload, $this->reason);
        }
        Flux::toast(variant: 'success', text: 'Đã lưu bản nháp. Chưa tự động phê duyệt hoặc gắn vào chương trình.');
    }

    public function approve(EvaluationRuleManagement $rules): void
    {
        $rules->approve($this->selected()->id);
        $this->selectRule($this->selected()->id);
        $this->dispatch('evaluation-rules-changed');
        Flux::toast(variant: 'success', text: 'Đã phê duyệt nội dung đã lưu. Cần gắn phiên bản vào chương trình riêng.');
    }

    public function newVersion(EvaluationRuleManagement $rules): void
    {
        $rule = $rules->newVersion($this->selected()->id, $this->reason);
        $this->selectRule($rule->id);
        Flux::toast(variant: 'success', text: 'Đã tạo phiên bản nháp mới; phiên bản cũ được giữ nguyên.');
    }

    public function retire(EvaluationRuleManagement $rules): void
    {
        $rules->retire($this->selected()->id, $this->reason);
        $this->dispatch('evaluation-rules-changed');
        Flux::toast(variant: 'success', text: 'Đã ngừng sử dụng; các submission đã pin được giữ nguyên.');
    }

    public function bind(EvaluationRuleManagement $rules): void
    {
        $program = $this->program();
        $rules->bind($program->id, $this->selected()->id, $this->expectedRuleId, $this->reason);
        $this->expectedRuleId = $this->selected()->id;
        $this->dispatch('evaluation-rules-changed');
        Flux::toast(variant: 'success', text: 'Đã gắn rule cho đăng ký native mới.');
    }

    private function program(): AdmissionProgram
    {
        abort_if($this->programId === null, 404);
        $program = AdmissionProgram::query()->with(['major', 'admissionMethod', 'evaluationRule'])->findOrFail($this->programId);
        Gate::authorize('update', $program);

        return $program;
    }

    private function scopedRule(int $id): EvaluationRuleVersion
    {
        $rule = EvaluationRuleVersion::query()->when($this->programId !== null,
            fn ($query) => $query->where('admission_method_id', $this->program()->admission_method_id))->findOrFail($id);
        Gate::authorize('update', $rule);

        return $rule;
    }

    private function selected(): EvaluationRuleVersion
    {
        abort_if($this->ruleId === null, 404);

        return $this->scopedRule($this->ruleId);
    }

    public function render(): View
    {
        $program = $this->programId === null ? null : $this->program();
        $rules = EvaluationRuleVersion::query()->with('admissionMethod')->withCount('programs')
            ->when($program !== null, fn ($query) => $query->where('admission_method_id', $program->admission_method_id))
            ->orderByDesc('id')->paginate(10, pageName: 'rulesPage');

        return view('livewire.admin.evaluation-rules', [
            'program' => $program, 'rules' => $rules, 'selected' => $this->ruleId === null ? null : $this->selected(),
            'methods' => AdmissionMethod::query()->where('is_active', true)->orderBy('name')->get(),
            'templates' => app(EvaluationTemplateRegistry::class)->templates(), 'subjects' => config('admission_data.subjects', []),
        ]);
    }
}
