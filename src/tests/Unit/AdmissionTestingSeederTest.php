<?php

use App\Actions\AdmissionReviewSnapshot;
use App\Actions\CandidateApplications;
use App\Actions\CandidateFiles;
use App\Actions\ProcessAdmissionRound;
use App\Actions\StaffApplicationReview;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\HighSchool;
use App\Models\User;
use Database\Seeders\AdmissionDemoSeeder;
use Database\Seeders\AdmissionTestingSeeder;
use Database\Seeders\HighSchoolSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
        throw new RuntimeException('Tests require isolated SQLite :memory:.');
    }
    $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
    Storage::fake(CandidateFiles::DISK);
    Notification::fake();
    config(['admission_testing.password' => bin2hex(random_bytes(16))]);
    $this->seed([ProvinceSeeder::class, HighSchoolSeeder::class, AdmissionDemoSeeder::class]);
});

test('testing fixtures have exact counts valid sources and preserve existing catalogs', function () {
    $grades = [];
    DB::listen(function (QueryExecuted $query) use (&$grades): void {
        if (preg_match('/^insert into ["`]candidate_transcript_scores["`] \(([^)]+)\)/i', $query->sql, $matches) !== 1) {
            return;
        }
        $columns = array_map(fn ($column) => trim($column, ' "`'), explode(',', $matches[1]));
        $grade = $query->bindings[array_search('grade_level', $columns, true)];
        expect($grade)->toBeString()->toBeIn(['10', '11', '12']);
        $mysql = new MySqlConnection(null);
        $statement = Mockery::mock(PDOStatement::class);
        $statement->shouldReceive('bindValue')->once()->with(1, $grade, PDO::PARAM_STR)->andReturnTrue();
        $mysql->bindValues($statement, $mysql->prepareBindings([$grade]));
        $grades[] = $grade;
    });
    $existing = User::factory()->create();
    $before = $existing->getRawOriginal();
    $this->seed(AdmissionTestingSeeder::class);
    expect(array_count_values($grades))->toBe([10 => 300, 11 => 300, 12 => 300]);
    foreach (['users' => 101, 'candidate_profiles' => 100, 'applications' => 100, 'admission_wishes' => 300,
        'candidate_scores' => 600, 'candidate_documents' => 100, 'candidate_exam_results' => 100,
        'candidate_exam_subject_scores' => 300, 'candidate_transcripts' => 100, 'candidate_transcript_scores' => 900,
        'candidate_transcript_evidence' => 100, 'provinces' => 34, 'high_schools' => 2784, 'admission_rounds' => 5,
        'admission_programs' => 24, 'candidate_major_offerings' => 12, 'admission_results' => 0] as $table => $count) {
        $this->assertDatabaseCount($table, $count);
    }
    expect($existing->fresh()->getRawOriginal())->toEqual($before);
    expect(Application::where('status', 'verified')->count())->toBe(70)
        ->and(Application::where('status', 'needs_revision')->count())->toBe(15)
        ->and(Application::where('status', 'submitted')->count())->toBe(15);
    foreach (CandidateProfile::with(['applications.wishes.admissionProgram', 'examResults.subjectScores', 'transcripts.scores'])->get() as $profile) {
        expect(HighSchool::where('code', $profile->high_school_code)->where('name', $profile->high_school_name)
            ->whereHas('province', fn ($q) => $q->where('code', $profile->province_code))->exists())->toBeTrue();
        expect(CandidateApplications::profileIsComplete($profile))->toBe($profile->address !== null);
        expect($profile->examResults->sole()->subjectScores)->toHaveCount(3);
        expect($profile->transcripts->sole()->scores)->toHaveCount(9);
        foreach ($profile->applications->sole()->wishes as $wish) {
            expect($wish->admissionProgram->admission_round_id)->toBe($profile->applications->sole()->admission_round_id);
        }
    }
    expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    Notification::assertNothingSent();
});

