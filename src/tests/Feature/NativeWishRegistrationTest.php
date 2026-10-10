<?php

use App\Actions\AdmissionEngineSnapshot;
use App\Actions\CandidateApplications;
use App\Actions\CandidateWishes;
use App\Actions\NativeWishRegistration;
use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Livewire\Candidate\ApplicationDetails;
use App\Livewire\Candidate\NativeWishes;
use App\Livewire\Candidate\Profile;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateMajorOffering;
use App\Models\EvaluationRuleVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
    config(['admission_registration.native_registration' => true, 'admission_registration.approved_templates' => ['THPT_SCORE' => [1], 'TRANSCRIPT_SCORE' => [1]]]);
});

function nativeApplication(): Application
{
    $round = AdmissionRound::factory()->create(['native_registration_state' => 'native_open', 'status' => AdmissionRoundStatus::Open]);
    $application = Application::factory()->for($round)->create(['registration_mode' => 'native']);
    $values = array_fill_keys(Profile::COMPLETION, 'fixture');
    $values['date_of_birth'] = '2008-01-02';
    $values['citizen_id_issued_date'] = '2022-01-02';
    $values['graduation_year'] = 2026;
    $values['citizen_id'] = fake()->unique()->numerify('############');
    $values['profile_status'] = ProfileStatus::Complete;
    $application->candidateProfile->update($values);

    return $application;
}

function nativeOffering(Application $application, int $methods = 2, bool $rules = true): CandidateMajorOffering
{
    $first = AdmissionProgram::factory()->for($application->admissionRound)->create();
    $offering = CandidateMajorOffering::factory()->for($first)->create();
    $programs = collect([$first]);
    for ($i = 1; $i < $methods; $i++) {
        $programs->push(AdmissionProgram::factory()->for($application->admissionRound)->for($first->major)->create());
    }
    if ($rules) {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        foreach ($programs as $program) {
            $rule = EvaluationRuleVersion::factory()->create([
                'admission_method_id' => $program->admission_method_id,
                'status' => 'approved', 'approved_by' => $admin->id, 'approved_at' => now(),
            ]);
            $program->evaluation_rule_version_id = $rule->id;
            $program->save();
        }
    }

    return $offering;
}

test('native UI presents one major with all accepted methods and no legacy wish', function () {
    $application = nativeApplication();
    $offering = nativeOffering($application);
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(NativeWishes::class, ['applicationId' => $application->id])
        ->set('offeringId', (string) $offering->id)->call('addWish')->assertHasNoErrors()->assertSee($offering->major->name);
    foreach (NativeWishRegistration::programs($offering) as $program) {
        $page->assertSee($program->admissionMethod->name);
    }
    expect($application->nativeWishes()->count())->toBe(1)->and($application->wishes()->count())->toBe(0);
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->assertSee('Đăng ký theo ngành');
});

test('native registration rejects duplicate major', function () {
    $application = nativeApplication();
    $offering = nativeOffering($application);
    $this->actingAs($application->candidateProfile->user);
    $action = app(NativeWishRegistration::class);
    $action->add($application->id, $offering->id);
    expect(fn () => $action->add($application->id, $offering->id))->toThrow(ValidationException::class);
    expect($application->nativeWishes()->count())->toBe(1);
});

test('native reorder and delete keep continuous priorities', function () {
    $application = nativeApplication();
    $this->actingAs($application->candidateProfile->user);
    $action = app(NativeWishRegistration::class);
    foreach (range(1, 3) as $i) {
        $action->add($application->id, nativeOffering($application, 1)->id);
    }
    $order = $application->nativeWishes()->orderBy('priority')->pluck('id')->all();
    $action->reorder($application->id, array_reverse($order), $order);
    expect($application->nativeWishes()->orderBy('priority')->pluck('id')->all())->toBe(array_reverse($order));
    $action->delete($application->id, $order[1], array_reverse($order));
    expect($application->nativeWishes()->orderBy('priority')->pluck('priority')->all())->toBe([1, 2]);
});

