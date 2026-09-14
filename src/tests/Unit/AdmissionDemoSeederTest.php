<?php

use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Major;
use App\Models\User;
use Database\Seeders\AdmissionDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class);

/** Use a new in-memory schema without the Feature suite's migrate:fresh hook. */
beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
});

test('demo catalogs include the requested admission variations', function () {
    $this->seed(AdmissionDemoSeeder::class);

    $this->assertDatabaseCount('admission_rounds', 3);
    $this->assertDatabaseCount('majors', 8);
    $this->assertDatabaseCount('admission_methods', 4);
    $this->assertDatabaseCount('admission_programs', 18);
    $this->assertDatabaseCount('users', 0);
    foreach (['draft', 'open', 'published'] as $status) {
        $this->assertDatabaseHas('admission_rounds', ['status' => $status]);
    }
    foreach ([true, false] as $active) {
        $this->assertDatabaseHas('majors', ['is_active' => $active]);
        $this->assertDatabaseHas('admission_methods', ['is_active' => $active]);
    }
    $this->assertDatabaseHas('admission_programs', ['quota' => 0, 'status' => 'inactive']);
    $this->assertDatabaseHas('admission_programs', ['minimum_score' => 23, 'previous_cutoff_score' => 26.125, 'tuition_fee' => 35000000, 'status' => 'active']);
    $this->assertDatabaseHas('admission_programs', ['minimum_score' => null, 'previous_cutoff_score' => null, 'tuition_fee' => null]);
    expect(AdmissionMethod::where('code', 'DEMO-HB-D01')->sole()->score_config)
        ->toBe(['weights' => ['MATH' => 1, 'LITERATURE' => 1, 'ENG' => 2]]);
    expect(AdmissionMethod::where('code', 'DEMO-THPT-A00')->sole()->score_config)
        ->toBe(['weights' => ['MATH' => 1, 'PHYSICS' => 1, 'CHEMISTRY' => 1]]);
    foreach (['DEMO-DGNL', 'DEMO-THANG'] as $code) {
        $this->assertDatabaseHas('admission_methods', ['code' => $code, 'score_config' => null]);
    }
    foreach (AdmissionRound::all() as $round) {
        expect($round->end_date->greaterThan($round->start_date))->toBeTrue();
        expect($round->result_date === null || $round->result_date->greaterThanOrEqualTo($round->end_date))->toBeTrue();
        expect($round->programs()->exists())->toBeTrue();
    }
    foreach (AdmissionProgram::with(['admissionRound', 'major', 'admissionMethod'])->get() as $program) {
        expect($program->admissionRound)->toBeInstanceOf(AdmissionRound::class);
        expect($program->major)->toBeInstanceOf(Major::class);
        expect($program->admissionMethod)->toBeInstanceOf(AdmissionMethod::class);
    }
});

test('rerunning restores demo values without duplicating records or changing unrelated data', function () {
    $user = User::factory()->create();
    $program = AdmissionProgram::factory()->create();
    $existing = collect([$user, $program, $program->admissionRound, $program->major, $program->admissionMethod]);
    $original = $existing->map(fn ($model) => $model->fresh()->getAttributes())->all();
    $this->seed(AdmissionDemoSeeder::class);
    $tables = ['admission_rounds', 'majors', 'admission_methods', 'admission_programs'];
    $ids = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->pluck('id')->all()]);
    $major = Major::where('code', 'DEMO-7480201')->sole();
    $major->update(['name' => 'Đã sửa để thử nghiệm']);

    $this->seed(AdmissionDemoSeeder::class);

    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->pluck('id')->all())->toBe($ids[$table]);
    }
    expect($major->fresh()->name)->toBe('Công nghệ thông tin');
    expect($existing->map(fn ($model) => $model->fresh()->getAttributes())->all())->toBe($original);
    $this->assertDatabaseCount('users', 1);
});
