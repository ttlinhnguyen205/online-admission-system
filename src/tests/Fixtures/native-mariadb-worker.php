<?php

use App\Actions\AdmissionCatalogLock;
use App\Actions\NativeAllocationPolicyManagement;
use App\Actions\NativeAllocationRun;
use App\Actions\NativeDeferredAcceptance;
use App\Actions\NativeResultWorkflow;
use App\Models\NativeAllocationPolicy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\Concerns\InteractsWithTime;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$job = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/^online_admission_acceptance_\d{8}_\d{6}$/D', $job['database'])
    || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Acceptance database guard failed');
}
config(['database.default' => 'mysql', 'database.connections.mysql.database' => $job['database'], 'filesystems.disks.candidate-private.root' => $job['storage']]);
DB::purge('mysql');
auth()->loginUsingId($job['admin']);
$clock = new class
{
    use InteractsWithTime;
};
$clock->travelTo('2026-10-12 12:00:00');
$connection = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($job['gate'].'.ready.'.$job['worker'], 'ready');
$deadline = microtime(true) + 20;
while (! file_exists($job['gate'].'.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out');
    }
    usleep(10000);
}
$started = microtime(true);
$locked = null;
try {
    $id = DB::transaction(function () use ($job, &$locked): ?int {
        AdmissionCatalogLock::acquire();
        $locked = microtime(true);
        usleep(250000);
        $results = app(NativeResultWorkflow::class);
        if ($job['operation'] === 'policyDraft') {
            $payload = NativeAllocationPolicy::query()->where('admission_round_id', $job['round'])->where('status', 'approved')->sole()->getAttribute('payload');
            $payload['policy_reference'] = 'Concurrent policy draft probe';

            return app(NativeAllocationPolicyManagement::class)->createDraft($job['round'], $payload)->id;
        }
        if ($job['operation'] === 'allocate') {
            return app(NativeAllocationRun::class)->run($job['round'], $results)->id;
        }
        if (in_array($job['operation'], ['draft', 'rollback'], true)) {
            $preview = app(NativeAllocationRun::class)->preview($job['round']);
            $version = $results->createDraft($job['round'], $preview['decisions'], NativeDeferredAcceptance::ALGORITHM, $preview['policy_reference'], $job['operation'] === 'rollback' ? 'Rollback probe' : 'Concurrent draft probe');
            if ($job['operation'] === 'rollback') {
                throw new RuntimeException('Expected rollback probe');
            }

            return $version->id;
        }
        if ($job['operation'] === 'stale') {
            DB::table('native_method_evaluations')->where('wish_method_binding_id', $job['binding'])->update(['input_fingerprint' => str_repeat('0', 64)]);

            return null;
        }
        if ($job['operation'] === 'approve') {
            $results->approve($job['version'], $job['hash']);
        } elseif ($job['operation'] === 'publish') {
            $results->publish($job['version'], $job['hash']);
        } else {
            throw new RuntimeException('Unknown acceptance operation');
        }

        return $job['version'];
    });
    echo json_encode(['ok' => true, 'id' => $id, 'connection' => $connection, 'started' => $started, 'locked' => $locked, 'finished' => microtime(true)], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'type' => get_class($exception), 'message' => $exception->getMessage(), 'connection' => $connection, 'started' => $started, 'locked' => $locked, 'finished' => microtime(true)], JSON_THROW_ON_ERROR);
}
