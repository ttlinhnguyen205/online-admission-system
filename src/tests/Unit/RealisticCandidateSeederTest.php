<?php

use App\Actions\AdmissionReviewSnapshot;
use App\Actions\CandidateApplications;
use App\Actions\CandidateFiles;
use App\Actions\ProcessAdmissionRound;
use App\Actions\StaffApplicationReview;
use App\Enums\ApplicationStatus;
use App\Livewire\Admin\ApplicationDetails;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\HighSchool;
use App\Models\User;
use Database\Seeders\AdmissionDemoSeeder;
use Database\Seeders\AdmissionTestingSeeder;
use Database\Seeders\HighSchoolSeeder;
use Database\Seeders\ProvinceSeeder;
use Database\Seeders\RealisticAdmissionCatalogSeeder;
use Database\Seeders\RealisticCandidateSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
        throw new RuntimeException('Only isolated SQLite memory is allowed.');
    }
    $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
    $this->travelTo(Carbon::parse('2026-10-24 10:00:00'));
    config(['realistic_candidate.password' => bin2hex(random_bytes(16))]);
    Notification::fake();
    Storage::fake(CandidateFiles::DISK);
    $this->seed([ProvinceSeeder::class, HighSchoolSeeder::class, AdmissionDemoSeeder::class, RealisticAdmissionCatalogSeeder::class]);
});