test('stale or incomplete reorder is rejected without writes', function () {
    $application = nativeApplication();
    $this->actingAs($application->candidateProfile->user);
    $action = app(NativeWishRegistration::class);
    $action->add($application->id, nativeOffering($application)->id);
    expect(fn () => $action->reorder($application->id, [], []))->toThrow(ValidationException::class);
    expect($application->nativeWishes()->sole()->priority)->toBe(1);
});

test('locked native applications cannot add delete or reorder', function (string $operation) {
    $application = nativeApplication();
    $this->actingAs($application->candidateProfile->user);
    $action = app(NativeWishRegistration::class);
    $action->add($application->id, nativeOffering($application)->id);
    $id = $application->nativeWishes()->sole()->id;
    $application->update(['status' => ApplicationStatus::Submitted]);
    expect(fn () => match ($operation) {
        'add' => $action->add($application->id, nativeOffering($application)->id),
        'delete' => $action->delete($application->id, $id, [$id]),
        'reorder' => $action->reorder($application->id, [$id], [$id]),
    })->toThrow(AuthorizationException::class);
})->with(['add', 'delete', 'reorder']);

test('wrong round offerings cannot be selected', function () {
    $application = nativeApplication();
    $offering = nativeOffering(nativeApplication());
    $this->actingAs($application->candidateProfile->user);
    expect(fn () => app(NativeWishRegistration::class)->add($application->id, $offering->id))->toThrow(ModelNotFoundException::class);
});

test('submission pins every accepted method even without candidate source scores', function () {
    $application = nativeApplication();
    $offering = nativeOffering($application, 3);
    $this->actingAs($application->candidateProfile->user);
    app(NativeWishRegistration::class)->add($application->id, $offering->id);
    app(CandidateApplications::class)->submit($application->id);
    $snapshot = $application->submissionSnapshots()->sole();
    expect($snapshot->readiness)->toBe('rules_pinned')->and($snapshot->entries()->sole()->bindings()->count())->toBe(3);
    expect($application->fresh()->status)->toBe(ApplicationStatus::Submitted);
    $this->assertDatabaseCount('candidate_scores', 0);
    $this->assertDatabaseCount('admission_results', 0);
});

test('missing approved rule rolls back native submission atomically', function () {
    $application = nativeApplication();
    $offering = nativeOffering($application, 2, false);
    $this->actingAs($application->candidateProfile->user);
    app(NativeWishRegistration::class)->add($application->id, $offering->id);
    expect(fn () => app(CandidateApplications::class)->submit($application->id))->toThrow(ValidationException::class);
    expect($application->submissionSnapshots()->count())->toBe(0)->and($application->fresh()->status)->toBe(ApplicationStatus::Draft);
});

test('resubmit preserves snapshots after reorder and deletion of live wishes', function () {
    $application = nativeApplication();
    $this->actingAs($application->candidateProfile->user);
    $action = app(NativeWishRegistration::class);
    foreach (range(1, 2) as $i) {
        $action->add($application->id, nativeOffering($application, 1)->id);
    }
    $action->submit($application->id);
    $first = $application->submissionSnapshots()->sole();
    $manifest = $first->manifest;
    $hash = $first->content_hash;
    $application->update(['status' => ApplicationStatus::NeedsRevision, 'revision_reason' => 'Bổ sung']);
    $order = $application->nativeWishes()->orderBy('priority')->pluck('id')->all();
    $action->reorder($application->id, array_reverse($order), $order);
    $action->delete($application->id, $order[0], array_reverse($order));
    $action->submit($application->id);
    expect($first->fresh()->manifest)->toBe($manifest)->and($first->fresh()->content_hash)->toBe($hash);
    expect($first->entries()->count())->toBe(2)->and($application->submissionSnapshots()->count())->toBe(2);
    expect($first->entries()->whereNull('live_wish_id')->count())->toBe(1);
});

