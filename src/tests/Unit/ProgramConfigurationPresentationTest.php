<?php

use App\Actions\NativeRegistrationReadiness;
use App\Livewire\Admin\AdmissionPrograms;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\EvaluationRuleVersion;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
        throw new RuntimeException('Only isolated SQLite memory is allowed.');
    }
    $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
});

test('legacy inactive programs do not require native rules or change their state', function (string $code) {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $round = AdmissionRound::factory()->create(['code' => 'RAC26-PLAN', 'status' => 'draft', 'native_registration_state' => 'legacy']);
    $method = AdmissionMethod::factory()->create(['code' => $code]);
    $program = AdmissionProgram::factory()->for($round)->for($method)->create(['status' => 'inactive']);
    $before = $program->fresh()->getRawOriginal();
    $this->mock(NativeRegistrationReadiness::class)->shouldNotReceive('programReady');
    Livewire::test(AdmissionPrograms::class)->assertSee('Cấu hình Legacy')->assertSee('Không hoạt động')
        ->assertDontSee('Thiếu quy tắc đã duyệt hợp lệ')->assertDontSee('wire:click="manageRules('.$program->id.')"', false)
        ->assertViewHas('ruleReadiness', fn ($values) => $values[$program->id] === null);
    expect($program->fresh()->getRawOriginal())->toBe($before);
    expect($round->fresh()->status->value)->toBe('draft');
    $this->assertDatabaseCount('activity_logs', 0);
})->with(['DEMO-THPT-A00', 'DEMO-HB-D01']);

test('native programs retain approved rule checks and configuration actions', function (string $ruleState) {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin);
    $round = AdmissionRound::factory()->create(['native_registration_state' => 'native_draft']);
    $program = AdmissionProgram::factory()->for($round)->create();
    if ($ruleState !== 'missing') {
        $rule = EvaluationRuleVersion::factory()->create(['admission_method_id' => $program->admission_method_id,
            'status' => $ruleState === 'draft' ? 'draft' : 'approved', 'approved_by' => $admin->id, 'approved_at' => now(),
            ...($ruleState === 'bad_hash' ? ['content_hash' => str_repeat('0', 64)] : [])]);
        $program->evaluationRule()->associate($rule);
        $program->save();
    }
    $page = Livewire::test(AdmissionPrograms::class)->assertDontSee('Cấu hình Legacy')
        ->assertSee('wire:click="manageRules('.$program->id.')"', false);
    if ($ruleState === 'approved') {
        $page->assertSee('Quy tắc hợp lệ')->assertDontSee('Thiếu quy tắc đã duyệt hợp lệ');
    } else {
        $page->assertSee('Thiếu quy tắc đã duyệt hợp lệ');
    }
    $page->call('manageRules', $program->id)->assertSee('Quản lý quy tắc đánh giá');
    $this->assertDatabaseCount('activity_logs', 0);
})->with(['missing', 'draft', 'approved', 'bad_hash']);

test('program numbering follows pagination and filtered results', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $round = AdmissionRound::factory()->create(['code' => 'ONLY-THIS-ROUND']);
    AdmissionProgram::factory()->count(16)->for($round)->create();
    Livewire::test(AdmissionPrograms::class)->assertSee('data-program-stt>1</span>', false)
        ->call('setPage', 2)->assertSee('data-program-stt>16</span>', false)
        ->set('search', 'ONLY-THIS-ROUND')->assertSee('data-program-stt>1</span>', false)
        ->set('statusFilter', 'inactive')->assertSee('Không tìm thấy chương trình tuyển sinh');
});
