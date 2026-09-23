<?php

namespace App\Console\Commands;

use App\Actions\CandidateFiles;
use App\Enums\UserRole;
use App\Models;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type Plan array{keys: array<string, list<int|string>>, columns: array<string, string>, counts: array<string, int>, files: list<string>, shared: list<string>, references: array<string, int>}
 */
class ClearCandidates extends Command
{
    protected $signature = 'dev:clear-candidates {--dry-run : Inspect the deletion plan without changing anything}';

    protected $description = 'Development only: remove candidate accounts and their owned admission data after confirmation';

    public const CONFIRMATION = 'Delete ALL listed candidate accounts, owned records and private files?';

    /** Ownership edges in parent-before-child order; reviewer/verifier links are not ownership. */
    private const OWNERSHIP = [
        'candidate_profiles' => ['users', 'user_id'],
        'applications' => ['candidate_profiles', 'candidate_profile_id'],
        'candidate_documents' => ['applications', 'application_id'],
        'candidate_scores' => ['candidate_profiles', 'candidate_profile_id'],
        'candidate_exam_results' => ['candidate_profiles', 'candidate_profile_id'],
        'candidate_exam_subject_scores' => ['candidate_exam_results', 'candidate_exam_result_id'],
        'candidate_transcripts' => ['candidate_profiles', 'candidate_profile_id'],
        'candidate_transcript_scores' => ['candidate_transcripts', 'candidate_transcript_id'],
        'candidate_transcript_evidence' => ['candidate_transcripts', 'candidate_transcript_id'],
        'candidate_certificates' => ['candidate_profiles', 'candidate_profile_id'],
        'candidate_admission_claims' => ['candidate_profiles', 'candidate_profile_id'],
        'admission_wishes' => ['applications', 'application_id'],
        'admission_results' => ['admission_wishes', 'admission_wish_id'],
    ];

    private const SUBJECTS = [
        'users' => Models\User::class,
        'candidate_profiles' => Models\CandidateProfile::class,
        'applications' => Models\Application::class,
        'candidate_documents' => Models\CandidateDocument::class,
        'candidate_scores' => Models\CandidateScore::class,
        'candidate_exam_results' => Models\CandidateExamResult::class,
        'candidate_exam_subject_scores' => Models\CandidateExamSubjectScore::class,
        'candidate_transcripts' => Models\CandidateTranscript::class,
        'candidate_transcript_scores' => Models\CandidateTranscriptScore::class,
        'candidate_transcript_evidence' => Models\CandidateTranscriptEvidence::class,
        'candidate_certificates' => Models\CandidateCertificate::class,
        'candidate_admission_claims' => Models\CandidateAdmissionClaim::class,
        'admission_wishes' => Models\AdmissionWish::class,
        'admission_results' => Models\AdmissionResult::class,
    ];

    private const REFERENCES = [
        'applications' => 'reviewed_by',
        'candidate_profiles' => 'verified_by',
        'candidate_documents' => 'verified_by',
        'candidate_scores' => 'verified_by',
        'candidate_exam_results' => 'verified_by',
        'candidate_transcripts' => 'verified_by',
        'candidate_certificates' => 'verified_by',
        'candidate_admission_claims' => 'verified_by',
    ];

    private const FILE_COLUMNS = [
        'candidate_profiles' => ['photo_path', 'citizen_id_front_path', 'citizen_id_back_path'],
        'candidate_documents' => ['file_path'],
        'candidate_scores' => ['evidence_path'],
        'candidate_exam_results' => ['evidence_path'],
        'candidate_transcripts' => ['evidence_path'],
        'candidate_transcript_evidence' => ['path'],
        'candidate_certificates' => ['evidence_path'],
        'candidate_admission_claims' => ['evidence_path'],
    ];

