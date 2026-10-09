<?php

use App\Livewire\Admin\Dashboard as ReviewDashboard;
use App\Livewire\Candidate\Dashboard as CandidateDashboard;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard hydration snapshot updates round state through the HTTP Livewire endpoint', function () {
    $first = Application::factory()->create(['status' => 'submitted']);
    $second = Application::factory()->create(['status' => 'needs_revision']);
    foreach (range(1, 3) as $priority) {
        AdmissionWish::factory()->create(['application_id' => $first->id, 'priority' => $priority]);
    }
    AdmissionWish::factory()->create(['application_id' => $second->id]);
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$this->get(route('dashboard'))->getContent());
    $xpath = new DOMXPath($document);
    $snapshot = $xpath->query('//*[@*[name()="wire:snapshot"]]')->item(0)->getAttribute('wire:snapshot');
    $componentId = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo']['id'];
    $endpoint = $xpath->query('//script[@data-update-uri]')->item(0)->getAttribute('data-update-uri');

    foreach ([[(string) $first->admission_round_id, 3, $first, $second], [(string) $second->admission_round_id, 1, $second, $first]] as [$round, $wishes, $included, $excluded]) {
        $response = $this->postJson($endpoint, ['components' => [[
            'snapshot' => $snapshot, 'updates' => ['roundFilter' => $round], 'calls' => [],
        ]]], ['X-Livewire' => 'true'])->assertOk();
        $snapshot = $response->json('components.0.snapshot');
        expect(json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['data']['roundFilter'])->toBe($round);
        expect($response->json('components.0.effects.html'))->toContain('Tỷ lệ trên 1 hồ sơ đã nộp', 'Tỷ lệ trên '.$wishes.' nguyện vọng', $included->application_code)
            ->not->toContain($excluded->application_code);
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->json('components.0.effects.html'));
        $xpath = new DOMXPath($document);
        $roots = $xpath->query('//*[@*[name()="wire:id"]]');
        expect($roots->length)->toBe(1)
            ->and($roots->item(0)->tagName)->toBe('div')
            ->and($roots->item(0)->getAttribute('wire:id'))->toBe($componentId)
            ->and(json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo']['id'])->toBe($componentId);
        $controls = [];
        foreach (['yearFilter', 'roundFilter', 'statusFilter', 'search'] as $filter) {
            $controls[$filter] = $xpath->query('//*[@*[name()="wire:id"]]//*[@name="'.$filter.'"]')->length;
        }
        expect($controls)->toBe(['yearFilter' => 1, 'roundFilter' => 1, 'statusFilter' => 1, 'search' => 1]);
    }
});

test('dashboard response mounts its role dashboard with hydration data and Livewire assets', function (string $role, string $component) {
    $this->actingAs(User::factory()->create(['role' => $role]));

    $response = $this->get(route('dashboard'))->assertOk()->assertSeeLivewire($component);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $roots = $xpath->query('//*[@*[name()="wire:id"] and @*[name()="wire:snapshot"]]');

    expect($roots->length)->toBe(1);
    expect($xpath->query('//*[@*[name()="wire:id"]]')->length)->toBe(1);
    if ($role === 'admin') {
        foreach (['yearFilter', 'roundFilter', 'statusFilter', 'search'] as $filter) {
            expect($xpath->query('//*[@*[name()="wire:id"]]//*[@name="'.$filter.'"]')->length)->toBe(1);
        }
    }
    $snapshot = json_decode($roots->item(0)->getAttribute('wire:snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot['memo']['name'])->toBe($role === 'candidate' ? 'candidate.dashboard' : 'admin.dashboard');
    expect($xpath->query('//script[@data-update-uri]')->length)->toBe(1);
    $script = $xpath->query('//script[@data-update-uri]')->item(0);
    $this->get($script->getAttribute('src'))->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
})->with([
    'admin' => ['admin', ReviewDashboard::class],
    'staff' => ['staff', ReviewDashboard::class],
    'candidate' => ['candidate', CandidateDashboard::class],
]);
