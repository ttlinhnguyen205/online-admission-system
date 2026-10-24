<?php

use App\Actions\CalculateAdmissionWishScore;
use App\Actions\CandidateApplications;
use App\Actions\CandidateFiles;
use App\Actions\CandidateMajorOfferings;
use App\Actions\ProcessAdmissionRound;
use App\Livewire\Admin;
use App\Livewire\Candidate;
use App\Models\AdmissionMethod;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\CandidateScore;
use App\Models\Major;
use App\Models\User;
use Database\Seeders\AdmissionDemoSeeder;
use Database\Seeders\AdmissionTestingSeeder;
use Database\Seeders\HighSchoolSeeder;
use Database\Seeders\ProvinceSeeder;
use Database\Seeders\RealisticAdmissionCatalogSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
        throw new RuntimeException('Catalog tests require isolated SQLite :memory:.');
    }
    $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
    $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));
    Notification::fake();
    Storage::fake(CandidateFiles::DISK);
    $this->seed(AdmissionDemoSeeder::class);
});

test('catalog has the requested counts valid foreign keys and supported scoring contracts', function () {
    $this->seed(RealisticAdmissionCatalogSeeder::class);
    foreach (['admission_rounds' => 6, 'majors' => 23, 'admission_methods' => 7, 'admission_programs' => 63,
        'candidate_major_offerings' => 51, 'users' => 0, 'applications' => 0, 'admission_results' => 0] as $table => $count) {
        $this->assertDatabaseCount($table, $count);
    }
    $rounds = AdmissionRound::where('code', 'like', 'RAC26-%')->get();
    expect($rounds->pluck('status')->map->value->sort()->values()->all())->toBe(['closed', 'draft', 'open']);
    foreach ($rounds as $round) {
        expect($round->year)->toBe(2026)->and($round->nativeRegistrationState())->toBe('legacy');
        expect($round->start_date->lt($round->end_date))->toBeTrue()->and($round->result_date->gte($round->end_date))->toBeTrue();
        expect($round->programs()->count())->toBe(15);
        foreach ($round->programs()->with(['admissionMethod', 'major'])->get() as $program) {
            expect($program->major->code)->toStartWith('RAC26-')->and($program->major->is_active)->toBeTrue();
            $method = $program->admissionMethod;
            $descriptor = config('admission_engine.methods.'.$method->code);
            $subjects = collect($descriptor['subjects'])->mapWithKeys(fn ($subject) => [$subject => [['score' => '8.000']]])->all();
            $total = app(CalculateAdmissionWishScore::class)->calculate($descriptor, $method->score_config, ['subjects' => $subjects]);
            $maximum = $method->code === 'DEMO-THPT-A00' ? 30 : 40;
            expect($total)->toBe($maximum * 800);
            expect((float) $program->minimum_score)->toBeGreaterThan(0)->toBeLessThanOrEqual((float) $program->previous_cutoff_score);
            expect((float) $program->previous_cutoff_score)->toBeLessThanOrEqual($maximum);
            expect((float) $program->tuition_fee)->toBe((float) $program->major->default_tuition_fee);
            expect($program->quota)->toBeGreaterThan(0);
        }
    }
    foreach (AdmissionMethod::where('code', 'like', 'RAC26-%')->get() as $method) {
        expect($method->is_active)->toBeFalse()->and($method->score_config)->toBeNull()->and($method->programs()->count())->toBe(0);
    }
    expect(CandidateMajorOfferings::choices($rounds->firstWhere('code', 'RAC26-REGISTER')))->toHaveCount(15);
    expect(CandidateMajorOfferings::choices($rounds->firstWhere('code', 'RAC26-ENGINE')))->toHaveCount(0);
    expect(CandidateMajorOfferings::choices($rounds->firstWhere('code', 'RAC26-PLAN')))->toHaveCount(0);
    expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    Notification::assertNothingSent();
});

test('rerunning preserves administrative edits and never duplicates the catalog', function () {
    $this->seed(RealisticAdmissionCatalogSeeder::class);
    $round = AdmissionRound::where('code', 'RAC26-REGISTER')->sole();
    $round->update(['status' => 'closed', 'end_date' => '2026-10-22 17:00:00']);
    $round->programs()->first()->update(['quota' => 17]);
    Major::where('code', 'RAC26-7480107')->update(['is_active' => false]);
    $tables = ['admission_rounds', 'majors', 'admission_methods', 'admission_programs', 'candidate_major_offerings', 'activity_logs'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
    $this->seed(RealisticAdmissionCatalogSeeder::class);
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($rows);
    }
});

test('preexisting reserved codes fail safely without catalog writes', function () {
    Major::factory()->create(['code' => 'RAC26-7480201']);
    expect(fn () => $this->seed(RealisticAdmissionCatalogSeeder::class))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('admission_rounds', 3);
    $this->assertDatabaseCount('majors', 9);
    $this->assertDatabaseCount('admission_methods', 4);
});

test('changed engine contracts fail before any new rows are created', function () {
    config(['admission_engine.methods.DEMO-HB-D01.type' => 'unsupported']);
    expect(fn () => $this->seed(RealisticAdmissionCatalogSeeder::class))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('admission_rounds', 3);
    $this->assertDatabaseCount('majors', 8);
    $this->assertDatabaseCount('admission_methods', 4);
});

