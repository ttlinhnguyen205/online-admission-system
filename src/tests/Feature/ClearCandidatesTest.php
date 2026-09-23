<?php

use App\Actions\CandidateFiles;
use App\Console\Commands\ClearCandidates;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\AdmissionResult;
use App\Models\AdmissionWish;
use App\Models\Announcement;
use App\Models\Application;
use App\Models\CandidateAdmissionClaim;
use App\Models\CandidateCertificate;
use App\Models\CandidateDocument;
use App\Models\CandidateExamResult;
use App\Models\CandidateExamSubjectScore;
use App\Models\CandidateProfile;
use App\Models\CandidateScore;
use App\Models\CandidateTranscript;
use App\Models\CandidateTranscriptScore;
use App\Models\HighSchool;
use App\Models\Province;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function cleanupNotification(User $user): string
{
    $id = (string) Str::uuid();
    $user->notifications()->create(['id' => $id, 'type' => 'test.notification', 'data' => []]);

    return $id;
}

function cleanupPrivateFile(string $path): string
{
    Storage::disk(CandidateFiles::DISK)->put($path, 'private test bytes');

    return $path;
}

test('confirmed cleanup removes the entire candidate graph while retaining staff admin and catalogs', function () {
    Storage::fake(CandidateFiles::DISK);
    $admin = User::factory()->create(['id' => 5, 'role' => UserRole::Admin]);
    $staff = User::factory()->create(['id' => 9, 'role' => UserRole::Staff]);
    $candidate = User::factory()->create(['id' => 42]);
    $bareCandidate = User::factory()->create(['id' => 43]);
    $profile = CandidateProfile::factory()->for($candidate)->create([
        'photo_path' => cleanupPrivateFile('candidate-photos/42/photo.png'),
        'citizen_id_front_path' => cleanupPrivateFile('candidate-citizen-ids/42/front.png'),
        'citizen_id_back_path' => cleanupPrivateFile('candidate-citizen-ids/42/back.png'),
        'verified_by' => $staff->id,
    ]);
    $application = Application::factory()->for($profile)->create(['reviewed_by' => $staff->id]);
    CandidateDocument::factory()->for($application)->create([
        'file_path' => cleanupPrivateFile('candidate-documents/'.$application->id.'/proof.pdf'), 'verified_by' => $admin->id,
    ]);
    $score = CandidateScore::factory()->for($profile)->create([
        'evidence_path' => cleanupPrivateFile('candidate-scores/'.$profile->id.'/score.png'), 'verified_by' => $staff->id,
    ]);
    $exam = CandidateExamResult::factory()->for($profile)->create([
        'evidence_path' => cleanupPrivateFile('candidate-exam-results/'.$profile->id.'/exam.png'),
    ]);
    CandidateExamSubjectScore::factory()->for($exam, 'examResult')->create();
    $transcript = CandidateTranscript::factory()->for($profile)->create([
        'evidence_path' => cleanupPrivateFile('candidate-transcripts/'.$profile->id.'/legacy.png'),
    ]);
    CandidateTranscriptScore::factory()->for($transcript, 'transcript')->create();
    $transcript->evidenceImages()->create(['path' => cleanupPrivateFile('candidate-transcripts/'.$profile->id.'/page.png')]);
    CandidateCertificate::factory()->for($profile)->create(['evidence_path' => cleanupPrivateFile('candidate-certificates/'.$profile->id.'/certificate.png')]);
    CandidateAdmissionClaim::factory()->for($profile)->create(['evidence_path' => cleanupPrivateFile('candidate-admission-claims/'.$profile->id.'/claim.png')]);
    $wish = AdmissionWish::factory()->for($application)->create();
    AdmissionResult::factory()->for($wish, 'admissionWish')->create();
    $province = Province::create(['code' => '01', 'name' => 'Hà Nội']);
    HighSchool::create(['province_id' => $province->id, 'code' => '0101', 'name' => 'Trường kiểm thử']);
    $announcement = Announcement::factory()->create(['created_by' => $admin->id]);
    $announcement->users()->attach([$candidate->id, $staff->id]);
    cleanupNotification($candidate);
    $staffNotification = cleanupNotification($staff);
    ActivityLog::factory()->create(['user_id' => $staff->id, 'subject_type' => $score->getMorphClass(), 'subject_id' => $score->id]);
    ActivityLog::factory()->create(['user_id' => $candidate->id]);
    $catalogLog = ActivityLog::factory()->create(['user_id' => $staff->id, 'subject_type' => $application->admissionRound->getMorphClass(), 'subject_id' => $application->admission_round_id]);
    foreach ([$candidate, $staff] as $user) {
        DB::table('sessions')->insert(['id' => 'session-'.$user->id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => 1]);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'secret']);
    }
    cleanupPrivateFile('candidate-photos/42/unreferenced.png');
    cleanupPrivateFile('candidate-photos/5/admin.png');
    cleanupPrivateFile('livewire-tmp/unknown.png');
    $adminBefore = $admin->fresh()->getAttributes();
    $staffBefore = $staff->fresh()->getAttributes();
    $catalogs = [];
    foreach (['admission_rounds', 'majors', 'admission_methods', 'admission_programs', 'provinces', 'high_schools', 'announcements'] as $table) {
        $catalogs[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    $this->artisan('dev:clear-candidates')->expectsConfirmation(ClearCandidates::CONFIRMATION, 'yes')->assertSuccessful();

    expect(User::orderBy('id')->pluck('id')->all())->toBe([5, 9]);
    expect($admin->fresh()->getAttributes())->toBe($adminBefore);
    expect($staff->fresh()->getAttributes())->toBe($staffBefore);
    foreach (['candidate_profiles', 'applications', 'candidate_documents', 'candidate_scores', 'candidate_exam_results',
        'candidate_exam_subject_scores', 'candidate_transcripts', 'candidate_transcript_scores', 'candidate_transcript_evidence',
        'candidate_certificates', 'candidate_admission_claims', 'admission_wishes', 'admission_results'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    foreach ($catalogs as $table => $snapshot) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($snapshot);
    }
    expect(DB::table('notifications')->pluck('id')->all())->toBe([$staffNotification]);
    expect(ActivityLog::pluck('id')->all())->toBe([$catalogLog->id]);
    expect(DB::table('announcement_user')->pluck('user_id')->all())->toBe([$staff->id]);
    expect(DB::table('sessions')->pluck('user_id')->all())->toBe([$staff->id]);
    expect(DB::table('password_reset_tokens')->pluck('email')->all())->toBe([$staff->email]);
    expect(Storage::disk(CandidateFiles::DISK)->allFiles())->toBe(['candidate-photos/5/admin.png', 'livewire-tmp/unknown.png']);
    $this->artisan('dev:clear-candidates')->expectsOutput('No changes made.')->assertSuccessful();
});

test('cleanup rejects production and nondevelopment environments without touching candidates', function (string $environment) {
    Storage::fake(CandidateFiles::DISK);
    $candidate = User::factory()->create();
    $this->app['env'] = $environment;

    $this->artisan('dev:clear-candidates')->assertFailed();
    $this->assertModelExists($candidate);
})->with(['production', 'staging']);

test('cleanup requires interactive confirmation and declines safely', function () {
    Storage::fake(CandidateFiles::DISK);
    $candidate = User::factory()->create();
    $this->artisan('dev:clear-candidates', ['--no-interaction' => true])->assertFailed();
    $this->artisan('dev:clear-candidates')->expectsConfirmation(ClearCandidates::CONFIRMATION, 'no')->assertSuccessful();
    $this->assertModelExists($candidate);
});

test('dry run displays candidate counts without deleting anything', function () {
    Storage::fake(CandidateFiles::DISK);
    $candidate = User::factory()->create();
    $this->artisan('dev:clear-candidates', ['--dry-run' => true])
        ->expectsOutput('Candidate user IDs: '.$candidate->id)->expectsOutput('No changes made.')->assertSuccessful();
    $this->assertModelExists($candidate);
});

test('review and verification references do not make retained records candidate owned', function () {
    Storage::fake(CandidateFiles::DISK);
    $candidate = User::factory()->create();
    $staff = User::factory()->create(['role' => UserRole::Staff]);
    $retained = CandidateProfile::factory()->for($staff)->create(['verified_by' => $candidate->id]);
    $application = Application::factory()->for($retained)->create(['reviewed_by' => $candidate->id]);
    $score = CandidateScore::factory()->for($retained)->create(['verified_by' => $candidate->id]);

    $this->artisan('dev:clear-candidates')->expectsConfirmation(ClearCandidates::CONFIRMATION, 'yes')->assertSuccessful();

    $this->assertModelExists($staff);
    expect($retained->fresh()->verified_by)->toBeNull();
    expect($application->fresh()->reviewed_by)->toBeNull();
    expect($score->fresh()->verified_by)->toBeNull();
});

test('a file referenced by staff is retained even inside a candidate folder', function (bool $alias) {
    Storage::fake(CandidateFiles::DISK);
    $candidate = User::factory()->create();
    $path = cleanupPrivateFile('candidate-photos/'.$candidate->id.'/shared.png');
    CandidateProfile::factory()->for($candidate)->create(['photo_path' => $path]);
    $staffProfile = CandidateProfile::factory()->for(User::factory()->create(['role' => UserRole::Staff]))->create([
        'photo_path' => $alias ? str_replace('/shared.png', '/./shared.png', $path) : $path,
    ]);

    $this->artisan('dev:clear-candidates')->expectsConfirmation(ClearCandidates::CONFIRMATION, 'yes')->assertSuccessful();

    Storage::disk(CandidateFiles::DISK)->assertExists($path);
    $this->assertModelExists($staffProfile);
})->with([false, true]);

test('unsafe or nonowned candidate file references block cleanup', function (string $path) {
    Storage::fake(CandidateFiles::DISK);
    $candidate = User::factory()->create(['id' => 1]);
    $profile = CandidateProfile::factory()->for($candidate)->create(['photo_path' => $path]);

    $this->artisan('dev:clear-candidates')->assertFailed();
    $this->assertModelExists($candidate);
    $this->assertModelExists($profile);
})->with(['candidate-photos/9/staff.png', 'candidate-photos/1/../../secret.png']);

test('candidate authored announcements are protected instead of being cascaded away', function () {
    Storage::fake(CandidateFiles::DISK);
    $announcement = Announcement::factory()->create();

    $this->artisan('dev:clear-candidates')->assertFailed();
    $this->assertModelExists($announcement);
    $this->assertModelExists($announcement->creator);
});

test('an unknown cascading dependency blocks cleanup before confirmation', function () {
    Storage::fake(CandidateFiles::DISK);
    $candidate = User::factory()->create();
    Schema::create('unreviewed_candidate_records', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    });
    try {
        $this->artisan('dev:clear-candidates')->assertFailed();
        $this->assertModelExists($candidate);
    } finally {
        Schema::drop('unreviewed_candidate_records');
    }
});

