<?php

use App\Livewire\Admin\AdmissionEngine;
use App\Livewire\Admin\ReviewHistory;
use App\Models\ActivityLog;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Tests\TestCase;

pest()->extend(TestCase::class);
require_once __DIR__.'/../Feature/AdmissionEngineFixtures.php';

beforeEach(function () {
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
        throw new RuntimeException('Only isolated SQLite memory is allowed.');
    }
    $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
});

test('history renders Vietnamese audit snapshots safely without changing logs', function (string $role) {
    $actor = User::factory()->create(['name' => 'Nguyễn Nhân Viên', 'role' => 'staff']);
    $application = Application::factory()->create(['application_code' => 'HS-2026-001', 'status' => 'completed']);
    $log = ActivityLog::factory()->create(['user_id' => $actor->id, 'subject_type' => $application->getMorphClass(), 'subject_id' => $application->id,
        'action' => 'application.revision_requested', 'created_at' => '2026-10-24 09:15:00',
        'new_values' => ['status' => 'needs_revision', 'revision_reason' => '<script>private</script>', 'password' => 'SECRET-HIDDEN'], 'ip_address' => '10.20.30.40']);
    $before = $log->fresh()->getRawOriginal();
    $this->actingAs(User::factory()->create(['role' => $role]));
    Model::preventLazyLoading();
    try {
        Livewire::test(ReviewHistory::class)->assertSee('Nguyễn Nhân Viên')->assertSee('Nhân viên')->assertSee('24/10/2026 09:15')
            ->assertSee('HS-2026-001')->assertSee('Cần bổ sung')->assertSee('Yêu cầu bổ sung')
            ->assertDontSee('<script>private</script>', false)->assertDontSee('SECRET-HIDDEN')->assertDontSee('10.20.30.40');
    } finally {
        Model::preventLazyLoading(false);
    }
    expect($log->fresh()->getRawOriginal())->toBe($before);
    $this->get(route('admin.review-history.index'))->assertOk();
})->with(['admin', 'staff']);

test('history search action dates pagination and reset preserve scope', function () {
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    $application = Application::factory()->create(['application_code' => 'TARGET-HS']);
    $actor = User::factory()->create(['name' => 'Người xử lý mục tiêu']);
    ActivityLog::factory()->count(16)->create(['user_id' => $actor->id, 'subject_type' => $application->getMorphClass(), 'subject_id' => $application->id,
        'action' => 'application.verified', 'created_at' => '2026-10-24 23:59:59', 'new_values' => ['status' => 'verified']]);
    ActivityLog::factory()->create(['action' => 'internal.secret', 'new_values' => ['password' => 'DO-NOT-SHOW']]);
    $page = Livewire::test(ReviewHistory::class)->assertViewHas('records', fn ($rows) => $rows->total() === 16 && $rows->count() === 15)
        ->call('setPage', 2)->assertViewHas('records', fn ($rows) => $rows->count() === 1)
        ->assertSee('class="table-stt">16</td>', false)
        ->set('search', 'TARGET-HS')->assertViewHas('records', fn ($rows) => $rows->currentPage() === 1 && $rows->total() === 16)
        ->assertSee('class="table-stt">1</td>', false)
        ->set('search', 'Người xử lý mục tiêu')->assertViewHas('records', fn ($rows) => $rows->total() === 16)
        ->set('statusFilter', 'needs_revision')->assertViewHas('records', fn ($rows) => $rows->total() === 0)
        ->set('statusFilter', 'verified')->assertViewHas('records', fn ($rows) => $rows->total() === 16)
        ->set('dateFrom', '2026-10-24')->set('dateTo', '2026-10-24')->assertViewHas('records', fn ($rows) => $rows->total() === 16)
        ->set('actionFilter', 'application.rejected')->assertSee('Chưa có lịch sử xử lý phù hợp.')
        ->call('clearFilters')->assertSet('dateFrom', '')->assertViewHas('records', fn ($rows) => $rows->total() === 16)
        ->set('dateFrom', '2026-10-25')->assertViewHas('records', fn ($rows) => $rows->total() === 0)
        ->set('dateTo', '2026-10-24')->assertSee('Ngày kết thúc phải từ ngày bắt đầu trở đi.')
        ->set('dateFrom', 'invalid')->assertSee('Ngày phải có định dạng năm-tháng-ngày.')
        ->assertDontSee('DO-NOT-SHOW');
    $this->assertDatabaseCount('activity_logs', 17);
});

test('missing actor subject and result have honest fallback labels', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    ActivityLog::factory()->create(['user_id' => null, 'subject_type' => (new Application)->getMorphClass(), 'subject_id' => 99999,
        'action' => 'application.review_started', 'new_values' => null]);
    Livewire::test(ReviewHistory::class)->assertSee('Không có thông tin người xử lý')->assertSee('Hồ sơ không còn tồn tại')->assertSee('Không có thông tin');
});

test('history denies guests candidates and inactive reviewers', function () {
    $this->get(route('admin.review-history.index'))->assertRedirect(route('login'));
    foreach ([['role' => 'candidate'], ['role' => 'staff', 'status' => 'inactive'], ['role' => 'admin', 'status' => 'locked']] as $attributes) {
        $this->actingAs(User::factory()->create($attributes));
        $this->get(route('admin.review-history.index'))->assertForbidden();
        Livewire::test(ReviewHistory::class)->assertForbidden();
    }
    $this->actingAs(User::factory()->unverified()->create(['role' => 'staff']));
    $this->get(route('admin.review-history.index'))->assertRedirect(route('verification.notice'));
});

test('engine still previews confirms processes and restricts staff after presentation changes', function () {
    $program = engineProgram();
    engineApplication($program);
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    Livewire::test(AdmissionEngine::class)->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    Livewire::test(AdmissionEngine::class)->assertSee('Công cụ xét tuyển')
        ->set('roundSelection', (string) $program->admission_round_id)->call('preview')->assertHasNoErrors()
        ->assertSee($program->major->name)->assertSee($program->admissionMethod->name)
        ->assertSee('STT')->assertSee($program->admissionMethod->code)
        ->call('confirm')->assertSet('showConfirmation', true)->call('process')->assertHasNoErrors()
        ->assertSet('summary.admitted', 1)->assertSet('showConfirmation', false);
    $this->assertDatabaseCount('admission_results', 1);
});

test('native report retains read only export and allocation blocker', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $round = AdmissionRound::factory()->create(['native_registration_state' => 'native_open']);
    Application::factory()->for($round)->create(['registration_mode' => 'native']);
    Livewire::test(AdmissionEngine::class)->set('roundSelection', (string) $round->id)->call('preview')
        ->assertSee('Báo cáo xét tuyển theo ngành')->assertSee('Chưa thể phân bổ')->assertSee('Chờ dữ liệu')
        ->call('exportNative')->assertFileDownloaded('native-scoring-round-'.$round->id.'.csv')
        ->call('confirm')->assertHasErrors('engine');
    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('activity_logs', 0);
});
