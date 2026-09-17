<?php

use App\Actions\AdmissionEngineSnapshot;
use App\Actions\ProcessAdmissionRound;
use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\AdmissionResult;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/AdmissionEngineFixtures.php';

test('a successful atomic run completes verified applications and leaves drafts and foreign rounds unchanged', function () {
    $this->freezeTime();
    $program = engineProgram();
    $application = engineApplication($program);
    $draft = Application::factory()->create(['admission_round_id' => $program->admission_round_id, 'status' => 'draft']);
    $foreign = engineApplication(engineProgram(), '999.000');
    $draftBefore = $draft->fresh()->getAttributes();
    $foreignBefore = $foreign->fresh()->getAttributes();
    $this->actingAs($actor = User::factory()->create(['role' => UserRole::Admin]));
    $states = [];
    Event::listen('eloquent.updating: '.Application::class, function (Application $record) use (&$states): void {
        $states[] = $record->status->value;
    });

    $summary = engineRun($program);

    expect($states)->toBe(['processing', 'completed']);
    expect($application->fresh()->status)->toBe(ApplicationStatus::Completed);
    expect($draft->fresh()->getAttributes())->toBe($draftBefore);
    expect($foreign->fresh()->getAttributes())->toBe($foreignBefore);
    expect($program->admissionRound->fresh()->status)->toBe(AdmissionRoundStatus::Processing);
    expect($program->fresh()->quota)->toBe(1);
    expect($summary)->toMatchArray(['included' => 1, 'excluded_drafts' => 1, 'results' => 1, 'admitted' => 1, 'not_admitted' => 0]);
    $result = AdmissionResult::query()->sole();
    expect($result->only(['final_score', 'rank', 'decision', 'published_at', 'confirmed_at']))
        ->toMatchArray(['final_score' => '8.000', 'rank' => 1, 'published_at' => null, 'confirmed_at' => null]);
    expect($result->decision->value)->toBe('admitted');
    expect($result->decided_at->format('Y-m-d H:i:s'))->toBe(now()->format('Y-m-d H:i:s'));
    expect(ActivityLog::query()->orderBy('id')->pluck('action')->all())->toBe(['admission_engine.started', 'admission_engine.completed']);
    expect(ActivityLog::query()->pluck('user_id')->unique()->all())->toBe([$actor->id]);
    $this->assertDatabaseMissing('applications', ['status' => 'processing']);
});