test('hundred realistic candidates have consistent records and engine excludes five incomplete drafts', function () {
    $grades = [];
    DB::listen(function (QueryExecuted $query) use (&$grades): void {
        if (preg_match('/^insert into ["`]candidate_transcript_scores["`] \(([^)]+)\)/i', $query->sql, $matches)) {
            $columns = array_map(fn ($column) => trim($column, ' "`'), explode(',', $matches[1]));
            $grade = $query->bindings[array_search('grade_level', $columns, true)];
            expect($grade)->toBeString()->toBeIn(['10', '11', '12']);
            $grades[] = $grade;
        }
    });
    $this->seed(RealisticCandidateSeeder::class);
    foreach (['users' => 100, 'candidate_profiles' => 100, 'applications' => 100, 'admission_wishes' => 300,
        'candidate_scores' => 585, 'candidate_documents' => 200, 'candidate_exam_results' => 100, 'candidate_exam_subject_scores' => 285,
        'candidate_transcripts' => 100, 'candidate_transcript_scores' => 900, 'candidate_transcript_evidence' => 100, 'admission_results' => 0] as $table => $count) {
        $this->assertDatabaseCount($table, $count);
    }
    expect(array_count_values($grades))->toBe([10 => 300, 11 => 300, 12 => 300]);
    expect(User::distinct()->count('name'))->toBe(100)->and(CandidateProfile::distinct()->count('province_code'))->toBe(34);
    expect(Hash::check(config('realistic_candidate.password'), User::first()->password))->toBeTrue();
    $round = AdmissionRound::where('code', 'RAC26-ENGINE')->sole();
    expect($round->applications()->count())->toBe(70);
    expect(AdmissionRound::where('code', 'RAC26-REGISTER')->sole()->applications()->count())->toBe(30);
    expect(AdmissionRound::where('code', 'RAC26-PLAN')->sole()->applications()->count())->toBe(0);
    foreach (['verified' => 65, 'draft' => 5, 'submitted' => 20, 'needs_revision' => 10] as $status => $count) {
        expect(Application::where('status', $status)->count())->toBe($count);
    }
    foreach (CandidateProfile::with(['scores', 'examResults.subjectScores', 'transcripts.scores', 'applications.wishes.admissionProgram.admissionMethod'])->get() as $profile) {
        expect(HighSchool::where('code', $profile->high_school_code)->where('name', $profile->high_school_name)
            ->whereHas('province', fn ($q) => $q->where('code', $profile->province_code))->exists())->toBeTrue();
        expect(CandidateApplications::profileIsComplete($profile))->toBe($profile->address !== null);
        $application = $profile->applications->sole();
        expect($application->wishes->pluck('priority')->sort()->values()->all())->toBe([1, 2, 3]);
        foreach ($application->wishes as $wish) {
            expect($wish->admissionProgram->admission_round_id)->toBe($application->admission_round_id);
            expect($wish->admissionProgram->status)->toBe('active')->and($wish->admissionProgram->admissionMethod->is_active)->toBeTrue();
        }
        foreach ($profile->scores as $score) {
            expect((float) $score->score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(10);
            $source = $score->score_type === 'thpt' ? $profile->examResults->sole()->subjectScores : $profile->transcripts->sole()->scores;
            foreach ($source->where('subject_code', $score->subject_code) as $row) {
                expect((float) $row->score)->toBe((float) $score->score);
            }
        }
        $photo = Storage::disk(CandidateFiles::DISK)->get($profile->photo_path);
        expect(getimagesizefromstring($photo)[0])->toBe(600)->and(getimagesizefromstring($photo)[1])->toBe(800);
    }
    expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    expect(Storage::disk(CandidateFiles::DISK)->allFiles())->toHaveCount(1285);
    Notification::assertNothingSent();
    $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
    $snapshots = app(AdmissionReviewSnapshot::class);
    foreach (Application::where('status', 'verified')->pluck('id') as $id) {
        $reviewable = $snapshots->load($id);
        // Evaluate the review checklist without persisting any status change.
        $reviewable->status = ApplicationStatus::UnderReview;
        expect($snapshots->checklist($reviewable))->toBe([]);
    }
    $engine = app(ProcessAdmissionRound::class);
    $preview = $engine->preview($round->id);
    expect($preview['blockers'])->toBe([])->and($preview['verified'])->toBe(65)->and($preview['drafts'])->toBe(5);
    $summary = $engine->process($round->id, $preview['fingerprint']);
    expect($summary['included'])->toBe(65)->and($summary['excluded_drafts'])->toBe(5)->and($summary['results'])->toBe(195)->and($summary['admitted'])->toBe(40);
    $expected = ['7480201' => [56], '7480101' => [57], '7480103' => [58], '7340101' => [31, 41, 46],
        '7340301' => [27, 32, 42, 47], '7340201' => [18, 28, 33, 43, 48], '7510605' => [34, 39, 44, 49, 54, 59],
        '7340115' => [30, 35, 40, 45, 50, 55, 60], '7220201' => [21, 36, 51], '7340122' => [17, 22, 37, 52],
        '7340120' => [23, 38, 53], '7340205' => [20, 25]];
    foreach ($round->programs()->with('major')->get() as $program) {
        $codes = $program->wishes()->where('status', 'admitted')->with('application')->get()->pluck('application.application_code')->sort()->values()->all();
        $numbers = $expected[substr($program->major->code, 6)] ?? [];
        expect($codes)->toBe(array_map(fn ($number) => sprintf('RC26-%03d', $number), $numbers));
    }
    foreach (range(61, 80) as $number) {
        $application = Application::where('application_code', sprintf('RC26-%03d', $number))->sole();
        expect($application->wishes()->where('status', 'admitted')->count())->toBe(0);
        if ($number >= 66) {
            expect($application->wishes()->has('result')->count())->toBe(0);
        }
    }
});

test('staff can open private images review pending applications and cannot verify missing information', function () {
    $this->seed(RealisticCandidateSeeder::class);
    $staff = User::factory()->create(['role' => 'staff', 'status' => 'active']);
    $this->actingAs($staff);
    $application = Application::where('application_code', 'RC26-081')->sole();
    $profile = $application->candidateProfile;
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->assertSee($profile->user->name);
    foreach ($profile->scores as $score) {
        $response = $this->get(route('admission.scores.evidence', ['score' => $score->id]))->assertOk()->assertHeader('Content-Type', 'image/png');
        expect($response->streamedContent())->toBe(Storage::disk(CandidateFiles::DISK)->get($score->evidence_path));
    }
    foreach ($application->documents as $document) {
        $response = $this->get(route('admission.documents.download', ['document' => $document->id]))->assertOk();
        expect($response->streamedContent())->toBe(Storage::disk(CandidateFiles::DISK)->get($document->file_path));
    }
    $snapshots = app(AdmissionReviewSnapshot::class);
    $review = app(StaffApplicationReview::class);
    $review->start($application->id, $snapshots->fingerprint($snapshots->load($application->id)));
    foreach ($profile->scores as $score) {
        $review->verifyScore($application->id, $score->id, $snapshots->fingerprint($snapshots->load($application->id)));
    }
    foreach ($application->documents as $document) {
        $review->verifyDocument($application->id, $document->id, $snapshots->fingerprint($snapshots->load($application->id)));
    }
    expect(fn () => $review->verify($application->id, $snapshots->fingerprint($snapshots->load($application->id))))->toThrow(ValidationException::class);
    foreach ($snapshots->load($application->id)->getRelation('admissionSources') as $key => $source) {
        $review->reviewAdmissionSource($application->id, explode(':', $key)[0], $source->id, $snapshots->fingerprint($snapshots->load($application->id)), true);
    }
    $review->verify($application->id, $snapshots->fingerprint($snapshots->load($application->id)));
    $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'verified', 'reviewed_by' => $staff->id]);
    $missing = Application::where('application_code', 'RC26-071')->sole();
    $missing->update(['status' => 'under_review']);
    expect(fn () => $review->verify($missing->id, $snapshots->fingerprint($snapshots->load($missing->id))))->toThrow(ValidationException::class);
    $this->actingAs(User::where('email', 'realistic-candidate-001@example.test')->sole());
    $this->get(route('admission.scores.evidence', ['score' => $profile->scores->first()->id]))->assertNotFound();
    $before = $application->fresh()->getRawOriginal();
    $this->seed(RealisticCandidateSeeder::class);
    expect($application->fresh()->getRawOriginal())->toEqual($before);
});

