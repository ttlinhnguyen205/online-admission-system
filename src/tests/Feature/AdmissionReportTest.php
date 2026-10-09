<?php

use App\Enums\UserRole;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\User;
use App\Support\AdmissionReportFilters;
use App\Support\AdmissionReportWriter;
use Illuminate\Routing\Exceptions\StreamedResponseException;
use Illuminate\Support\Facades\Storage;

/** @return array<string, string> */
function admissionWorkbookFiles(string $content): array
{
    $path = tempnam(sys_get_temp_dir(), 'admission-test-');
    file_put_contents($path, $content);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    try {
        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $files[$name] = $zip->getFromIndex($index);
        }

        return $files;
    } finally {
        $zip->close();
        unlink($path);
    }
}

function admissionPdfText(string $content): string
{
    preg_match_all('/<<([^>]+)>>\s*stream\r?\n(.*?)\r?\nendstream/s', $content, $streams, PREG_SET_ORDER);
    $text = '';
    foreach ($streams as $stream) {
        $data = str_contains($stream[1], '/FlateDecode') ? zlib_decode($stream[2]) : $stream[2];
        expect($data)->toBeString();
        preg_match_all('/\[\((.*?)\)\] TJ/s', $data, $strings);
        foreach ($strings[1] as $string) {
            $bytes = strtr($string, ['\\\\' => '\\', '\\(' => '(', '\\)' => ')', '\\r' => "\r"]);
            $text .= mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE')."\n";
        }
    }

    return $text;
}

beforeEach(function () {
    Storage::fake('candidate-private');
});

test('exports deny guests candidates inactive accounts and unverified reviewers', function (string $account, string $format) {
    if ($account !== 'guest') {
        $user = User::factory()->create(['role' => $account === 'candidate' ? 'candidate' : 'staff',
            'status' => in_array($account, ['inactive', 'locked'], true) ? $account : 'active',
            'email_verified_at' => $account === 'unverified' ? null : now()]);
        $this->actingAs($user);
    }
    $response = $this->get(route('admin.reports.download', $format));
    if (in_array($account, ['candidate', 'inactive', 'locked'], true)) {
        $response->assertForbidden();
    } else {
        $response->assertRedirect();
    }
    expect(Storage::disk('candidate-private')->allFiles())->toBe([]);
})->with(['guest', 'candidate', 'inactive', 'locked', 'unverified'])->with(['xlsx', 'pdf']);

test('excel contains Vietnamese headers real values styles and literal strings without private fields', function () {
    config(['admission_reports.chunk_size' => 1]);
    $this->freezeTime();
    $application = Application::factory()->create(['application_code' => '000123', 'status' => 'completed', 'submitted_at' => now()]);
    $application->candidateProfile->update(['phone' => 'PRIVATE-PHONE', 'citizen_id' => 'PRIVATE-CITIZEN']);
    $application->candidateProfile->user->update(['name' => '=HYPERLINK("https://example.test")', 'email' => 'private-email@example.test']);
    $application->admissionRound->update(['name' => 'Đợt một', 'status' => 'published']);
    $wish = AdmissionWish::factory()->create(['application_id' => $application->id]);
    $wish->admissionProgram->major->update(['name' => 'Công nghệ thông tin']);
    AdmissionResult::factory()->create(['admission_wish_id' => $wish->id, 'decision' => 'admitted', 'published_at' => now(), 'rank' => 1]);
    $draft = Application::factory()->create(['status' => 'draft']);
    AdmissionWish::factory()->create(['application_id' => $draft->id]);
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $response = $this->get(route('admin.reports.download', 'xlsx'))->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $files = admissionWorkbookFiles($response->streamedContent());
    expect($files['xl/workbook.xml'])->toContain('Tổng quan', 'Hồ sơ', 'Nguyện vọng', 'Kết quả');
    $applications = $files['xl/worksheets/sheet2.xml'];
    expect($applications)->toContain('Mã hồ sơ', 'Họ và tên', 'Đợt một', '000123', '=HYPERLINK', 't="inlineStr"', 'Hoàn tất xét tuyển')
        ->not->toContain('<f>', 'PRIVATE-PHONE', 'PRIVATE-CITIZEN', 'private-email@example.test', $draft->application_code);
    expect($files['xl/worksheets/sheet3.xml'])->toContain('Thứ tự nguyện vọng', 'Công nghệ thông tin');
    expect($files['xl/worksheets/sheet4.xml'])->toContain('Điểm xét tuyển', 'Trúng tuyển', '<v>25.5</v>');
    expect($files['xl/styles.xml'])->toContain('<b', 'wrapText="1"', 'E2E8F0');
    expect(Storage::disk('candidate-private')->allFiles())->toBe([])
        ->and(Storage::disk('candidate-private')->directories('reports'))->toBe([]);
});