test('rule retirement leaves old bindings unchanged and blocks a new submission', function () {
    $application = nativeApplication();
    $offering = nativeOffering($application, 1);
    $this->actingAs($application->candidateProfile->user);
    $action = app(NativeWishRegistration::class);
    $action->add($application->id, $offering->id);
    $action->submit($application->id);
    $binding = $application->submissionSnapshots()->sole()->entries()->sole()->bindings()->sole();
    $before = $binding->getAttributes();
    $binding->rule->update(['status' => 'retired']);
    expect($binding->fresh()->getAttributes())->toBe($before);
    $application->update(['status' => ApplicationStatus::NeedsRevision]);
    expect(fn () => $action->submit($application->id))->toThrow(ValidationException::class);
    expect($application->submissionSnapshots()->count())->toBe(1);
});

test('history models reject payload edits and deletion', function () {
    $application = nativeApplication();
    $this->actingAs($application->candidateProfile->user);
    $action = app(NativeWishRegistration::class);
    $action->add($application->id, nativeOffering($application, 1)->id);
    $action->submit($application->id);
    $snapshot = $application->submissionSnapshots()->sole();
    expect(fn () => $snapshot->update(['manifest' => []]))->toThrow(ValidationException::class);
    expect(fn () => $snapshot->delete())->toThrow(ValidationException::class);
    expect(fn () => $snapshot->entries()->sole()->update(['priority' => 9]))->toThrow(ValidationException::class);
});

test('composite FK rejects a rule of another method', function () {
    $application = nativeApplication();
    $offering = nativeOffering($application);
    $programs = NativeWishRegistration::programs($offering);
    $programs[0]->evaluation_rule_version_id = $programs[1]->evaluation_rule_version_id;
    expect(fn () => $programs[0]->save())->toThrow(QueryException::class);
});

test('changed accepted catalog is rechecked at submit and cannot omit new method', function () {
    $application = nativeApplication();
    $offering = nativeOffering($application, 1);
    $this->actingAs($application->candidateProfile->user);
    app(NativeWishRegistration::class)->add($application->id, $offering->id);
    AdmissionProgram::factory()->for($application->admissionRound)->for($offering->major)->create();
    expect(fn () => app(NativeWishRegistration::class)->submit($application->id))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('application_submission_snapshots', 0);
});

test('double submit cannot create a second submission version', function () {
    $application = nativeApplication();
    $this->actingAs($application->candidateProfile->user);
    $action = app(NativeWishRegistration::class);
    $action->add($application->id, nativeOffering($application)->id);
    $action->submit($application->id);
    expect(fn () => $action->submit($application->id))->toThrow(AuthorizationException::class);
    expect($application->submissionSnapshots()->pluck('submission_version')->all())->toBe([1]);
});

test('another candidate cannot edit a native application', function () {
    $application = nativeApplication();
    $other = nativeApplication();
    $this->actingAs($other->candidateProfile->user);
    expect(fn () => app(NativeWishRegistration::class)->add($application->id, nativeOffering($application)->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => Livewire::test(NativeWishes::class, ['applicationId' => $application->id]))->toThrow(ModelNotFoundException::class);
});

test('feature flag disables native writes without converting existing application', function () {
    $application = nativeApplication();
    $offering = nativeOffering($application);
    $this->actingAs($application->candidateProfile->user);
    config(['admission_registration.native_registration' => false]);
    expect(fn () => app(NativeWishRegistration::class)->add($application->id, $offering->id))->toThrow(HttpException::class);
    expect($application->fresh()->registration_mode)->toBe('native');
});

test('legacy add cannot write a fake representative program for native wishes', function () {
    $application = nativeApplication();
    $offering = nativeOffering($application);
    $this->actingAs($application->candidateProfile->user);
    expect(fn () => app(CandidateWishes::class)->add($application->id, ['candidate_major_offering_id' => $offering->id]))->toThrow(HttpException::class);
    expect($application->wishes()->count())->toBe(0);
});

test('legacy engine refuses a round containing native applications', function () {
    $application = nativeApplication();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin);
    expect(fn () => app(AdmissionEngineSnapshot::class)->load($application->admission_round_id))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('admission_results', 0);
});

test('empty template allowlist cannot create a native ready submission', function () {
    $application = nativeApplication();
    $this->actingAs($application->candidateProfile->user);
    $action = app(NativeWishRegistration::class);
    $action->add($application->id, nativeOffering($application)->id);
    config(['admission_registration.approved_templates' => []]);
    expect(fn () => $action->submit($application->id))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('application_submission_snapshots', 0);
});

