<?php

namespace App\Console\Commands;

use App\Actions\AdmissionCatalogLock;
use App\Actions\EvaluationTemplateRegistry;
use App\Actions\NativeRegistrationReadiness;
use App\Enums\AdmissionRoundStatus;
use App\Models\ActivityLog;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\CandidateMajorOffering;
use App\Models\EvaluationRuleVersion;
use App\Models\Major;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PrepareNativeMultiDemo extends Command
{
    public const CODE = 'DEMO-2026-NATIVE-MULTI';

    protected $signature = 'admission:prepare-native-multi-demo {--dry-run} {--apply}
        {--confirm= : Exact round code} {--development-database= : Exact development database name}
        {--backup-confirmed : Suitable backup verified} {--admin= : Active verified Admin ID for audit}';

    protected $description = 'Prepare native multi-method demo catalog; read-only by default';

    public function handle(EvaluationTemplateRegistry $templates, NativeRegistrationReadiness $readiness): int
    {
        try {
            $this->guards();
            $write = $this->option('apply') && ! $this->option('dry-run');
            $actor = null;
            if ($write) {
                $this->check($this->option('confirm') === self::CODE, 'Exact --confirm round code required.');
                $this->check($this->option('development-database') === DB::connection()->getDatabaseName(), 'Confirm exact --development-database.');
                $this->check((bool) $this->option('backup-confirmed'), '--backup-confirmed required.');
                $actor = User::query()->find($this->option('admin'));
                $this->check($actor instanceof User && $actor->isAdmin() && $actor->isActive() && $actor->hasVerifiedEmail(), 'Active verified --admin required.');
                DB::transaction(function () use ($templates, $readiness, $actor): void {
                    AdmissionCatalogLock::acquire();
                    $this->guards();
                    assert($actor instanceof User);
                    $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                    $this->check($actor->isAdmin() && $actor->isActive() && $actor->hasVerifiedEmail(), 'Admin access changed.');
                    $this->plan($templates, $readiness, true, $actor);
                }, 3);
            } else {
                $this->plan($templates, $readiness, false, null);
            }
            $this->info($write ? 'Committed. Round remains Draft / legacy.' : 'READ-ONLY preview: no database writes.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function guards(): void
    {
        $connection = DB::connection();
        $test = app()->environment('testing') && app()->runningUnitTests() && $connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:';
        $this->check($test || (app()->environment('local') && $connection->getDriverName() === 'mysql'
            && in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)), 'Local MySQL loopback required (or isolated in-memory tests).');
        $this->check(config('app.timezone') === 'Asia/Ho_Chi_Minh', 'Timezone must be Asia/Ho_Chi_Minh.');
        foreach (['2026_10_09_233710_create_admission_quota_tables', '2026_10_10_002531_create_native_registration_tables', '2026_10_10_163034_add_native_registration_activation_to_admission_rounds'] as $migration) {
            $this->check(DB::table('migrations')->where('migration', $migration)->exists(), 'Required migration missing: '.$migration);
        }
        $this->check(Schema::hasColumns('admission_rounds', ['native_registration_state']) && Schema::hasColumns('admission_programs', ['evaluation_rule_version_id']), 'Required schema missing.');
        foreach (['admission_rounds' => ['code'], 'admission_methods' => ['code'], 'admission_programs' => ['admission_round_id', 'major_id', 'admission_method_id'], 'candidate_major_offerings' => ['admission_round_id', 'major_id']] as $table => $columns) {
            $this->check(collect(Schema::getIndexes($table))->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === $columns), 'Required unique constraint missing: '.$table);
        }
        foreach (['admission_programs' => [
            ['columns' => ['evaluation_rule_version_id', 'admission_method_id'], 'foreign_table' => 'evaluation_rule_versions', 'foreign_columns' => ['id', 'admission_method_id']],
            ['columns' => ['admission_round_id'], 'foreign_table' => 'admission_rounds', 'foreign_columns' => ['id']],
            ['columns' => ['major_id'], 'foreign_table' => 'majors', 'foreign_columns' => ['id']],
            ['columns' => ['admission_method_id'], 'foreign_table' => 'admission_methods', 'foreign_columns' => ['id']],
        ], 'candidate_major_offerings' => [
            ['columns' => ['admission_round_id'], 'foreign_table' => 'admission_rounds', 'foreign_columns' => ['id']],
            ['columns' => ['major_id'], 'foreign_table' => 'majors', 'foreign_columns' => ['id']],
        ]] as $table => $expected) {
            $foreignKeys = Schema::getForeignKeys($table);
            foreach ($expected as $key) {
                $this->check(collect($foreignKeys)->contains(fn (array $actual): bool => $actual['columns'] === $key['columns']
                    && $actual['foreign_table'] === $key['foreign_table'] && $actual['foreign_columns'] === $key['foreign_columns']), 'Required foreign key missing: '.$table);
            }
        }
    }

    /** @param array<string, mixed> $attributes */
    private function record(Model $model, array $attributes, bool $write, ?User $actor): void
    {
        if ($model->exists) {
            foreach ($attributes as $key => $value) {
                $actual = $model->getAttribute($key);
                if (in_array($key, ['start_date', 'end_date'], true)) {
                    $actual = $actual->format('Y-m-d H:i:s');
                }
                $this->check(($actual === null || $value === null) ? $actual === $value : $actual == $value, 'Conflict: '.$model->getTable().'.'.$key.' differs; no overwrite.');
            }
            $this->line('KEEP '.$model->getTable().' #'.$model->getKey());

            return;
        }
        $this->line('INSERT '.$model->getTable().' '.json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $model->fill($attributes);
        if ($write) {
            $model->save();
            ActivityLog::query()->create(['user_id' => $actor?->id, 'action' => 'native_multi_demo.created', 'subject_type' => $model->getMorphClass(),
                'subject_id' => $model->getKey(), 'new_values' => ['round_code' => self::CODE, 'attributes' => $attributes, 'demo_only' => true], 'created_at' => now()]);
        }
    }

    private function plan(EvaluationTemplateRegistry $templates, NativeRegistrationReadiness $readiness, bool $write, ?User $actor): void
    {
        $major = Major::query()->where('code', 'DEMO-7340301')->when($write, fn ($q) => $q->lockForUpdate())->first();
        $thpt = AdmissionMethod::query()->where('code', 'DEMO-THPT-A00')->when($write, fn ($q) => $q->lockForUpdate())->first();
        $this->check($major instanceof Major && $major->is_active && $major->name === 'Kế toán', 'Active demo Accounting major required.');
        $this->check($thpt instanceof AdmissionMethod && $thpt->is_active, 'Active DEMO-THPT-A00 required.');
        assert($major instanceof Major && $thpt instanceof AdmissionMethod);
        $rule = EvaluationRuleVersion::query()->where('admission_method_id', $thpt->id)->where('version', 1)->when($write, fn ($q) => $q->lockForUpdate())->first();
        $this->check($templates->validApproved($rule, $thpt->id) && $rule?->template_identifier === 'THPT_SCORE'
            && $rule->template_version === 1 && $rule->payload['subjects'] === ['CHEMISTRY', 'MATH', 'PHYSICS'], 'Valid approved THPT A00 V1 required.');
        assert($rule instanceof EvaluationRuleVersion);
        $round = AdmissionRound::query()->where('code', self::CODE)->first() ?? new AdmissionRound;
        if ($round->exists) {
            $this->check($round->nativeRegistrationState() === 'legacy' && ! $round->applications()->exists()
                && ! $round->programs()->whereHas('wishes')->exists() && ! $round->candidateMajorOfferings()->whereHas('wishes')->exists(), 'Existing round mode/applications/wishes conflict.');
            $this->check(! ActivityLog::query()->where('subject_type', $round->getMorphClass())->where('subject_id', $round->id)
                ->whereIn('action', ['admission_engine.started', 'admission_engine.completed'])->exists(), 'Existing engine history.');
        }
        $this->record($round, ['code' => self::CODE, 'name' => 'Kiểm thử đăng ký đa phương thức Native 2026', 'year' => 2026,
            'status' => AdmissionRoundStatus::Draft, 'start_date' => '2026-10-10 00:00:00', 'end_date' => '2026-10-31 23:59:59'], $write, $actor);
        $method = AdmissionMethod::query()->where('code', 'DEMO-HB-EQ1')->when($write, fn ($q) => $q->lockForUpdate())->first() ?? new AdmissionMethod;
        $this->record($method, ['code' => 'DEMO-HB-EQ1', 'name' => 'Học bạ THPT – Demo 3 môn hệ số 1',
            'description' => 'DEMO ONLY – NOT OFFICIAL ADMISSION POLICY. Source: candidate_transcripts + candidate_transcript_scores; TRANSCRIPT_SCORE v1. D01: MATH + LITERATURE + ENG, weights 1–1–1. Grade/year/thresholds require Admin approval.',
            'score_config' => ['weights' => ['MATH' => 1, 'LITERATURE' => 1, 'ENG' => 1]], 'is_active' => true], $write, $actor);
        $this->check(! $method->exists || ! $method->programs()->where('admission_round_id', '!=', $round->id ?? 0)->exists(), 'Demo method already used outside the planned round.');
        $this->check(! $round->exists || ! $round->programs()->where(fn ($q) => $q->where('major_id', '!=', $major->id)
            ->orWhereNotIn('admission_method_id', [$thpt->id, $method->id ?? 0]))->exists(), 'Unexpected program in round.');
        foreach ([$thpt, $method] as $accepted) {
            $program = $round->exists && $accepted->exists ? AdmissionProgram::query()->where('admission_round_id', $round->id)->where('major_id', $major->id)->where('admission_method_id', $accepted->id)->first() : null;
            $program ??= new AdmissionProgram;
            $desired = $accepted->id === $thpt->id ? $rule->id : null;
            if ($program->exists && $accepted->id !== $thpt->id && $program->evaluation_rule_version_id !== null) {
                $this->check($readiness->programReady($program) && $program->evaluationRule?->template_identifier === 'TRANSCRIPT_SCORE'
                    && $program->evaluationRule->payload['subjects'] === ['ENG', 'LITERATURE', 'MATH'], 'Invalid existing transcript binding.');
                $desired = $program->evaluation_rule_version_id;
            }
            $this->check(! $program->exists || $program->evaluation_rule_version_id === $desired, 'Conflicting program rule.');
            $new = ! $program->exists;
            $this->record($program, ['admission_round_id' => $round->id, 'major_id' => $major->id, 'admission_method_id' => $accepted->id,
                'quota' => 0, 'minimum_score' => null, 'previous_cutoff_score' => null, 'tuition_fee' => null, 'status' => 'active'], $write, $actor);
            if ($write && $new && $desired !== null) {
                $program->setAttribute('evaluation_rule_version_id', $desired);
                $program->save();
                ActivityLog::query()->create(['user_id' => $actor?->id, 'action' => 'native_multi_demo.rule_bound', 'subject_type' => $program->getMorphClass(),
                    'subject_id' => $program->id, 'new_values' => ['evaluation_rule_version_id' => $desired, 'round_code' => self::CODE], 'created_at' => now()]);
            }
            $this->line('RULE '.$accepted->code.': '.($desired ?? 'MISSING approved TRANSCRIPT_SCORE: Admin create/approve/bind required'));
        }
        $this->check(! $round->exists || ! $round->candidateMajorOfferings()->where('major_id', '!=', $major->id)->exists(), 'Unexpected offering in round.');
        $offering = $round->exists ? CandidateMajorOffering::query()->where('admission_round_id', $round->id)->where('major_id', $major->id)->first() : null;
        $offering ??= new CandidateMajorOffering;
        $this->record($offering, ['admission_round_id' => $round->id, 'major_id' => $major->id, 'is_selectable' => true, 'admission_program_id' => null], $write, $actor);
        if ($offering->exists) {
            $this->check(! $offering->quotaVersions()->exists(), 'Quota configuration exists; this catalog plan does not manage quotas.');
            $this->check($round->fresh()->nativeRegistrationState() === 'legacy' && $round->fresh()->getAttribute('status') === AdmissionRoundStatus::Draft, 'Postcondition failed: round must remain Draft / legacy.');
            $state = $readiness->check($offering);
            $this->check($state['total'] === 2, 'Postcondition failed: expected two accepted methods.');
            $this->line('READINESS '.json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('READINESS planned NOT READY: accepted=2, valid=1, missing=1, unsupported=0.');
        }
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
