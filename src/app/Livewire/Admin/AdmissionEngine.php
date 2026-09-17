<?php

namespace App\Livewire\Admin;

use App\Actions\AdmissionEngineSnapshot;
use App\Actions\ProcessAdmissionRound;
use App\Models\AdmissionRound;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Admission engine')]
class AdmissionEngine extends Component
{
    public string $roundSelection = '';

    #[Locked]
    public ?int $roundId = null;

    /** @var array<string, mixed> */
    #[Locked]
    public array $previewData = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $summary = [];

    #[Locked]
    public bool $confirmed = false;

    public bool $showConfirmation = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public function boot(): void
    {
        AdmissionEngineSnapshot::actor();
    }

    public function preview(ProcessAdmissionRound $engine): void
    {
        AdmissionEngineSnapshot::actor();
        $this->resetValidation();
        $this->reset('previewData', 'summary', 'confirmed', 'showConfirmation', 'roundId');
        $this->validate(['roundSelection' => ['required', 'integer', 'min:1']]);
        $this->roundId = (int) $this->roundSelection;
        $this->previewData = $engine->preview($this->roundId);
        $this->summary = $this->previewData['summary'] ?? [];
    }

    public function confirm(): void
    {
        AdmissionEngineSnapshot::actor();
        abort_unless($this->roundId !== null && $this->previewData !== [] && ! $this->previewData['completed'], 403);
        if ($this->previewData['blockers'] !== []) {
            AdmissionEngineSnapshot::fail('Resolve the readiness blockers before processing.');
        }
        $this->confirmed = true;
        $this->showConfirmation = true;
    }

    public function process(ProcessAdmissionRound $engine): void
    {
        AdmissionEngineSnapshot::actor();
        abort_unless($this->confirmed && $this->roundId !== null && $this->previewData !== [], 403);
        if ($this->form !== [] || $this->roundSelection !== (string) $this->roundId) {
            AdmissionEngineSnapshot::fail('This decision accepts no result fields or replacement round. Preview again.');
        }
        try {
            $this->summary = $engine->process($this->roundId, $this->previewData['fingerprint']);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: __('Processing blocked. No new decisions were saved.'));
            throw $exception;
        }
        $this->confirmed = false;
        $this->showConfirmation = false;
        $this->previewData = $engine->preview($this->roundId);
        Flux::toast(variant: 'success', text: __('Admission decisions saved. Results remain unpublished.'));
    }

    public function render(): View
    {
        AdmissionEngineSnapshot::actor();

        return view('livewire.admin.admission-engine', ['rounds' => AdmissionRound::query()->orderByDesc('year')->orderBy('id')->get()]);
    }
}