    public function handle(): int
    {
        if (! app()->environment('local') && ! (app()->environment('testing') && app()->runningUnitTests()
            && DB::connection()->getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:')) {
            $this->error('Refused: this command is restricted to local development (or isolated SQLite tests).');

            return self::FAILURE;
        }
        if (! $this->option('dry-run') && (! $this->input->isInteractive() || $this->output->isQuiet())) {
            $this->error('Refused: interactive confirmation and visible output are required. No --force option is supported.');

            return self::FAILURE;
        }
        try {
            $this->checkSchema();
            $plan = $this->plan();
            $this->line('Environment: '.app()->environment().' | Database: '.DB::connection()->getDatabaseName());
            $this->line('Candidate user IDs: '.implode(', ', $plan['keys']['users']));
            $this->line('All non-candidate users, admission catalogs and announcements will be preserved.');
            $this->table(['Delete from', 'Rows'], collect($plan['counts'])->map(fn (int $count, string $table): array => [$table, $count])->values()->all());
            $this->table(['Retained references to clear', 'Rows'], collect($plan['references'])->map(fn (int $count, string $column): array => [$column, $count])->values()->all());
            $this->line('Private files to delete: '.count($plan['files']).'; shared files preserved: '.count($plan['shared']));
            foreach ($plan['files'] as $path) {
                $this->line('  '.$path);
            }
            $this->line('Ownerless temporary files and unrelated queue/cache data are not deleted. Stop application writers and queue workers before confirming.');
            if ($this->option('dry-run') || $plan['keys']['users'] === []) {
                $this->info('No changes made.');

                return self::SUCCESS;
            }
            if (! $this->confirm(self::CONFIRMATION, false)) {
                $this->info('Cancelled. No changes made.');

                return self::SUCCESS;
            }
            DB::transaction(function () use ($plan): void {
                $this->checkSchema();
                $current = $this->plan(lock: true);
                if ($current !== $plan) {
                    throw new RuntimeException('The deletion plan changed after confirmation. Nothing was deleted; inspect and confirm again.');
                }
                foreach (self::REFERENCES as $table => $column) {
                    DB::table($table)->whereIn($column, $plan['keys']['users'])
                        ->whereNotIn('id', $plan['keys'][$table])->update([$column => null]);
                }
                foreach (array_reverse(array_keys($plan['keys'])) as $table) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }
                    $query = DB::table($table)->whereIn($plan['columns'][$table], $plan['keys'][$table]);
                    if ($table === 'users') {
                        $query->where('role', UserRole::Candidate->value);
                    }
                    $query->delete();
                }
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Cleanup refused or rolled back: '.$exception->getMessage());

            return self::FAILURE;
        }
        $failed = [];
        foreach ($plan['files'] as $path) {
            try {
                $this->assertSafeLocalFile($path);
                if ($this->isReferenced($path)) {
                    throw new RuntimeException('A retained record now references this file.');
                }
                if (! Storage::disk(CandidateFiles::DISK)->delete($path)) {
                    throw new RuntimeException('Storage refused deletion.');
                }
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = $path;
                $this->warn('File retained for manual review: '.$path);
            }
        }
        $this->info('Deleted '.count($plan['keys']['users']).' candidate users and '.(count($plan['files']) - count($failed)).' private files. Database transaction committed.');
        if ($failed !== []) {
            $this->error('Some files remain. Keep the listed paths for manual review; rerunning will not infer ownership of orphaned files.');
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return Plan */
    private function plan(bool $lock = false): array
    {
        if ($lock) {
            DB::table('users')->orderBy('id')->lockForUpdate()->get(['id']);
        }
        $users = DB::table('users')->where('role', UserRole::Candidate->value);
        $emails = (clone $users)->pluck('email')->all();
        $keys = ['users' => $this->queryKeys($users, 'id', $lock), ...array_fill_keys(array_keys(self::OWNERSHIP), [])];
        $columns = array_fill_keys(array_keys($keys), 'id');
        $counts = array_fill_keys(array_keys($keys), 0);
        $counts['users'] = count($keys['users']);
        foreach (self::OWNERSHIP as $table => [$parent, $foreignKey]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $query = DB::table($table)->whereIn($foreignKey, $keys[$parent]);
            $keys[$table] = $this->queryKeys($query, 'id', $lock);
            $columns[$table] = 'id';
            $counts[$table] = count($keys[$table]);
        }
        if (DB::table('announcements')->whereIn('created_by', $keys['users'])->exists()) {
            throw new RuntimeException('A candidate authored a shared announcement. Reassign its author explicitly before cleanup; announcements will not be deleted.');
        }
        $logs = DB::table('activity_logs')->where(function (Builder $query) use ($keys): void {
            $query->whereIn('user_id', $keys['users']);
            foreach (self::SUBJECTS as $table => $class) {
                $query->orWhere(function (Builder $subject) use ($keys, $table, $class): void {
                    $subject->whereIn('subject_type', array_unique([$class, (new $class)->getMorphClass()]))
                        ->whereIn('subject_id', $keys[$table]);
                });
            }
        });
        $queries = [
            'activity_logs' => [$logs, 'id'],
            'notifications' => [DB::table('notifications')->whereIn('notifiable_type', array_unique([Models\User::class, (new Models\User)->getMorphClass()]))->whereIn('notifiable_id', $keys['users']), 'id'],
            'announcement_user' => [DB::table('announcement_user')->whereIn('user_id', $keys['users']), 'user_id'],
            'sessions' => [DB::table('sessions')->whereIn('user_id', $keys['users']), 'id'],
            'password_reset_tokens' => [DB::table('password_reset_tokens')->whereIn('email', $emails), 'email'],
        ];
        foreach ($queries as $table => [$query, $column]) {
            $keys[$table] = $this->queryKeys($query, $column, $lock);
            $columns[$table] = $column;
            $counts[$table] = count($keys[$table]);
        }
        $references = [];
        foreach (self::REFERENCES as $table => $column) {
            $query = DB::table($table)->whereIn($column, $keys['users'])->whereNotIn('id', $keys[$table]);
            $references[$table.'.'.$column] = count($this->queryKeys($query, 'id', $lock));
        }
        [$files, $shared] = $this->files($keys);

        return compact('keys', 'columns', 'counts', 'files', 'shared', 'references');
    }

    /** @return list<int|string> */
    private function queryKeys(Builder $query, string $column, bool $lock): array
    {
        if ($lock) {
            $query->lockForUpdate();
        }
        $keys = [];
        foreach ($query->orderBy($column)->pluck($column) as $value) {
            if (! is_int($value) && ! is_string($value)) {
                throw new RuntimeException('Cleanup requires scalar record identifiers.');
            }
            $keys[] = $value;
        }

        return $keys;
    }

    /** Refuse new cascading dependencies until their ownership has been reviewed. */
    private function checkSchema(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (Schema::getTables() as $table) {
                if (strtolower($table['engine'] ?? '') !== 'innodb') {
                    throw new RuntimeException('Cleanup requires transactional InnoDB tables: '.$table['name']);
                }
            }
        }
        foreach (array_keys(self::OWNERSHIP) as $table) {
            if ($table !== 'candidate_transcript_evidence' && ! Schema::hasTable($table)) {
                throw new RuntimeException('Required table is missing: '.$table);
            }
        }
        $targets = ['users', ...array_keys(self::OWNERSHIP), 'activity_logs', 'notifications', 'sessions', 'password_reset_tokens', 'announcement_user'];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $parent = $foreign['foreign_table'];
                if (! in_array($parent, $targets, true)) {
                    continue;
                }
                $column = $foreign['columns'][0];
                $owned = (self::OWNERSHIP[$table] ?? null) === [$parent, $column];
                $reference = $parent === 'users' && (self::REFERENCES[$table] ?? null) === $column;
                $special = $parent === 'users' && in_array([$table, $column], [
                    ['activity_logs', 'user_id'], ['announcement_user', 'user_id'], ['announcements', 'created_by'], ['sessions', 'user_id'],
                ], true);
                if (count($foreign['columns']) !== 1 || $foreign['foreign_columns'] !== ['id'] || (! $owned && ! $reference && ! $special)) {
                    throw new RuntimeException('Unreviewed foreign key: '.$table.'.'.$column.' -> '.$parent.'. Cleanup blocked.');
                }
            }
        }
    }