test('database failure rolls back all candidate deletions and keeps private files', function () {
    Storage::fake(CandidateFiles::DISK);
    $candidate = User::factory()->create();
    $path = cleanupPrivateFile('candidate-photos/'.$candidate->id.'/photo.png');
    $profile = CandidateProfile::factory()->for($candidate)->create(['photo_path' => $path]);
    Event::listen(QueryExecuted::class, function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'delete from "users"')) {
            throw new RuntimeException('Simulated deletion failure');
        }
    });
    try {
        $this->artisan('dev:clear-candidates')->expectsConfirmation(ClearCandidates::CONFIRMATION, 'yes')->assertFailed();
        $this->assertModelExists($candidate);
        $this->assertModelExists($profile);
        Storage::disk(CandidateFiles::DISK)->assertExists($path);
    } finally {
        Event::forget(QueryExecuted::class);
    }
});

test('role changes after preview invalidate the confirmed plan', function () {
    Storage::fake(CandidateFiles::DISK);
    $candidate = User::factory()->create();
    $command = new class($candidate->id) extends ClearCandidates
    {
        public function __construct(private int $candidateId)
        {
            parent::__construct();
        }

        public function confirm(mixed $question, mixed $default = false): bool
        {
            DB::table('users')->where('id', $this->candidateId)->update(['role' => UserRole::Staff->value]);

            return true;
        }
    };
    $this->app->instance(ClearCandidates::class, $command);

    $this->artisan('dev:clear-candidates')->assertFailed();
    expect($candidate->fresh()->role)->toBe(UserRole::Staff);
});