test('reruns preserve all identities original AT fixtures and RAC catalogs', function () {
    config(['admission_testing.password' => bin2hex(random_bytes(16))]);
    $this->seed(AdmissionTestingSeeder::class);
    $tables = ['users', 'candidate_profiles', 'applications', 'admission_wishes', 'candidate_scores', 'candidate_documents', 'candidate_exam_results',
        'candidate_exam_subject_scores', 'candidate_transcripts', 'candidate_transcript_scores', 'candidate_transcript_evidence',
        'admission_rounds', 'majors', 'admission_methods', 'admission_programs', 'candidate_major_offerings', 'activity_logs'];
    $original = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()]);
    $this->seed(RealisticCandidateSeeder::class);
    foreach ($original as $table => $rows) {
        expect(DB::table($table)->whereIn('id', $rows->pluck('id'))->orderBy('id')->get()->toJson())->toBe($rows->toJson());
    }
    $seeded = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
    $this->seed(RealisticCandidateSeeder::class);
    foreach ($seeded as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($rows);
    }
});

test('invalid credentials conflicts and closed registration fail before any new data', function () {
    config(['realistic_candidate.password' => null]);
    expect(fn () => $this->seed(RealisticCandidateSeeder::class))->toThrow(RuntimeException::class);
    config(['realistic_candidate.password' => bin2hex(random_bytes(16))]);
    $round = AdmissionRound::where('code', 'RAC26-REGISTER')->sole();
    $round->update(['status' => 'closed']);
    expect(fn () => $this->seed(RealisticCandidateSeeder::class))->toThrow(RuntimeException::class);
    $round->update(['status' => 'open']);
    User::factory()->create(['email' => 'realistic-candidate-001@example.test']);
    expect(fn () => $this->seed(RealisticCandidateSeeder::class))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('applications', 0);
    expect(Storage::disk(CandidateFiles::DISK)->allFiles())->toBe([]);
});

test('database failure rolls back rows and removes only files created by this attempt', function () {
    $disk = Storage::disk(CandidateFiles::DISK);
    $disk->put('candidate-documents/unrelated.png', 'existing file');
    DB::connection()->beforeExecuting(function (string $sql): void {
        if (str_starts_with($sql, 'insert into "candidate_transcript_scores"')) {
            throw new RuntimeException('Simulated write failure');
        }
    });
    expect(fn () => $this->seed(RealisticCandidateSeeder::class))->toThrow(RuntimeException::class, 'Simulated write failure');
    foreach (['users', 'candidate_profiles', 'applications', 'candidate_documents', 'candidate_scores', 'candidate_transcripts'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    expect($disk->allFiles())->toBe(['candidate-documents/unrelated.png'])->and($disk->get('candidate-documents/unrelated.png'))->toBe('existing file');
});
