<?php

use App\Actions\NativeRoundScoringReport;
use App\Enums\UserRole;
use App\Livewire\Admin\AdmissionEngine;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('native preview and export are read only and refuse allocation without policy', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $round = AdmissionRound::factory()->create(['native_registration_state' => 'native_open']);
    Application::factory()->for($round)->create(['registration_mode' => 'native']);

    Livewire::test(AdmissionEngine::class)->set('roundSelection', (string) $round->id)->call('preview')
        ->assertSee('Báo cáo Native')->assertSee('BLOCKED')
        ->call('exportNative')->assertFileDownloaded('native-scoring-round-'.$round->id.'.csv')
        ->call('confirm')->assertHasErrors('engine');

    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('native_method_evaluations', 0);
    $this->assertDatabaseCount('activity_logs', 0);
    expect($round->fresh()->nativeRegistrationState())->toBe('native_open');
});

test('legacy records in native round are reported without conversion', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $round = AdmissionRound::factory()->create(['native_registration_state' => 'native_draft']);
    $application = Application::factory()->for($round)->create();

    $report = app(NativeRoundScoringReport::class)->build($round->id);

    expect($report['blockers'])->toContain('Có hồ sơ legacy trong đợt Native.');
    expect($application->fresh()->registration_mode)->toBe('legacy');
});

test('candidate and staff cannot read or export native engine report', function (UserRole $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $round = AdmissionRound::factory()->create(['native_registration_state' => 'native_closed']);

    expect(fn () => app(NativeRoundScoringReport::class)->build($round->id))->toThrow(HttpException::class);
    Livewire::test(AdmissionEngine::class)->assertForbidden();
})->with([UserRole::Candidate, UserRole::Staff]);

test('legacy round cannot enter native report path', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $round = AdmissionRound::factory()->create();

    expect(fn () => app(NativeRoundScoringReport::class)->build($round->id))->toThrow(HttpException::class);
});
