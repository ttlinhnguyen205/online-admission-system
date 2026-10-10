<?php

namespace App\Livewire\Admin;

use App\Actions\AdmissionEngineSnapshot;
use App\Actions\NativeAllocationPolicyManagement;
use App\Actions\NativeAllocationRun;
use App\Actions\NativeDeferredAcceptance;
use App\Actions\NativeResultWorkflow;
use App\Models\AdmissionMethod;
use App\Models\NativeAllocationPolicy;
use App\Models\NativeResultVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NativeResults extends Component
{
    #[Locked]
    public int $roundId;

    #[Locked]
    public ?int $versionId = null;

    #[Locked]
    public string $expectedHash = '';

    /** @var list<string> */
    #[Locked]
    public array $blockers = [];

    public bool $confirmed = false;

    public string $rejectionReason = '';

    public string $methodOrder = '';

    public string $policyReference = '';

    public string $ruleEquivalences = '';

    public function draftPolicy(NativeAllocationPolicyManagement $management): void
    {
        $this->boot();
        $this->validate(['methodOrder' => ['required', 'string', 'max:1000'], 'policyReference' => ['required', 'string', 'max:1000'], 'ruleEquivalences' => ['string', 'max:4000']]);
        $codes = array_map('trim', explode(',', $this->methodOrder));
        $methods = AdmissionMethod::query()->whereIn('code', $codes)->get()->keyBy('code');
        $ids = [];
        foreach ($codes as $code) {
            $method = $methods->get($code);
            abort_unless($method !== null, 422);
            $ids[] = $method->id;
        }
        $equivalences = [];
        foreach (preg_split('/\R/', trim($this->ruleEquivalences)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $rules = [];
            foreach (explode(',', $line) as $value) {
                abort_unless(ctype_digit(trim($value)), 422);
                $rules[] = (int) trim($value);
            }
            $equivalences[] = $rules;
        }
        $management->createDraft($this->roundId, ['algorithm' => NativeDeferredAcceptance::ALGORITHM, 'method_priority' => $ids,
            'rule_equivalences' => $equivalences, 'ties' => 'block', 'policy_reference' => $this->policyReference]);
        $this->confirmed = false;
    }

    public function approvePolicy(int $id, string $hash, NativeAllocationPolicyManagement $management): void
    {
        $this->boot();
        $this->validate(['confirmed' => ['accepted']]);
        NativeAllocationPolicy::query()->where('admission_round_id', $this->roundId)->findOrFail($id);
        $management->approve($id, $hash);
        $this->confirmed = false;
    }

    public function allocate(NativeAllocationRun $allocation, NativeResultWorkflow $workflow): void
    {
        $this->boot();
        $this->validate(['confirmed' => ['accepted']]);
        $version = $allocation->run($this->roundId, $workflow);
        $this->select($version->id, $workflow);
    }

    public function boot(): void
    {
        AdmissionEngineSnapshot::actor();
        Gate::authorize('viewAny', NativeResultVersion::class);
    }

    public function select(int $id, NativeResultWorkflow $workflow): void
    {
        $version = NativeResultVersion::query()->where('admission_round_id', $this->roundId)->findOrFail($id);
        $this->versionId = $id;
        $this->expectedHash = $version->content_hash;
        $this->confirmed = false;
        $this->rejectionReason = '';
        $this->blockers = $workflow->check($id);
        $this->resetValidation();
    }

    public function approve(NativeResultWorkflow $workflow): void
    {
        $this->action($workflow, 'approve');
    }

    public function publish(NativeResultWorkflow $workflow): void
    {
        $this->action($workflow, 'publish');
    }

    public function reject(NativeResultWorkflow $workflow): void
    {
        $this->action($workflow, 'reject');
    }

    private function action(NativeResultWorkflow $workflow, string $operation): void
    {
        $this->boot();
        $this->validate(['confirmed' => ['accepted']]);
        $version = NativeResultVersion::query()->where('admission_round_id', $this->roundId)->findOrFail($this->versionId);
        Gate::authorize($operation, $version);
        if ($operation === 'reject') {
            $this->validate(['rejectionReason' => ['required', 'string', 'max:2000']]);
            $workflow->reject($version->id, $this->expectedHash, $this->rejectionReason);
        } else {
            $workflow->{$operation}($version->id, $this->expectedHash);
        }
        $this->confirmed = false;
        $this->dispatch('native-results-changed');
    }

    public function render(): View
    {
        $available = Schema::hasTable('native_result_versions');

        return view('livewire.admin.native-results', ['available' => $available,
            'allocationAvailable' => Schema::hasTable('native_allocation_policies'),
            'allocationMethods' => AdmissionMethod::query()->pluck('code', 'id'),
            'allocationPolicies' => Schema::hasTable('native_allocation_policies') ? NativeAllocationPolicy::query()->where('admission_round_id', $this->roundId)->orderByDesc('version')->get() : collect(),
            'versions' => $available ? NativeResultVersion::query()->where('admission_round_id', $this->roundId)->with('entries')->orderByDesc('version')->get() : collect()]);
    }
}
