<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

class RealisticCandidateEvidence
{
    /** @param list<array<string, mixed>> $records
     * @return array<string, array<string, string>>
     */
    public function render(array $records): array
    {
        $process = new Process([config('realistic_candidate.python'), database_path('data/render-realistic-candidate.py')]);
        $process->setInput(json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $process->setTimeout(180);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Không dựng được PNG minh chứng: '.$process->getErrorOutput());
        }
        $images = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $row = json_decode(trim($line), true, flags: JSON_THROW_ON_ERROR);
            $bytes = base64_decode($row['png'], true);
            if ($bytes === false || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
                throw new RuntimeException('Renderer trả về ảnh không hợp lệ.');
            }
            $images[$row['code']][$row['kind']] = $bytes;
        }
        foreach ($records as $record) {
            foreach (['profile', 'exam', 'transcript'] as $kind) {
                if (! isset($images[$record['code']][$kind])) {
                    throw new RuntimeException('Thiếu ảnh minh chứng trong kết quả dựng.');
                }
            }
        }

        return $images;
    }
}
