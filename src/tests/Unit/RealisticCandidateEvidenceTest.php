<?php

use App\Support\RealisticCandidateEvidence;
use Symfony\Component\Process\Process;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('renderer streams one hundred candidates within a bounded memory budget at 128 MB', function () {
    $records = [];
    foreach (range(1, 100) as $number) {
        $records[] = [
            'code' => 'MEM-'.$number, 'variant' => $number, 'name' => 'Candidate memory regression '.$number,
            'birth' => '2008-01-01', 'school' => 'Fictional school for memory regression',
            'labels' => array_fill_keys(['brand', 'profile', 'exam', 'transcript', 'profileNote', 'note', 'watermark'], 'TEST DATA ONLY'),
            'examRows' => array_fill(0, 8, ['label' => 'Subject test', 'value' => '8.25']),
            'transcriptRows' => array_fill(0, 8, ['label' => 'Transcript subject', 'value' => '9.25']),
            'examTotal' => 'Total 24.75', 'transcriptTotal' => 'Total 27.75',
        ];
    }
    $script = <<<'PHP'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $records = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);
    $count = 0;
    foreach (app(App\Support\RealisticCandidateEvidence::class)->render($records) as $code => $images) {
        if ($code !== 'MEM-'.($count + 1) || array_keys($images) !== ['profile', 'exam', 'transcript']) {
            throw new RuntimeException('Unexpected image identity');
        }
        foreach ($images as $bytes) {
            if (getimagesizefromstring($bytes)['mime'] !== 'image/png') {
                throw new RuntimeException('Invalid PNG');
            }
        }
        $count++;
    }
    echo json_encode(['count' => $count, 'growth' => memory_get_peak_usage(true) - $baseline, 'limit' => ini_get('memory_limit')]);
    PHP;
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $script], base_path(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    ]);
    $process->setInput(json_encode($records, JSON_THROW_ON_ERROR))->setTimeout(180)->mustRun();
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['count'])->toBe(100);
    expect($result['limit'])->toBe('128M');
    expect($result['growth'])->toBeLessThan(16 * 1024 * 1024);
});

test('renderer process failure does not yield partial evidence', function () {
    $yielded = false;
    expect(function () use (&$yielded) {
        foreach (app(RealisticCandidateEvidence::class)->render([['code' => 'INVALID']]) as $images) {
            $yielded = true;
        }
    })->toThrow(RuntimeException::class, 'Không dựng được PNG minh chứng');
    expect($yielded)->toBeFalse();
});

test('renderer accepts an empty candidate list', function () {
    expect(iterator_to_array(app(RealisticCandidateEvidence::class)->render([])))->toBe([]);
});