test('admin screens and candidate registration display the usable catalog', function () {
    $this->seed(RealisticAdmissionCatalogSeeder::class);
    $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
    Livewire::test(Admin\Majors::class)->set('search', 'RAC26-')->assertViewHas('records', fn ($records) => $records->total() === 15)->assertSee('Trí tuệ nhân tạo');
    Livewire::test(Admin\AdmissionMethods::class)->set('search', 'RAC26-')->assertViewHas('records', fn ($records) => $records->total() === 3)->assertSee('Xét kết quả đánh giá tư duy');
    Livewire::test(Admin\AdmissionMethods::class)->set('search', 'DEMO-THPT-A00')->assertSee('Điểm thi tốt nghiệp THPT');
    Livewire::test(Admin\AdmissionRounds::class)->set('search', 'RAC26-')->assertViewHas('records', fn ($records) => $records->total() === 3);
    $round = AdmissionRound::where('code', 'RAC26-REGISTER')->sole();
    Livewire::test(Admin\AdmissionPrograms::class)->set('roundFilter', (string) $round->id)->assertViewHas('records', fn ($records) => $records->total() === 15);
    $user = User::factory()->create();
    CandidateProfile::factory()->for($user)->create();
    $this->actingAs($user);
    Livewire::test(Candidate\Applications::class)->assertSee($round->name);
    $application = app(CandidateApplications::class)->create(['admission_round_id' => $round->id]);
    $component = Livewire::test(Candidate\ApplicationDetails::class, ['application' => $application->id])
        ->assertViewHas('offerings', fn ($choices) => $choices->count() === 15)->assertSee('Kỹ thuật phần mềm')->assertDontSee('Xét kết quả đánh giá tư duy');
    foreach ($round->candidateMajorOfferings()->orderBy('id')->take(3)->get() as $offering) {
        $component->set('form.candidate_major_offering_id', (string) $offering->id)->call('addWish')->assertHasNoErrors();
    }
    expect($application->wishes()->orderBy('priority')->pluck('priority')->all())->toBe([1, 2, 3]);
    $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
    expect(CandidateMajorOfferings::choices($round))->toHaveCount(0);
});

test('catalog keeps the existing hundred candidates and processed engine results unchanged', function () {
    config(['admission_testing.password' => bin2hex(random_bytes(16))]);
    $this->seed([ProvinceSeeder::class, HighSchoolSeeder::class, AdmissionTestingSeeder::class]);
    $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
    $round = AdmissionRound::where('code', 'AT-V1-ENGINE')->sole();
    $engine = app(ProcessAdmissionRound::class);
    $summary = $engine->process($round->id, $engine->preview($round->id)['fingerprint']);
    expect($summary['admitted'])->toBe(65)->and($summary['results'])->toBe(210);
    $tables = ['users', 'candidate_profiles', 'applications', 'admission_wishes', 'candidate_scores', 'admission_results',
        'candidate_documents', 'candidate_exam_results', 'candidate_exam_subject_scores', 'candidate_transcripts', 'candidate_transcript_scores',
        'candidate_transcript_evidence', 'admission_rounds', 'majors', 'admission_methods', 'admission_programs', 'candidate_major_offerings', 'activity_logs'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()]);
    $this->seed(RealisticAdmissionCatalogSeeder::class);
    $this->seed(RealisticAdmissionCatalogSeeder::class);
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->whereIn('id', $rows->pluck('id'))->orderBy('id')->get()->toJson())->toBe($rows->toJson());
    }
    expect($engine->preview($round->id)['summary'])->toBe($summary);
});

test('new round supports preference competition below minimum scores and missing score blockers', function () {
    $this->seed(RealisticAdmissionCatalogSeeder::class);
    $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
    $round = AdmissionRound::where('code', 'RAC26-ENGINE')->sole();
    $programs = $round->programs()->orderBy('id')->take(3)->get();
    $applications = [];
    foreach (['9.000', '8.000', '7.000', '5.000', '6.500', '6.500'] as $score) {
        $application = Application::factory()->create(['admission_round_id' => $round->id, 'status' => 'verified', 'submitted_at' => now()]);
        foreach ($programs as $index => $program) {
            AdmissionWish::factory()->create(['application_id' => $application->id, 'admission_program_id' => $program->id, 'priority' => $index + 1]);
        }
        foreach (['MATH', 'PHYSICS', 'CHEMISTRY'] as $subject) {
            CandidateScore::factory()->create(['candidate_profile_id' => $application->candidate_profile_id, 'score_type' => 'thpt', 'subject_code' => $subject,
                'score' => $score, 'exam_year' => 2026, 'verified' => true]);
        }
        $applications[] = $application;
    }
    $engine = app(ProcessAdmissionRound::class);
    $score = $applications[0]->candidateProfile->scores()->first();
    $score->update(['verified' => false]);
    $preview = $engine->preview($round->id);
    expect($preview['blockers'])->not->toBe([]);
    expect(fn () => $engine->process($round->id, $preview['fingerprint']))->toThrow(ValidationException::class);
    $score->update(['verified' => true]);
    $applications[0]->candidateProfile->scores()->update(['score' => '8.000']);
    $preview = $engine->preview($round->id);
    expect(implode(' ', $preview['blockers']))->toContain('bằng điểm');
    expect(fn () => $engine->process($round->id, $preview['fingerprint']))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('admission_results', 0);
    $applications[0]->candidateProfile->scores()->update(['score' => '9.000']);
    $summary = $engine->process($round->id, $engine->preview($round->id)['fingerprint']);
    expect($summary['admitted'])->toBe(3)->and($summary['results'])->toBe(18);
    foreach ($applications as $index => $application) {
        $winners = $application->wishes()->where('status', 'admitted')->get();
        if ($index < 3) {
            expect($winners->sole()->admission_program_id)->toBe($programs[$index]->id);
        } else {
            expect($winners)->toHaveCount(0);
        }
    }
    expect($applications[3]->wishes()->where('status', 'ineligible')->count())->toBe(3);
    Notification::assertNothingSent();
});