test('excel applies round status and search to all sheets and aggregates', function () {
    $target = Application::factory()->create(['status' => 'verified', 'application_code' => 'EXPORT-TARGET']);
    $other = Application::factory()->create(['status' => 'verified']);
    $wrongStatus = Application::factory()->create(['status' => 'submitted', 'admission_round_id' => $target->admission_round_id]);
    $wrongSearch = Application::factory()->create(['status' => 'verified', 'admission_round_id' => $target->admission_round_id]);
    foreach ([$target, $other, $wrongStatus, $wrongSearch] as $application) {
        $wish = AdmissionWish::factory()->create(['application_id' => $application->id]);
        AdmissionResult::factory()->create(['admission_wish_id' => $wish->id]);
    }
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $response = $this->get(route('admin.reports.download', ['format' => 'xlsx', 'roundFilter' => $target->admission_round_id, 'statusFilter' => 'verified', 'search' => 'EXPORT-TARGET']))->assertOk();
    $files = admissionWorkbookFiles($response->streamedContent());
    foreach ([2, 3, 4] as $sheet) {
        expect($files['xl/worksheets/sheet'.$sheet.'.xml'])->toContain('EXPORT-TARGET')
            ->not->toContain($other->application_code, $wrongStatus->application_code, $wrongSearch->application_code);
    }
    expect($files['xl/worksheets/sheet1.xml'])->toContain('Đã xác minh', 'EXPORT-TARGET');
    expect($files['xl/worksheets/sheet4.xml'])->toContain('Chưa công bố');
});

test('staff exports omit unpublished future and closed-round results', function (UserRole $role) {
    $this->freezeTime();
    foreach (['PUBLIC-RESULT', 'INTERNAL-RESULT', 'FUTURE-RESULT', 'CLOSED-RESULT'] as $code) {
        $application = Application::factory()->create(['status' => 'completed', 'application_code' => $code]);
        $application->admissionRound->update(['status' => $code === 'CLOSED-RESULT' ? 'closed' : 'published']);
        $wish = AdmissionWish::factory()->create(['application_id' => $application->id]);
        AdmissionResult::factory()->create(['admission_wish_id' => $wish->id, 'published_at' => match ($code) {
            'INTERNAL-RESULT' => null, 'FUTURE-RESULT' => now()->addDay(), default => now(),
        }]);
    }
    $this->actingAs(User::factory()->create(['role' => $role]));
    $files = admissionWorkbookFiles($this->get(route('admin.reports.download', 'xlsx'))->assertOk()->streamedContent());
    $results = $files['xl/worksheets/sheet4.xml'];
    expect($results)->toContain('PUBLIC-RESULT');
    if ($role === UserRole::Staff) {
        expect($results)->not->toContain('INTERNAL-RESULT', 'FUTURE-RESULT', 'CLOSED-RESULT');
    } else {
        expect($results)->toContain('INTERNAL-RESULT', 'FUTURE-RESULT', 'CLOSED-RESULT', 'Chưa công bố');
    }
})->with([UserRole::Staff, UserRole::Admin]);

test('excel streams all rows across query chunks', function () {
    config(['admission_reports.chunk_size' => 2]);
    $applications = Application::factory()->count(7)->create(['status' => 'submitted']);
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    $files = admissionWorkbookFiles($this->get(route('admin.reports.download', 'xlsx'))->assertOk()->streamedContent());
    foreach ($applications as $application) {
        expect($files['xl/worksheets/sheet2.xml'])->toContain($application->application_code);
    }
    expect(substr_count($files['xl/worksheets/sheet2.xml'], '<row '))->toBe(8);
});

test('empty excel has headers and clear empty messages', function () {
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    $files = admissionWorkbookFiles($this->get(route('admin.reports.download', 'xlsx'))->assertOk()->streamedContent());
    foreach ([2, 3, 4] as $sheet) {
        expect($files['xl/worksheets/sheet'.$sheet.'.xml'])->toContain('Mã hồ sơ', 'Không có dữ liệu phù hợp.');
    }
});

test('exports fail closed for malformed unknown and draft filters', function (array $filters, string $format) {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->getJson(route('admin.reports.download', ['format' => $format, ...$filters]))->assertUnprocessable();
    expect(Storage::disk('candidate-private')->allFiles())->toBe([]);
})->with([[['statusFilter' => 'draft']], [['statusFilter' => 'approved']], [['roundFilter' => 999999]], [['roundFilter' => ['1']]], [['search' => ['x']]], [['search' => str_repeat('x', 101)]], [['candidate_profile_id' => 1]]])->with(['xlsx', 'pdf']);

