<?php

use App\Actions\AdmissionStatistics;
use App\Actions\NativeAllocationRun;
use App\Actions\NativeResultWorkflow;
use App\Models\AdmissionRound;
use App\Support\AdmissionReportFilters;
use App\Support\AdmissionReportWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Fixtures/native-allocation.php';

/** @return array<string, string> */
function nativeReportWorkbook(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    try {
        return ['wishes' => $zip->getFromName('xl/worksheets/sheet3.xml'), 'results' => $zip->getFromName('xl/worksheets/sheet4.xml')];
    } finally {
        $zip->close();
    }
}

test('native published results and one multi method wish appear in scoped exports without draft leakage', function () {
    $fixture = allocationFixture(true);
    $filters = new AdmissionReportFilters(roundFilter: (string) $fixture['round']->id);
    $statistics = app(AdmissionStatistics::class);
    $results = app(NativeResultWorkflow::class);
    $version = app(NativeAllocationRun::class)->run($fixture['round']->id, $results);
    $path = Storage::disk('candidate-private')->path('native-report.xlsx');
    $writer = app(AdmissionReportWriter::class);
    foreach ([$fixture['admin'], $fixture['staff']] as $actor) {
        $writer->write($actor, $filters, 'xlsx', $path);
        $sheets = nativeReportWorkbook($path);
        expect($sheets['results'])->not->toContain($fixture['application']->application_code);
        expect(substr_count($sheets['wishes'], $fixture['application']->application_code))->toBe(1);
        expect($sheets['wishes'])->toContain($fixture['thpt']->admissionMethod->name, $fixture['transcript']->admissionMethod->name);
        expect($statistics->build($actor, $filters)['metrics']['Kết quả Native đã công bố'])->toBe(0);
    }
    $results->approve($version->id, $version->content_hash);
    expect($statistics->nativeResults($fixture['staff'], $filters)->count())->toBe(0);
    $results->publish($version->id, $version->content_hash);
    foreach ([$fixture['admin'], $fixture['staff']] as $actor) {
        $writer->write($actor, $filters, 'xlsx', $path);
        expect(nativeReportWorkbook($path)['results'])->toContain($fixture['application']->application_code, $fixture['thpt']->admissionMethod->name, '24');
        expect($statistics->build($actor, $filters)['metrics']['Kết quả Native đã công bố'])->toBe(1);
    }
    expect(fn () => $statistics->nativeResults($fixture['application']->candidateProfile->user, $filters))->toThrow(AuthorizationException::class);
    $other = AdmissionRound::factory()->create();
    $writer->write($fixture['admin'], new AdmissionReportFilters(roundFilter: (string) $other->id), 'xlsx', $path);
    expect(nativeReportWorkbook($path)['results'])->not->toContain($fixture['application']->application_code);
    DB::table('native_result_versions')->where('id', $version->id)->update(['approved_by' => null]);
    expect($statistics->nativeResults($fixture['staff'], $filters)->count())->toBe(0);
});

test('native report rows participate in export limits', function () {
    $fixture = allocationFixture();
    config(['admission_reports.xlsx_max_rows' => 1]);
    expect(fn () => app(AdmissionReportWriter::class)->write($fixture['admin'], new AdmissionReportFilters(roundFilter: (string) $fixture['round']->id), 'xlsx', Storage::disk('candidate-private')->path('limited.xlsx')))->toThrow(ValidationException::class);
});