test('new runs require explicit closed state regardless of deadlines', function (string $status) {
    $program = engineProgram();
    engineApplication($program);
    $program->admissionRound->update(['status' => $status, 'start_date' => now()->subDays(10), 'end_date' => now()->subDays(5)]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    expect(fn () => engineRun($program))->toThrow(ValidationException::class);

    expect($program->admissionRound->fresh()->status->value)->toBe($status);
    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('activity_logs', 0);
})->with(['draft', 'open', 'processing', 'published']);

test('unresolved application states block the entire round and report counts', function (string $state) {
    $program = engineProgram();
    engineApplication($program);
    Application::factory()->count(2)->create(['admission_round_id' => $program->admission_round_id, 'status' => $state]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $engine = app(ProcessAdmissionRound::class);

    $preview = $engine->preview($program->admission_round_id);

    expect($preview[$state])->toBe(2);
    expect($preview['blockers'])->toContain('Unresolved '.$state.' applications: 2.');
    expect(fn () => $engine->process($program->admission_round_id, $preview['fingerprint']))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('activity_logs', 0);
})->with(['submitted', 'under_review', 'needs_revision']);

test('zero verified applications cannot start processing', function () {
    $program = engineProgram();
    Application::factory()->create(['admission_round_id' => $program->admission_round_id, 'status' => 'draft']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    expect(fn () => engineRun($program))->toThrow(ValidationException::class, 'No verified applications');

    $this->assertDatabaseCount('activity_logs', 0);
});

test('later inactive catalog and candidate flags do not disqualify reviewed wishes', function () {
    $program = engineProgram();
    $application = engineApplication($program);
    $program->update(['status' => 'inactive']);
    $program->major->update(['is_active' => false]);
    $program->admissionMethod->update(['is_active' => false]);
    $application->candidateProfile->user->update(['status' => UserStatus::Locked]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    engineRun($program);

    $this->assertDatabaseHas('admission_wishes', ['application_id' => $application->id, 'status' => 'admitted']);
});

/** Structural states forbidden by current FK/unique constraints are injected only into the read snapshot. */
test('structural corruption blocks without repairs or partial writes', function (string $corruption, string $message) {
    $program = engineProgram();
    $application = engineApplication($program);
    $second = engineProgram($program->admissionRound);
    AdmissionWish::factory()->for($application)->for($second)->create(['priority' => 2]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $this->app->instance(AdmissionEngineSnapshot::class, new class($corruption) extends AdmissionEngineSnapshot
    {
        public function __construct(private string $corruption) {}

        public function load(int $id, bool $lock = false): array
        {
            $data = parent::load($id, $lock);
            match ($this->corruption) {
                'no wishes' => $data['wishes'] = $data['wishes']->take(0),
                'gap' => $data['wishes']->last()->priority = 3,
                'invalid priority' => $data['wishes']->first()->priority = 0,
                'duplicate priority' => $data['wishes']->last()->priority = 1,
                'duplicate program' => $data['wishes']->last()->admission_program_id = $data['wishes']->first()->admission_program_id,
                'missing profile' => $data['profiles'] = $data['profiles']->take(0),
                'wrong application round' => $data['applications']->first()->admission_round_id = $id + 100,
                'missing program' => $data['programs'] = $data['programs']->take(0),
                'missing major' => $data['majors'] = $data['majors']->take(0),
                'missing method' => $data['methods'] = $data['methods']->take(0),
                'cross round program' => $data['programs']->first()->admission_round_id = $id + 100,
            };

            return $data;
        }
    });

    expect(fn () => engineRun($program))->toThrow(ValidationException::class, $message);

    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('activity_logs', 0);
    $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'verified']);
    $this->assertDatabaseHas('admission_rounds', ['id' => $program->admission_round_id, 'status' => 'closed']);
    expect($application->wishes()->orderBy('priority')->pluck('priority')->all())->toBe([1, 2]);
})->with([
    ['no wishes', 'nonempty'], ['gap', 'contiguous'], ['invalid priority', 'contiguous'],
    ['duplicate priority', 'unique'], ['duplicate program', 'unique'],
    ['missing profile', 'relationships'], ['wrong application round', 'relationships'],
    ['missing program', 'relationship'], ['missing major', 'relationship'], ['missing method', 'relationship'], ['cross round program', 'cross-round'],
]);

test('a persisted cross round wish blocks final processing', function () {
    $program = engineProgram();
    $application = engineApplication($program);
    $foreign = engineProgram();
    $application->wishes()->sole()->update(['admission_program_id' => $foreign->id]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    expect(fn () => engineRun($program))->toThrow(ValidationException::class, 'cross-round');

    $this->assertDatabaseCount('admission_results', 0);
});

test('iterative allocation persists fallback displacement and rejected lower preferences', function () {
    $a = engineProgram();
    $b = engineProgram($a->admissionRound);
    $c = engineProgram($a->admissionRound);
    $zero = engineProgram($a->admissionRound, quota: 0);
    $first = engineApplication($a, '20');
    AdmissionWish::factory()->for($first)->for($b)->create(['priority' => 2]);
    AdmissionWish::factory()->for($first)->for($c)->create(['priority' => 3]);
    $second = engineApplication($b, '10');
    AdmissionWish::factory()->for($second)->for($c)->create(['priority' => 2]);
    $third = engineApplication($zero, '30');
    AdmissionWish::factory()->for($third)->for($a)->create(['priority' => 2]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $summary = engineRun($a);

    expect($summary)->toMatchArray(['admitted' => 3, 'not_admitted' => 4, 'results' => 7]);
    foreach ([[$first, $b], [$second, $c], [$third, $a]] as [$application, $winner]) {
        expect($application->wishes()->where('status', 'admitted')->sole()->admission_program_id)->toBe($winner->id);
        expect($application->fresh()->status)->toBe(ApplicationStatus::Completed);
    }
    $this->assertDatabaseCount('admission_results', 7);
    $this->assertDatabaseMissing('admission_wishes', ['status' => 'pending']);
    $this->assertDatabaseMissing('admission_results', ['decision' => 'waiting']);
    $this->assertDatabaseHas('admission_wishes', ['application_id' => $first->id, 'admission_program_id' => $c->id, 'status' => 'rejected']);
    expect($a->fresh()->quota)->toBe(1);
    expect($zero->fresh()->quota)->toBe(0);
    expect(AdmissionResult::query()->whereNotNull('published_at')->count())->toBe(0);
    expect(AdmissionResult::query()->whereNotNull('confirmed_at')->count())->toBe(0);
});

test('tie blockers detected in preview prevent every write', function () {
    $program = engineProgram(quota: 2);
    engineApplication($program, '27.500');
    engineApplication($program, '27.000');
    engineApplication($program, '27.000');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $engine = app(ProcessAdmissionRound::class);

    $preview = $engine->preview($program->admission_round_id);

    expect(implode(' ', $preview['blockers']))->toContain('Equal-score tie at quota boundary');
    expect(fn () => $engine->process($program->admission_round_id, $preview['fingerprint']))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('activity_logs', 0);
});

test('successful reruns return the immutable summary without writing or recomputing live inputs', function () {
    $program = engineProgram();
    $application = engineApplication($program);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $first = engineRun($program);
    $program->update(['quota' => 0, 'minimum_score' => '99999.999']);
    $program->admissionMethod->update(['score_config' => ['now' => 'unsupported']]);
    $application->candidateProfile->scores()->update(['score' => '999.000', 'verified' => false]);
    $before = AdmissionResult::query()->sole()->getAttributes();
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $writes = [];

    $second = app(ProcessAdmissionRound::class)->process($program->admission_round_id, 'old-preview-token');

    expect($second)->toBe($first);
    expect($writes)->toBe([]);
    expect(AdmissionResult::query()->sole()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('admission_results', 1);
    $this->assertDatabaseCount('activity_logs', 2);
});

test('partial results without completed history block replacement', function () {
    $program = engineProgram();
    $application = engineApplication($program);
    $result = AdmissionResult::factory()->for($application->wishes()->sole())->create();
    $before = $result->fresh()->getAttributes();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    expect(fn () => engineRun($program))->toThrow(ValidationException::class, 'Existing decisions cannot be replaced');

    expect($result->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('activity_logs', 0);
});

test('published confirmed or manually reversed completed runs block rather than rewrite history', function (string $change) {
    $program = engineProgram();
    $application = engineApplication($program);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    engineRun($program);
    match ($change) {
        'published' => AdmissionResult::query()->update(['published_at' => now()]),
        'confirmed' => AdmissionResult::query()->update(['confirmed_at' => now()]),
        'round reversal' => $program->admissionRound->update(['status' => 'closed']),
        'application reversal' => $application->fresh()->update(['status' => 'verified']),
        'wish reversal' => $application->wishes()->update(['status' => 'pending', 'calculated_score' => null]),
        'result alteration' => AdmissionResult::query()->update(['final_score' => '999.000']),
        'missing completion' => ActivityLog::query()->where('action', 'admission_engine.completed')->update(['action' => 'unrecognized']),
        'altered summary' => ActivityLog::query()->where('action', 'admission_engine.completed')->update(['new_values' => json_encode(['run_id' => 'forged'])]),
    };
    $before = AdmissionResult::query()->sole()->getAttributes();

    expect(fn () => engineRun($program))->toThrow(ValidationException::class);

    expect(AdmissionResult::query()->sole()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('admission_results', 1);
    $this->assertDatabaseCount('activity_logs', 2);
})->with(['published', 'confirmed', 'round reversal', 'application reversal', 'wish reversal', 'result alteration', 'missing completion', 'altered summary']);

test('stale previews reject changed processing inputs and a fresh preview uses current values', function (string $change) {
    $program = engineProgram();
    $application = engineApplication($program);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $engine = app(ProcessAdmissionRound::class);
    $preview = $engine->preview($program->admission_round_id);
    match ($change) {
        'score' => $application->candidateProfile->scores()->update(['score' => '9.000']),
        'quota' => $program->update(['quota' => 0]),
        'minimum' => $program->update(['minimum_score' => '9.000']),
        'cohort' => engineApplication($program, '9.000'),
    };

    expect(fn () => $engine->process($program->admission_round_id, $preview['fingerprint']))->toThrow(ValidationException::class, 'inputs changed');

    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('activity_logs', 0);
    $summary = engineRun($program);
    expect($summary['results'])->toBe($change === 'cohort' ? 2 : 1);
    if ($change === 'quota' || $change === 'minimum') {
        expect($summary['admitted'])->toBe(0);
    }
    if ($change === 'score') {
        expect(AdmissionResult::query()->sole()->final_score)->toBe('9.000');
    }
})->with(['score', 'quota', 'minimum', 'cohort']);

test('persistence and audit failures roll back all engine writes', function (string $failure) {
    $program = engineProgram(quota: 2);
    $application = engineApplication($program);
    engineApplication($program, '9.000');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $resultSaves = 0;
    Event::listen('eloquent.creating: '.AdmissionResult::class, function () use ($failure, &$resultSaves) {
        $resultSaves++;
        if ($failure === 'second result' && $resultSaves === 2) {
            return false;
        }
    });
    Event::listen('eloquent.creating: '.ActivityLog::class, function (ActivityLog $log) use ($failure) {
        if ($log->action === $failure) {
            return false;
        }
    });

    expect(fn () => engineRun($program))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('activity_logs', 0);
    $this->assertDatabaseHas('admission_rounds', ['id' => $program->admission_round_id, 'status' => 'closed']);
    expect($application->fresh()->status)->toBe(ApplicationStatus::Verified);
    $this->assertDatabaseMissing('applications', ['status' => 'completed']);
    $this->assertDatabaseMissing('applications', ['status' => 'processing']);
    $this->assertDatabaseMissing('admission_wishes', ['status' => 'admitted']);
    expect(AdmissionWish::query()->whereNotNull('calculated_score')->count())->toBe(0);
})->with(['second result', 'admission_engine.started', 'admission_engine.completed']);

test('audit metadata contains aggregate contract evidence without private candidate information', function () {
    $program = engineProgram();
    $application = engineApplication($program);
    $application->candidateProfile->update(['citizen_id' => '123456789123', 'phone' => '0901234567', 'address' => 'PRIVATE ADDRESS', 'photo_path' => 'private/photo.png']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $summary = engineRun($program);

    expect($summary['algorithm_version'])->toBe('candidate-proposals-v1');
    expect($summary['scoring_version'])->toBe('explicit-aliases-round-year-half-up-v1');
    expect(strlen($summary['input_fingerprint']))->toBe(64);
    expect(strlen($summary['outcome_fingerprint']))->toBe(64);
    $metadata = ActivityLog::query()->where('action', 'admission_engine.completed')->sole()->new_values;
    expect($metadata)->toBe($summary);
    expect(json_encode($metadata))->not->toContain('123456789123', '0901234567', 'PRIVATE ADDRESS', 'private/photo.png', 'citizen_id', 'document', 'phone');
});

test('ranking persisted for every eligible wish is competition ranking within its program', function () {
    $program = engineProgram(quota: 4);
    foreach (['28.000', '27.000', '27.000', '26.500'] as $score) {
        engineApplication($program, $score);
    }
    $other = engineProgram($program->admissionRound, 'DEMO-THPT-A00');
    engineApplication($other, '1.000');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    engineRun($program);

    expect(AdmissionResult::query()->orderBy('id')->pluck('rank')->all())->toBe([1, 2, 2, 4, 1]);
    expect(AdmissionResult::query()->orderBy('id')->pluck('final_score')->all())->toBe(['28.000', '27.000', '27.000', '26.500', '3.000']);
});

test('a changed valid weight configuration requires a fresh preview then uses current weights', function () {
    $program = engineProgram(code: 'DEMO-THPT-A00');
    engineApplication($program, '8.000');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $engine = app(ProcessAdmissionRound::class);
    $preview = $engine->preview($program->admission_round_id);
    $program->admissionMethod->update(['score_config' => ['weights' => ['MATH' => 2, 'PHYSICS' => 1, 'CHEMISTRY' => 1]]]);

    expect(fn () => $engine->process($program->admission_round_id, $preview['fingerprint']))->toThrow(ValidationException::class, 'inputs changed');

    $this->assertDatabaseCount('admission_results', 0);
    engineRun($program);
    expect(AdmissionResult::query()->sole()->final_score)->toBe('32.000');
});

test('preview uses bounded batch queries as cohort size grows', function () {
    $program = engineProgram(quota: 30);
    engineApplication($program);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $engine = app(ProcessAdmissionRound::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $engine->preview($program->admission_round_id);
    $small = count(DB::getQueryLog());
    DB::disableQueryLog();
    for ($index = 0; $index < 10; $index++) {
        engineApplication($program);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();

    $preview = $engine->preview($program->admission_round_id);

    expect(count(DB::getQueryLog()))->toBe($small);
    expect($preview['verified'])->toBe(11);
    expect($preview['blockers'])->toBe([]);
    DB::disableQueryLog();
});
