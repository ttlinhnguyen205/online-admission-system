<?php

use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;

test('MariaDB keeps native enums and the SQLite repair issues no statements', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_SERVER_VERSION)->andReturn('5.5.5-10.4.32-MariaDB');
    $connection = new MariaDbConnection($pdo, '', '', ['driver' => 'mariadb']);
    $original = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $creation = $connection->pretend(function (): void {
            (require database_path('migrations/2026_09_21_103226_create_candidate_exam_results_table.php'))->up();
            (require database_path('migrations/2026_09_21_103236_create_candidate_transcript_scores_table.php'))->up();
        });
        $sql = implode("\n", array_column($creation, 'query'));
        expect($sql)->toContain("`exam_type` enum('thpt', 'dgnl', 'dgtd', 'vsat', 'spt')")
            ->toContain("`status` enum('pending', 'verified', 'rejected')")
            ->toContain("`grade_level` enum('10', '11', '12')");

        $precision = $connection->pretend(function (): void {
            (require database_path('migrations/2026_09_22_235741_change_candidate_exam_result_score_precision.php'))->up();
            (require database_path('migrations/2026_09_23_001934_change_candidate_transcript_score_precision.php'))->up();
        });
        expect(implode("\n", array_column($precision, 'query')))
            ->toContain('modify `overall_score`')->toContain('modify `score`')
            ->not->toContain('`exam_type`')->not->toContain('`status`')->not->toContain('`grade_level`');

        $repair = require database_path('migrations/2026_10_09_010302_restore_sqlite_normalized_admission_constraints.php');
        expect($connection->pretend(function () use ($repair): void {
            $repair->up();
            $repair->down();
        }))->toBeEmpty();
    } finally {
        Schema::swap($original);
    }
});

test('SQLite repair preserves records and rejects invalid updates atomically', function (bool $invalidExistingRow) {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
    $original = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        Schema::enableForeignKeyConstraints();
        foreach (['users', 'candidate_profiles', 'candidate_transcripts'] as $table) {
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
            });
        }
        (require database_path('migrations/2026_09_21_103226_create_candidate_exam_results_table.php'))->up();
        (require database_path('migrations/2026_09_21_103236_create_candidate_transcript_scores_table.php'))->up();
        Schema::create('exam_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_exam_result_id')->constrained()->cascadeOnDelete();
        });
        (require database_path('migrations/2026_09_22_235741_change_candidate_exam_result_score_precision.php'))->up();
        (require database_path('migrations/2026_09_23_001934_change_candidate_transcript_score_precision.php'))->up();
        $connection->table('candidate_profiles')->insert(['id' => 1]);
        $connection->table('candidate_transcripts')->insert(['id' => 1]);
        $connection->table('candidate_exam_results')->insert([
            'id' => 1, 'candidate_profile_id' => 1, 'exam_type' => 'thpt', 'exam_year' => 2026,
            'status' => 'verified', 'overall_score' => 25,
        ]);
        $connection->table('exam_children')->insert(['id' => 1, 'candidate_exam_result_id' => 1]);
        $connection->table('candidate_transcript_scores')->insert([
            'id' => 1, 'candidate_transcript_id' => 1, 'subject_code' => 'MATH', 'subject_name' => 'Toán',
            'grade_level' => $invalidExistingRow ? '9' : '12', 'score' => 8.25,
        ]);
        $before = $connection->table('candidate_exam_results')->first();
        $repair = require database_path('migrations/2026_10_09_010302_restore_sqlite_normalized_admission_constraints.php');

        if ($invalidExistingRow) {
            expect(fn () => $repair->up())->toThrow(QueryException::class);
            expect($connection->table('candidate_transcript_scores')->value('grade_level'))->toBe('9');
            expect($connection->scalar("select sql from sqlite_master where name = 'candidate_exam_results'"))->not->toContain('check');
        } else {
            $repair->up();
            foreach (['exam_type' => 'unsupported', 'status' => 'unsupported'] as $field => $value) {
                expect(fn () => $connection->table('candidate_exam_results')->where('id', 1)->update([$field => $value]))
                    ->toThrow(QueryException::class);
            }
            expect(fn () => $connection->table('candidate_transcript_scores')->where('id', 1)->update(['grade_level' => '9']))
                ->toThrow(QueryException::class);
            expect($connection->table('candidate_transcript_scores')->value('score'))->toEqual(8.25);
            expect(array_column(Schema::getIndexes('candidate_transcript_scores'), 'name'))->toContain('candidate_transcript_subject_grade_unique');
        }
        expect($connection->table('candidate_exam_results')->first())->toEqual($before);
        expect($connection->table('exam_children')->count())->toBe(1);
        expect((int) $connection->scalar('PRAGMA foreign_keys'))->toBe(1);
        expect($connection->select('PRAGMA foreign_key_check'))->toBeEmpty();
    } finally {
        Schema::swap($original);
        $connection->disconnect();
    }
})->with([false, true]);