test('a postcommit storage failure is reported without deleting unowned files on a repeated run', function () {
    Storage::fake(CandidateFiles::DISK);
    $candidate = User::factory()->create();
    $path = cleanupPrivateFile('candidate-photos/'.$candidate->id.'/photo.png');
    CandidateProfile::factory()->for($candidate)->create(['photo_path' => $path]);
    $disk = Storage::disk(CandidateFiles::DISK);
    $failingDisk = new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends FilesystemAdapter
    {
        public function delete(mixed $paths): bool
        {
            return false;
        }
    };
    Storage::set(CandidateFiles::DISK, $failingDisk);

    $this->artisan('dev:clear-candidates')->expectsConfirmation(ClearCandidates::CONFIRMATION, 'yes')
        ->expectsOutput('File retained for manual review: '.$path)->assertFailed();

    $this->assertModelMissing($candidate);
    Storage::disk(CandidateFiles::DISK)->assertExists($path);
    $this->artisan('dev:clear-candidates')->expectsOutput('No changes made.')->assertSuccessful();
    Storage::disk(CandidateFiles::DISK)->assertExists($path);
});

test('cleanup remains compatible before the additive transcript evidence migration', function () {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create();
    Schema::drop('candidate_transcript_evidence');

    $this->artisan('dev:clear-candidates')->expectsConfirmation(ClearCandidates::CONFIRMATION, 'yes')->assertSuccessful();

    $this->assertModelMissing($profile);
});