test('global flag never changes the workflow of a legacy round', function (bool $enabled) {
    $application = nativeApplication();
    $application->delete();
    DB::table('admission_rounds')->where('id', $application->admission_round_id)->update(['native_registration_state' => 'legacy']);
    config(['admission_registration.native_registration' => $enabled]);
    $this->actingAs($application->candidateProfile->user);
    $created = app(CandidateApplications::class)->create(['admission_round_id' => $application->admission_round_id]);
    expect($created->registration_mode)->toBe('legacy');
    expect(fn () => $created->update(['registration_mode' => 'native']))->toThrow(ValidationException::class);
})->with([true, false]);

test('native submission ignores quota Q q and never writes allocation results', function () {
    $application = nativeApplication();
    $offering = nativeOffering($application, 1);
    $offering->programs()->update(['quota' => 0]);
    $this->actingAs($application->candidateProfile->user);
    $action = app(NativeWishRegistration::class);
    $action->add($application->id, $offering->id);
    $action->submit($application->id);
    expect($application->submissionSnapshots()->count())->toBe(1);
    $this->assertDatabaseCount('admission_quota_versions', 0);
    $this->assertDatabaseCount('admission_results', 0);
});

test('native bindings and entries reject mismatched scope at database boundary', function () {
    $application = nativeApplication();
    $offering = nativeOffering($application, 1);
    $otherOffering = nativeOffering($application, 1);
    $this->actingAs($application->candidateProfile->user);
    $action = app(NativeWishRegistration::class);
    $action->add($application->id, $offering->id);
    $action->submit($application->id);
    $entry = $application->submissionSnapshots()->sole()->entries()->sole();
    $binding = $entry->bindings()->sole();
    $row = $binding->getAttributes();
    unset($row['id']);
    $row['admission_program_id'] = NativeWishRegistration::programs($otherOffering)->sole()->id;
    expect(fn () => DB::table('wish_method_bindings')->insert($row))->toThrow(QueryException::class);
});

test('pinned rules cannot have approved payload edited', function () {
    $application = nativeApplication();
    $offering = nativeOffering($application, 1);
    $rule = EvaluationRuleVersion::query()->findOrFail(NativeWishRegistration::programs($offering)->sole()->evaluation_rule_version_id);
    expect(fn () => $rule->update(['payload' => ['changed' => true]]))->toThrow(ValidationException::class);
    expect(fn () => $rule->update(['admission_method_id' => nativeOffering($application, 1)->programs()->sole()->admission_method_id]))->toThrow(ValidationException::class);
});

test('submission rejects corrupt rule payload hashes instead of pinning them', function () {
    $application = nativeApplication();
    $offering = nativeOffering($application, 1);
    $program = NativeWishRegistration::programs($offering)->sole();
    DB::table('evaluation_rule_versions')->where('id', $program->evaluation_rule_version_id)->update(['content_hash' => str_repeat('0', 64)]);
    $this->actingAs($application->candidateProfile->user);
    $action = app(NativeWishRegistration::class);
    $action->add($application->id, $offering->id);
    expect(fn () => $action->submit($application->id))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('application_submission_snapshots', 0);
});

test('sealed snapshots reject additional entries bindings and unsealing', function () {
    $application = nativeApplication();
    $this->actingAs($application->candidateProfile->user);
    $action = app(NativeWishRegistration::class);
    $action->add($application->id, nativeOffering($application, 1)->id);
    $action->submit($application->id);
    $snapshot = $application->submissionSnapshots()->sole();
    $entry = $snapshot->entries()->sole();
    $binding = $entry->bindings()->sole();
    expect($snapshot->sealed_at)->not->toBeNull();
    expect(fn () => $snapshot->update(['sealed_at' => null]))->toThrow(ValidationException::class);
    expect(fn () => $snapshot->entries()->create($entry->getAttributes()))->toThrow(ValidationException::class);
    expect(fn () => $entry->bindings()->create($binding->getAttributes()))->toThrow(ValidationException::class);
});
