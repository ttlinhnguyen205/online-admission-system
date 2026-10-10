<?php

use App\Actions\AdmissionStatistics;
use App\Actions\CandidateFiles;
use App\Actions\NativeAllocationRun;
use App\Actions\NativeResultWorkflow;
use App\Livewire\Admin\AdmissionEngine;
use App\Livewire\Candidate\Results;
use App\Models\ActivityLog;
use App\Models\NativeAllocationPolicy;
use App\Models\NativeResultEntry;
use App\Models\NativeResultVersion;
use App\Models\User;
use App\Notifications\AdmissionResultsPublished;
use App\Support\AdmissionReportFilters;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../Fixtures/native-allocation.php';

/** @return list<array<string, mixed>> */
function mariaDbRace(array $fixture, array $operations): array
{
    $gate = storage_path('framework/testing/native-race-'.bin2hex(random_bytes(8)));
    $processes = [];
    foreach ($operations as $index => $operation) {
        $job = ['database' => DB::connection()->getDatabaseName(), 'round' => $fixture['round']->id, 'admin' => $fixture['admin']->id,
            'storage' => Storage::disk(CandidateFiles::DISK)->path(''), 'gate' => $gate, 'worker' => $index, ...$operation];
        file_put_contents($gate.'.job.'.$index, json_encode($job, JSON_THROW_ON_ERROR));
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/native-mariadb-worker.php'), $gate.'.job.'.$index], base_path(), ['APP_ENV' => 'testing']);
        $process->setTimeout(30)->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 20;
    foreach ($processes as $index => $process) {
        while (! file_exists($gate.'.ready.'.$index)) {
            if (! $process->isRunning() || microtime(true) > $deadline) {
                throw new RuntimeException('Worker initialization failed: '.$process->getErrorOutput().$process->getOutput());
            }
            usleep(10000);
        }
    }
    file_put_contents($gate.'.go', 'go');
    $responses = [];
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $responses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    expect(array_unique(array_column($responses, 'connection')))->toHaveCount(count($responses));
    if (count($responses) === 2) {
        expect(max(array_column($responses, 'started')))->toBeLessThan(min(array_column($responses, 'finished')));
        expect(abs($responses[0]['locked'] - $responses[1]['locked']))->toBeGreaterThan(0.20);
    }
    foreach (glob($gate.'.*') as $file) {
        unlink($file);
    }
    file_put_contents(storage_path('framework/testing/native-mariadb-races.jsonl'), json_encode(['database' => DB::connection()->getDatabaseName(), 'operations' => array_column($operations, 'operation'), 'responses' => $responses], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);

    return $responses;
}

test('MariaDB acceptance exercises committed native E2E and actual concurrent transactions', function () {
    $database = getenv('NATIVE_MARIADB_ACCEPTANCE_DATABASE');
    if (! $database) {
        test()->markTestSkipped('Requires an explicitly isolated MariaDB acceptance database.');
    }
    expect(preg_match('/^online_admission_acceptance_\d{8}_\d{6}$/D', $database))->toBe(1);
    expect(config('database.connections.mysql.host'))->toBeIn(['127.0.0.1', 'localhost', '::1']);
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database]);
    DB::purge('mysql');
    expect(DB::selectOne('SELECT VERSION() AS version')->version)->toContain('MariaDB');
    $legacyWishes = DB::table('admission_wishes')->count();
    $legacyResults = DB::table('admission_results')->count();
    $oldSnapshots = DB::table('application_submission_snapshots')->orderBy('id')->get()->toJson();
    $fixture = allocationFixture(true);
    $snapshot = $fixture['application']->submissionSnapshots()->sole();
    expect($fixture['policy']->fresh()->approved_at->lessThan($snapshot->sealed_at))->toBeTrue();
    expect($snapshot->entries()->count())->toBe(1);
    expect($snapshot->entries()->sole()->bindings()->count())->toBe(2);
    expect($fixture['application']->candidateProfile->examResults()->sole()->getRawOriginal('status'))->toBe('verified');
    expect($fixture['application']->candidateProfile->transcripts()->sole()->getRawOriginal('status'))->toBe('verified');
    expect(DB::table('native_method_evaluations')->whereIn('wish_method_binding_id', $snapshot->entries()->sole()->bindings()->pluck('id'))->pluck('status')->all())->toBe(['eligible', 'eligible']);
    $sealedHash = $snapshot->content_hash;
    $results = app(NativeResultWorkflow::class);
    $owner = $fixture['application']->candidateProfile->user;
    test()->actingAs($owner);
    expect(fn () => app(NativeAllocationRun::class)->preview($fixture['round']->id))->toThrow(HttpException::class);
    test()->actingAs($fixture['staff']);
    expect(fn () => app(NativeAllocationRun::class)->preview($fixture['round']->id))->toThrow(HttpException::class);
    test()->actingAs($fixture['admin']);
    $policyRace = mariaDbRace($fixture, [['operation' => 'policyDraft'], ['operation' => 'policyDraft']]);
    expect(array_column($policyRace, 'ok'))->toBe([true, true]);
    expect($policyRace[0]['id'])->toBe($policyRace[1]['id']);
    expect(NativeAllocationPolicy::where('admission_round_id', $fixture['round']->id)->where('status', 'draft')->count())->toBe(1);
    expect($fixture['policy']->fresh()->getRawOriginal('status'))->toBe('approved');
    $race = mariaDbRace($fixture, [['operation' => 'allocate'], ['operation' => 'allocate']]);
    expect(array_column($race, 'ok'))->toBe([true, true]);
    expect($race[0]['id'])->toBe($race[1]['id']);
    $version = NativeResultVersion::findOrFail($race[0]['id']);
    expect($version->entries()->count())->toBe(1);
    expect($results->check($version->id))->toBe([]);
    $filters = new AdmissionReportFilters(roundFilter: (string) $fixture['round']->id);
    expect(app(AdmissionStatistics::class)->nativeResults($fixture['staff'], $filters)->count())->toBe(0);
    $race = mariaDbRace($fixture, [['operation' => 'draft'], ['operation' => 'draft']]);
    expect(array_column($race, 'ok'))->toBe([true, true]);
    expect($race[0]['id'])->toBe($race[1]['id']);
    expect(NativeResultVersion::where('admission_round_id', $fixture['round']->id)->count())->toBe(2);
    $auditCount = ActivityLog::count();
    $rollback = mariaDbRace($fixture, [['operation' => 'rollback']]);
    expect($rollback[0]['ok'])->toBeFalse();
    expect($rollback[0]['message'])->toBe('Expected rollback probe');
    expect(NativeResultVersion::where('admission_round_id', $fixture['round']->id)->count())->toBe(2);
    expect(ActivityLog::count())->toBe($auditCount);
    expect(NativeResultEntry::visibleToCandidate($owner)->count())->toBe(0);
    $args = ['version' => $version->id, 'hash' => $version->content_hash];
    $binding = $snapshot->entries()->sole()->bindings()->firstOrFail()->id;
    $originalFingerprint = DB::table('native_method_evaluations')->where('wish_method_binding_id', $binding)->value('input_fingerprint');
    $race = mariaDbRace($fixture, [['operation' => 'stale', 'binding' => $binding], ['operation' => 'approve', ...$args]]);
    expect($race[0]['ok'])->toBeTrue();
    expect($results->check($version->id))->not->toBeEmpty();
    expect(fn () => $results->publish($version->id, $version->content_hash))->toThrow(ValidationException::class);
    DB::table('native_method_evaluations')->where('wish_method_binding_id', $binding)->update(['input_fingerprint' => $originalFingerprint]);
    $race = mariaDbRace($fixture, [['operation' => 'approve', ...$args], ['operation' => 'approve', ...$args]]);
    expect(array_column($race, 'ok'))->toBe([true, true]);
    $race = mariaDbRace($fixture, [['operation' => 'publish', ...$args], ['operation' => 'publish', ...$args]]);
    expect(array_column($race, 'ok'))->toBe([true, true]);
    expect($version->fresh()->status)->toBe('published');
    expect(DB::table('notifications')->where('notifiable_id', $owner->id)->where('type', AdmissionResultsPublished::class)->count())->toBe(1);
    expect(NativeResultEntry::visibleToCandidate($owner)->count())->toBe(1);
    $statistics = app(AdmissionStatistics::class);
    expect($statistics->build($fixture['admin'], $filters)['metrics']['Kết quả Native đã công bố'])->toBe(1);
    expect($statistics->nativeResults($fixture['staff'], $filters)->count())->toBe(1);
    expect(fn () => $statistics->nativeResults($owner, $filters))->toThrow(AuthorizationException::class);
    test()->actingAs($owner);
    Livewire\Livewire::test(Results::class)->assertStatus(200);
    foreach (['candidate.applications.index', 'candidate.admission-information.index', 'candidate.scores.index', 'candidate.results.index'] as $route) {
        test()->get(route($route))->assertOk();
    }
    test()->get(route('admin.results.index'))->assertForbidden();
    $other = User::factory()->create();
    test()->actingAs($other)->get(route('candidate.applications.show', $fixture['application']->id))->assertNotFound();
    test()->actingAs($fixture['staff']);
    test()->get(route('admin.applications.show', $fixture['application']->id))->assertOk();
    test()->get(route('admin.results.index'))->assertForbidden();
    test()->get(route('admin.admission-engine'))->assertForbidden();
    $download = test()->get(route('admin.reports.download', ['format' => 'xlsx', 'roundFilter' => $fixture['round']->id]))->assertOk()->streamedContent();
    expect(substr($download, 0, 2))->toBe('PK');
    test()->actingAs($fixture['admin']);
    Livewire\Livewire::test(App\Livewire\Admin\Results::class)->set('roundSelection', (string) $fixture['round']->id)->assertStatus(200);
    Livewire\Livewire::test(AdmissionEngine::class)->assertStatus(200);
    foreach (['dashboard', 'admin.admission-rounds.index', 'admin.admission-programs.index', 'admin.candidate-major-offerings.index', 'admin.applications.index', 'admin.results.index'] as $route) {
        test()->get(route($route))->assertOk();
    }
    $pdf = test()->get(route('admin.reports.download', ['format' => 'pdf', 'roundFilter' => $fixture['round']->id]))->assertOk()->streamedContent();
    expect($pdf)->toStartWith('%PDF-');
    expect(ActivityLog::where('subject_type', $version->getMorphClass())->where('subject_id', $version->id)->where('action', 'native_results.approved')->count())->toBe(1);
    expect(ActivityLog::where('subject_type', $version->getMorphClass())->where('subject_id', $version->id)->where('action', 'native_results.published')->count())->toBe(1);
    expect($snapshot->fresh()->content_hash)->toBe($sealedHash);
    expect(DB::table('admission_wishes')->count())->toBe($legacyWishes);
    expect(DB::table('admission_results')->count())->toBe($legacyResults);
    expect(DB::table('application_submission_snapshots')->where('id', '<', $snapshot->id)->orderBy('id')->get()->toJson())->toBe($oldSnapshots);
})->group('mariadb-acceptance');