test('unknown export formats return not found', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->get(route('admin.reports.download', 'csv'))->assertNotFound();
});

test('bounded exports reject oversized requests and remove temporary files', function (string $format) {
    config(['admission_reports.'.$format.'_max_rows' => 2]);
    Application::factory()->count(3)->create(['status' => 'submitted']);
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->getJson(route('admin.reports.download', $format))->assertUnprocessable()->assertJsonValidationErrors('export');
    expect(Storage::disk('candidate-private')->allFiles())->toBe([])
        ->and(Storage::disk('candidate-private')->directories('reports'))->toBe([]);
})->with(['xlsx', 'pdf']);

test('export cleanup runs when file generation fails', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->mock(AdmissionReportWriter::class)->shouldReceive('write')->once()->andThrow(new RuntimeException('Generation failed'));
    $this->withoutExceptionHandling();
    expect(fn () => $this->get(route('admin.reports.download', 'xlsx')))->toThrow(RuntimeException::class);
    expect(Storage::disk('candidate-private')->allFiles())->toBe([])
        ->and(Storage::disk('candidate-private')->directories('reports'))->toBe([]);
});

test('download rechecks account and role before streaming and cleans up denied files', function (array $changes) {
    $this->actingAs($user = User::factory()->create(['role' => 'admin']));
    $response = $this->get(route('admin.reports.download', 'xlsx'))->assertOk();
    expect(Storage::disk('candidate-private')->allFiles())->not->toBe([]);
    User::whereKey($user->id)->update($changes);
    expect($response->baseResponse->getCallback())->toThrow(StreamedResponseException::class);
    expect(Storage::disk('candidate-private')->allFiles())->toBe([]);
})->with([[['role' => 'staff']], [['role' => 'candidate']], [['status' => 'locked']], [['email_verified_at' => null]]]);

test('pdf is a valid Unicode report with selected filters and private download headers', function (bool $empty) {
    $this->freezeTime();
    $round = AdmissionRound::factory()->create(['name' => 'Đợt tuyển sinh tiếng Việt']);
    if (! $empty) {
        $application = Application::factory()->create(['status' => 'submitted', 'admission_round_id' => $round->id]);
        $application->candidateProfile->user->update(['name' => 'Nguyễn Thị Ánh']);
    }
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    $response = $this->get(route('admin.reports.download', ['format' => 'pdf', 'roundFilter' => $round->id, 'statusFilter' => 'submitted']))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $content = $response->streamedContent();
    expect($content)->toStartWith('%PDF-')->toContain('%%EOF', '/ToUnicode', 'DejaVuSans');
    $text = admissionPdfText($content);
    expect($text)->toContain('BÁO CÁO TUYỂN SINH', 'Đợt tuyển sinh tiếng Việt', 'Đã nộp', now()->format('d/m/Y H:i:s'), 'Mã hồ sơ');
    if ($empty) {
        expect($text)->toContain('Không có dữ liệu phù hợp.');
    } else {
        expect($text)->toContain('Nguyễn Thị Ánh', $application->application_code);
    }
    expect(Storage::disk('candidate-private')->allFiles())->toBe([]);
})->with([true, false]);

test('export rate limit bounds expensive generation per authorized account', function () {
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->get(route('admin.reports.download', 'xlsx'))->assertOk()->streamedContent();
    }
    $this->get(route('admin.reports.download', 'pdf'))->assertTooManyRequests();
    expect(Storage::disk('candidate-private')->allFiles())->toBe([]);
});

test('pdf report template escapes content and contains Vietnamese title filters and generation time', function () {
    $round = AdmissionRound::factory()->create(['name' => '<script>PRIVATE</script>']);
    $html = view('reports.admissions', [
        'filters' => new AdmissionReportFilters((string) $round->id, 'submitted', 'Nguyễn'),
        'generatedAt' => '09/10/2026 10:00:00 (Asia/Ho_Chi_Minh)', 'scope' => 'Chỉ bao gồm kết quả đã công bố.',
        'summary' => ['metrics' => ['Hồ sơ đã nộp' => 0]], 'applicationRows' => [], 'resultRows' => [],
        'headers' => ['Hồ sơ' => ['Mã hồ sơ'], 'Kết quả' => ['Kết quả']],
    ])->render();
    expect($html)->toContain('BÁO CÁO TUYỂN SINH', 'DejaVu Sans', '09/10/2026 10:00:00', 'Đã nộp', 'Nguyễn', 'Không có dữ liệu phù hợp.', '&lt;script&gt;')
        ->not->toContain('<script>PRIVATE</script>');
});
