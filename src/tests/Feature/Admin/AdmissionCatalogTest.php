<?php

use App\Livewire\Admin\AdmissionMethods;
use App\Livewire\Admin\AdmissionPrograms;
use App\Livewire\Admin\CandidateMajorOfferings;
use App\Livewire\Admin\Majors;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\CandidateMajorOffering;
use App\Models\Major;
use App\Models\User;
use Livewire\Livewire;

test('offering round status and search filters combine reset pagination and clear', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    CandidateMajorOffering::factory()->count(16)->create();
    $target = CandidateMajorOffering::factory()->create(['is_selectable' => false]);
    $target->major->update(['name' => 'Ngành cần tìm']);
    Livewire::test(CandidateMajorOfferings::class)->call('setPage', 2)
        ->set('roundFilter', (string) $target->admission_round_id)->assertSet('paginators.page', 1)
        ->set('search', 'Ngành cần tìm')->set('statusFilter', 'disabled')
        ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $target->id)
        ->set('statusFilter', 'enabled')->assertViewHas('records', fn ($records) => $records->isEmpty())
        ->call('clearFilters')->assertSet('roundFilter', '')->assertSet('search', '')->assertSet('statusFilter', '')
        ->assertViewHas('records', fn ($records) => $records->total() === 17);
});

test('catalog page roots contain all interactive controls and hydrate without nested component ids', function (string $path) {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $response = $this->get(route('admin.'.$path.'.index'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $roots = $xpath->query('//*[@*[name()="wire:id"]]');
    expect($roots->length)->toBe(1);
    $root = $roots->item(0);
    expect($root->hasAttribute('wire:snapshot'))->toBeTrue();
    $outside = $xpath->query('//*[@*[starts-with(name(), "wire:model") or name()="wire:click" or name()="wire:submit"] and not(ancestor-or-self::*[@*[name()="wire:id"]])]');
    expect($outside->length)->toBe(0);
    $snapshot = $root->getAttribute('wire:snapshot');
    $id = $root->getAttribute('wire:id');
    $endpoint = $xpath->query('//script[@data-update-uri]')->item(0)->getAttribute('data-update-uri');
    $updated = $this->postJson($endpoint, ['components' => [[
        'snapshot' => $snapshot, 'updates' => ['search' => 'Không có ngành này'], 'calls' => [],
    ]]], ['X-Livewire' => 'true'])->assertOk();
    expect(json_decode($updated->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR)['data']['search'])->toBe('Không có ngành này');
    @$document->loadHTML('<?xml encoding="UTF-8">'.$updated->json('components.0.effects.html'));
    $xpath = new DOMXPath($document);
    expect($xpath->query('//*[@*[name()="wire:id"]]')->length)->toBe(1)
        ->and($xpath->query('//*[@*[name()="wire:id"]]')->item(0)->getAttribute('wire:id'))->toBe($id);
})->with(['majors', 'admission-methods', 'admission-programs', 'candidate-major-offerings']);

test('offering page distinguishes configured registration from current admission availability', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $offering = CandidateMajorOffering::factory()->create();
    $offering->admissionRound->update(['status' => 'open', 'start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    Livewire::test(CandidateMajorOfferings::class)->assertSee('Đang nhận đăng ký');
    $offering->admissionRound->update(['end_date' => now()->subHour()]);
    Livewire::test(CandidateMajorOfferings::class)->assertSee('Ngoài thời gian nhận hồ sơ')->assertDontSee('Đang nhận đăng ký');
});

test('Vietnamese tuition display preserves zero unspecified and fractional amounts', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    Major::factory()->create(['default_tuition_fee' => '25000000.50']);
    Major::factory()->create(['default_tuition_fee' => '0']);
    Major::factory()->create(['default_tuition_fee' => null]);
    $this->get(route('admin.majors.index'))->assertOk()->assertSee('25.000.000,50 VND')->assertSee('0 VND')->assertSee('Chưa thiết lập');
    AdmissionProgram::factory()->create(['tuition_fee' => '25000000', 'minimum_score' => '24.125']);
    $this->get(route('admin.admission-programs.index'))->assertOk()->assertSee('25.000.000 VND')->assertSee('24,125 điểm');
});

test('bundled offering parent selections keep a matching program while incompatible selections clear it', function () {
    $program = AdmissionProgram::factory()->create();
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$this->get(route('admin.candidate-major-offerings.index'))->getContent());
    $xpath = new DOMXPath($document);
    $snapshot = $xpath->query('//*[@*[name()="wire:snapshot"]]')->item(0)->getAttribute('wire:snapshot');
    $endpoint = $xpath->query('//script[@data-update-uri]')->item(0)->getAttribute('data-update-uri');
    $response = $this->postJson($endpoint, ['components' => [[
        'snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'create', 'params' => []]],
    ]]], ['X-Livewire' => 'true'])->assertOk();
    $response = $this->postJson($endpoint, ['components' => [[
        'snapshot' => $response->json('components.0.snapshot'),
        'updates' => ['form.admission_round_id' => $program->admission_round_id, 'form.major_id' => $program->major_id, 'form.admission_program_id' => $program->id, 'form.is_selectable' => true],
        'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
    ]]], ['X-Livewire' => 'true'])->assertOk();
    $this->assertDatabaseHas('candidate_major_offerings', ['admission_program_id' => $program->id, 'admission_round_id' => $program->admission_round_id, 'major_id' => $program->major_id, 'is_selectable' => true]);
    Livewire::test(CandidateMajorOfferings::class)->call('create')
        ->set('form.admission_round_id', $program->admission_round_id)->set('form.major_id', $program->major_id)
        ->set('form.admission_program_id', $program->id)->set('form.major_id', Major::factory()->create()->id)
        ->assertSet('form.admission_program_id', null);
});

test('offering round filter rejects unknown values and switches data through HTTP hydration', function () {
    $first = CandidateMajorOffering::factory()->create();
    $second = CandidateMajorOffering::factory()->create();
    $empty = AdmissionRound::factory()->create();
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$this->get(route('admin.candidate-major-offerings.index'))->getContent());
    $xpath = new DOMXPath($document);
    $snapshot = $xpath->query('//*[@*[name()="wire:snapshot"]]')->item(0)->getAttribute('wire:snapshot');
    $endpoint = $xpath->query('//script[@data-update-uri]')->item(0)->getAttribute('data-update-uri');
    foreach ([[(string) $first->admission_round_id, [$first]], [(string) $second->admission_round_id, [$second]], [(string) $empty->id, []], ['', [$first, $second]]] as [$round, $included]) {
        $response = $this->postJson($endpoint, ['components' => [[
            'snapshot' => $snapshot, 'updates' => ['roundFilter' => $round], 'calls' => [],
        ]]], ['X-Livewire' => 'true'])->assertOk();
        $snapshot = $response->json('components.0.snapshot');
        expect(json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['data']['roundFilter'])->toBe($round);
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->json('components.0.effects.html'));
        $xpath = new DOMXPath($document);
        $ids = [];
        foreach ($xpath->query('//button[@*[name()="wire:click"]]') as $button) {
            if (preg_match('/\Aedit\((\d+)\)\z/', $button->getAttribute('wire:click'), $matches)) {
                $ids[] = (int) $matches[1];
            }
        }
        expect($ids)->toEqualCanonicalizing(array_map(fn ($offering) => $offering->id, $included));
    }
    $this->getJson(route('admin.candidate-major-offerings.index', ['roundFilter' => '999999']))
        ->assertOk()->assertSee('đợt tuyển sinh')->assertDontSee('edit('.$first->id.')', false)->assertDontSee('edit('.$second->id.')', false);
    Livewire::test(CandidateMajorOfferings::class)->set('roundFilter', '999999')->assertHasErrors('roundFilter')
        ->assertViewHas('records', fn ($records) => $records->total() === 0)
        ->call('clearFilters')->assertHasNoErrors()->assertViewHas('records', fn ($records) => $records->total() === 2);
});

test('invalid catalog status filters show validation with no unfiltered fallback and recover when cleared', function (string $component, string $model) {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $model::factory()->create();
    Livewire::test($component)->set('statusFilter', 'not-a-status')->assertHasErrors('statusFilter')
        ->assertViewHas('records', fn ($records) => $records->total() === 0)
        ->call('clearFilters')->assertHasNoErrors()->assertViewHas('records', fn ($records) => $records->total() === 1);
})->with([
    [Majors::class, Major::class],
    [AdmissionMethods::class, AdmissionMethod::class],
    [AdmissionPrograms::class, AdmissionProgram::class],
    [CandidateMajorOfferings::class, CandidateMajorOffering::class],
]);