test('a failed transcript insert rolls back demo rows and a retry reuses evidence safely', function () {
    $fail = true;
    DB::connection()->beforeExecuting(function (string $sql) use (&$fail): void {
        if ($fail && str_starts_with($sql, 'insert into "candidate_transcript_scores"')) {
            throw new RuntimeException('Simulated transcript insert failure');
        }
    });
    expect(fn () => $this->seed(AdmissionTestingSeeder::class))->toThrow(RuntimeException::class, 'Simulated transcript insert failure');
    foreach (['users', 'candidate_profiles', 'applications', 'candidate_scores', 'candidate_transcripts', 'candidate_transcript_scores', 'activity_logs'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $this->assertDatabaseCount('admission_rounds', 3);
    $files = Storage::disk(CandidateFiles::DISK)->allFiles();
    expect($files)->toHaveCount(12);
    $contents = collect($files)->mapWithKeys(fn ($path) => [$path => Storage::disk(CandidateFiles::DISK)->get($path)]);
    $fail = false;
    $this->seed(AdmissionTestingSeeder::class);
    $this->assertDatabaseCount('candidate_transcript_scores', 900);
    foreach ($contents as $path => $bytes) {
        expect(Storage::disk(CandidateFiles::DISK)->get($path))->toBe($bytes);
    }
});

test('reruns preserve reviewed data and all record identities', function () {
    $this->seed(AdmissionTestingSeeder::class);
    $application = Application::where('application_code', 'AT-V1-066')->sole();
    $application->update(['status' => 'under_review']);
    $tables = ['users', 'candidate_profiles', 'applications', 'admission_wishes', 'candidate_scores', 'admission_rounds', 'activity_logs'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
    $this->seed(AdmissionTestingSeeder::class);
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($rows);
    }
});

test('reserved account conflicts abort without writes', function () {
    User::factory()->create(['email' => 'admission-testing-001@example.test']);
    expect(fn () => $this->seed(AdmissionTestingSeeder::class))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('admission_rounds', 3);
    expect(Storage::disk(CandidateFiles::DISK)->allFiles())->toBe([]);
});

test('staff can verify a pending fixture and incomplete profile remains blocked', function () {
    $this->seed(AdmissionTestingSeeder::class);
    $this->actingAs(User::factory()->create(['role' => 'staff', 'status' => 'active']));
    $snapshots = app(AdmissionReviewSnapshot::class);
    $review = app(StaffApplicationReview::class);
    $id = Application::where('application_code', 'AT-V1-066')->value('id');
    $review->start($id, $snapshots->fingerprint($snapshots->load($id)));
    expect($snapshots->checklist($snapshots->load($id)))->toBe([]);
    $review->verify($id, $snapshots->fingerprint($snapshots->load($id)));
    $this->assertDatabaseHas('applications', ['id' => $id, 'status' => 'verified']);
    $missing = Application::where('application_code', 'AT-V1-051')->sole();
    $missing->update(['status' => 'under_review']);
    expect(fn () => $review->verify($missing->id, $snapshots->fingerprint($snapshots->load($missing->id))))->toThrow(ValidationException::class);
});

test('allocation has deterministic winners respects wishes and excludes low scores', function () {
    $this->seed(AdmissionTestingSeeder::class);
    $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
    $round = AdmissionRound::where('code', 'AT-V1-ENGINE')->sole();
    $engine = app(ProcessAdmissionRound::class);
    $preview = $engine->preview($round->id);
    expect($preview['blockers'])->toBe([]);
    $summary = $engine->process($round->id, $preview['fingerprint']);
    expect($summary['admitted'])->toBe(65)->and($summary['results'])->toBe(210);
    $programs = AdmissionProgram::where('admission_round_id', $round->id)->orderBy('id')->get();
    foreach (range(1, 100) as $number) {
        if ($number >= 51 && $number <= 80) {
            continue;
        }
        $application = Application::where('application_code', sprintf('AT-V1-%03d', $number))->sole();
        $winners = $application->wishes()->where('status', 'admitted')->get();
        if ($number >= 91 && $number <= 95) {
            expect($winners)->toHaveCount(0);
            expect($application->wishes()->where('status', 'ineligible')->count())->toBe(3);
        } else {
            $index = match (true) {
                $number <= 35 => 2, $number <= 50 => 1, $number <= 85 => 0, $number <= 90 => 1, default => 0
            };
            expect($winners->sole()->admission_program_id)->toBe($programs[$index]->id);
        }
    }
    expect($engine->process($round->id, $preview['fingerprint']))->toBe($summary);
    $this->seed(AdmissionTestingSeeder::class);
    $this->assertDatabaseCount('admission_results', 210);
    expect(AdmissionResult::whereNotNull('published_at')->exists())->toBeFalse();
});

test('cutoff ties and unresolved reviews block processing without result writes', function () {
    $this->seed(AdmissionTestingSeeder::class);
    $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
    $round = AdmissionRound::where('code', 'AT-V1-ENGINE')->sole();
    $round->programs()->orderBy('id')->first()->update(['quota' => 8]);
    $engine = app(ProcessAdmissionRound::class);
    $preview = $engine->preview($round->id);
    expect(implode(' ', $preview['blockers']))->toContain('bằng điểm');
    expect(fn () => $engine->process($round->id, $preview['fingerprint']))->toThrow(ValidationException::class);
    $review = AdmissionRound::where('code', 'AT-V1-REVIEW')->sole();
    $review->update(['status' => 'closed']);
    $preview = $engine->preview($review->id);
    expect($preview['submitted'])->toBe(15)->and($preview['under_review'])->toBe(0)->and($preview['needs_revision'])->toBe(15);
    expect(fn () => $engine->process($review->id, $preview['fingerprint']))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('admission_results', 0);
});

test('missing catalogs or password fail before inserting fixtures', function () {
    config(['admission_testing.password' => null]);
    expect(fn () => $this->seed(AdmissionTestingSeeder::class))->toThrow(RuntimeException::class);
    config(['admission_testing.password' => bin2hex(random_bytes(16))]);
    AdmissionMethod::where('code', 'DEMO-HB-D01')->update(['is_active' => false]);
    expect(fn () => $this->seed(AdmissionTestingSeeder::class))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('admission_rounds', 3);
    expect(Storage::disk(CandidateFiles::DISK)->allFiles())->toBe([]);
});

test('existing evidence is never overwritten and database transaction rolls back', function () {
    $path = 'candidate-photos/admission-testing-v1-001-candidate-photos.png';
    Storage::disk(CandidateFiles::DISK)->put($path, 'existing evidence');
    expect(fn () => $this->seed(AdmissionTestingSeeder::class))->toThrow(RuntimeException::class);
    expect(Storage::disk(CandidateFiles::DISK)->get($path))->toBe('existing evidence');
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('admission_rounds', 3);
});
