<?php

namespace App\Support;

use Generator;
use RuntimeException;
use Symfony\Component\Process\Process;

class RealisticCandidateEvidence
{
    /** @param list<array<string, mixed>> $records
     * @return Generator<string, array<string, string>>
     */
    public function render(array $records): Generator
    {
        $output = tmpfile();
        if ($output === false) {
            throw new RuntimeException('Không tạo được file tạm cho minh chứng.');
        }
        try {
            $process = new Process([config('realistic_candidate.python'), database_path('data/render-realistic-candidate.py')]);
            $process->setInput(json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $process->setTimeout(180);
            $process->disableOutput();
            $error = '';
            $process->run(function (string $type, string $chunk) use ($output, &$error): void {
                if ($type === Process::ERR) {
                    $error = substr($error.$chunk, -8192);

                    return;
                }
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Không ghi được file tạm cho minh chứng.');
                    }
                    $offset += $written;
                }
            });
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Không dựng được PNG minh chứng: '.$error);
            }
            rewind($output);
            foreach ($records as $record) {
                $images = [];
                foreach (['profile', 'exam', 'transcript'] as $kind) {
                    $line = fgets($output);
                    if ($line === false) {
                        throw new RuntimeException('Thiếu ảnh minh chứng trong kết quả dựng.');
                    }
                    $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    if (($row['code'] ?? null) !== $record['code'] || ($row['kind'] ?? null) !== $kind || ! is_string($row['png'] ?? null)) {
                        throw new RuntimeException('Renderer trả về định danh minh chứng không hợp lệ.');
                    }
                    $bytes = base64_decode($row['png'], true);
                    if ($bytes === false || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
                        throw new RuntimeException('Renderer trả về ảnh không hợp lệ.');
                    }
                    $images[$kind] = $bytes;
                }
                unset($line, $row, $bytes);
                yield $record['code'] => $images;
            }
            if (fgets($output) !== false) {
                throw new RuntimeException('Renderer trả về minh chứng thừa.');
            }
        } finally {
            fclose($output);
        }
    }
}