    /** @param array<string, list<int|string>> $keys
     * @return array{list<string>, list<string>}
     */
    private function files(array $keys): array
    {
        if (config('filesystems.disks.'.CandidateFiles::DISK.'.driver') !== 'local') {
            throw new RuntimeException('Only the local candidate-private disk is supported.');
        }
        $directories = [];
        foreach (['candidate-photos' => 'users', 'candidate-citizen-ids' => 'users', 'candidate-documents' => 'applications',
            'candidate-scores' => 'candidate_profiles', 'candidate-exam-results' => 'candidate_profiles',
            'candidate-transcripts' => 'candidate_profiles', 'candidate-certificates' => 'candidate_profiles',
            'candidate-admission-claims' => 'candidate_profiles'] as $prefix => $table) {
            foreach ($keys[$table] as $id) {
                $directories[] = $prefix.'/'.$id.'/';
            }
        }
        $paths = $protected = [];
        foreach (self::FILE_COLUMNS as $table => $fields) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->get(['id', ...$fields]) as $row) {
                foreach ($fields as $field) {
                    $path = $row->{$field};
                    if (! is_string($path) || $path === '') {
                        continue;
                    }
                    if (! in_array($row->id, $keys[$table], true)) {
                        $protected[] = $this->fileKey($path);
                    } else {
                        if (! collect($directories)->contains(fn (string $directory): bool => str_starts_with($path, $directory))) {
                            throw new RuntimeException('Cannot prove ownership of stored file: '.$path);
                        }
                        $paths[] = $path;
                    }
                }
            }
        }
        foreach ($directories as $directory) {
            $this->assertSafeLocalFile(rtrim($directory, '/'));
            $paths = [...$paths, ...Storage::disk(CandidateFiles::DISK)->allFiles($directory)];
        }
        $files = $shared = [];
        foreach (array_unique($paths) as $path) {
            $this->assertSafeLocalFile($path);
            if (in_array($this->fileKey($path), $protected, true)) {
                $shared[] = $path;
            } elseif (Storage::disk(CandidateFiles::DISK)->exists($path)) {
                $files[] = $path;
            }
        }
        sort($files);
        sort($shared);

        return [$files, $shared];
    }

    private function assertSafeLocalFile(string $path): void
    {
        if (! CandidateFiles::safePath($path)) {
            throw new RuntimeException('Unsafe private storage path.');
        }
        $disk = Storage::disk(CandidateFiles::DISK);
        $root = realpath($disk->path(''));
        $absolute = $disk->path($path);
        if ($root === false || ! str_starts_with($this->pathKey($absolute), $this->pathKey($root).'/')) {
            throw new RuntimeException('Private file is outside the storage root.');
        }
        $part = $absolute;
        while ($this->pathKey($part) !== $this->pathKey($root)) {
            if (is_link($part) || (file_exists($part) && $this->pathKey((string) realpath($part)) !== $this->pathKey($part))) {
                throw new RuntimeException('Linked or redirected storage paths are not safe to clean.');
            }
            $parent = dirname($part);
            if ($parent === $part) {
                throw new RuntimeException('Could not resolve private file ownership.');
            }
            $part = $parent;
        }
    }

    private function pathKey(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private function isReferenced(string $path): bool
    {
        foreach (self::FILE_COLUMNS as $table => $fields) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($fields as $field) {
                foreach (DB::table($table)->whereNotNull($field)->pluck($field) as $reference) {
                    if (is_string($reference) && $this->fileKey($reference) === $this->fileKey($path)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function fileKey(string $path): string
    {
        $absolute = Storage::disk(CandidateFiles::DISK)->path($path);

        return $this->pathKey(realpath($absolute) ?: $absolute);
    }
}
