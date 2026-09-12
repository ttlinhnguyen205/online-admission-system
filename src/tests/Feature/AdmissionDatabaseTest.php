<?php

use App\Enums\AdmissionDecision;
use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WishStatus;
use App\Models\ActivityLog;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Announcement;
use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\CandidateScore;
use App\Models\Major;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('users default to active candidates without exposing privilege mass assignment', function () {
    $user = User::factory()->create()->refresh();

    expect($user->role)->toBe(UserRole::Candidate);
    expect($user->status)->toBe(UserStatus::Active);
    expect($user->isFillable('role'))->toBeFalse();
    expect($user->isFillable('status'))->toBeFalse();

    try {
        $user->fill(['role' => 'admin', 'status' => 'locked'])->save();
    } catch (MassAssignmentException) {
        // Strict mass assignment may reject the attributes instead of discarding them.
    }

    $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'candidate', 'status' => 'active']);
});

test('database status defaults hydrate as their corresponding enums', function (string $model, string $column, BackedEnum $value) {
    $record = $model::factory()->create()->refresh();

    expect($record->getAttribute($column))->toBe($value);
})->with([
    [CandidateProfile::class, 'profile_status', ProfileStatus::Incomplete],
    [AdmissionRound::class, 'status', AdmissionRoundStatus::Draft],
    [Application::class, 'status', ApplicationStatus::Draft],
    [CandidateDocument::class, 'status', DocumentStatus::Pending],
    [AdmissionWish::class, 'status', WishStatus::Pending],
    [AdmissionResult::class, 'decision', AdmissionDecision::Waiting],
]);

test('a user cannot own two candidate profiles', function () {
    $profile = CandidateProfile::factory()->create();

    expect(fn () => CandidateProfile::factory()->for($profile->user)->create())
        ->toThrow(QueryException::class);
    $this->assertDatabaseCount('candidate_profiles', 1);
});

test('candidate profiles require an existing user', function (?int $userId) {
    expect(fn () => CandidateProfile::factory()->create(['user_id' => $userId]))
        ->toThrow(QueryException::class);
    $this->assertDatabaseCount('candidate_profiles', 0);
})->with(['null owner' => [null], 'missing owner' => [999999]]);

test('citizen identifiers are unique when supplied and allow multiple unknown values', function () {
    CandidateProfile::factory()->count(2)->create(['citizen_id' => null]);
    CandidateProfile::factory()->create(['citizen_id' => '012345678901']);

    expect(fn () => CandidateProfile::factory()->create(['citizen_id' => '012345678901']))
        ->toThrow(QueryException::class);
    $this->assertDatabaseCount('candidate_profiles', 3);
});

test('candidate and round identify a single application', function () {
    $application = Application::factory()->create();

    expect(fn () => Application::factory()
        ->for($application->candidateProfile)
        ->for($application->admissionRound)
        ->create())->toThrow(QueryException::class);
    $this->assertDatabaseCount('applications', 1);

    Application::factory()->for($application->candidateProfile)->create();
    $this->assertDatabaseCount('applications', 2);
});

test('a round major and method identify a single program', function () {
    $program = AdmissionProgram::factory()->create();

    expect(fn () => AdmissionProgram::factory()
        ->for($program->admissionRound)
        ->for($program->major)
        ->for($program->admissionMethod)
        ->create())->toThrow(QueryException::class);
    $this->assertDatabaseCount('admission_programs', 1);

    AdmissionProgram::factory()->for($program->admissionRound)->for($program->major)->create();
    $this->assertDatabaseCount('admission_programs', 2);
});

test('wish priorities cannot repeat within an application', function () {
    $wish = AdmissionWish::factory()->create();

    expect(fn () => AdmissionWish::factory()->for($wish->application)->create(['priority' => $wish->priority]))
        ->toThrow(QueryException::class);
    $this->assertDatabaseCount('admission_wishes', 1);
});

test('a program cannot appear twice in the same application', function () {
    $wish = AdmissionWish::factory()->create();

    expect(fn () => AdmissionWish::factory()->for($wish->application)
        ->for($wish->admissionProgram)->create(['priority' => 2]))
        ->toThrow(QueryException::class);
    $this->assertDatabaseCount('admission_wishes', 1);
});

test('different applications can select the same program and priority', function () {
    $wish = AdmissionWish::factory()->create();
    $application = Application::factory()->for($wish->application->admissionRound)->create();

    $other = AdmissionWish::factory()->for($application)->for($wish->admissionProgram)->create();

    $this->assertModelExists($other);
    expect($other->priority)->toBe($wish->priority);
});

test('a wish has at most one result', function () {
    $result = AdmissionResult::factory()->create();

    expect(fn () => AdmissionResult::factory()->for($result->admissionWish)->create())
        ->toThrow(QueryException::class);
    $this->assertDatabaseCount('admission_results', 1);
});

test('referenced admission records cannot be deleted', function (string $model, string $relationship) {
    $child = $model::factory()->create();
    $parent = $child->getRelationValue($relationship);

    expect(fn () => $parent->delete())->toThrow(QueryException::class);

    $this->assertModelExists($parent);
    $this->assertModelExists($child);
})->with([
    'profile owner' => [CandidateProfile::class, 'user'],
    'program round' => [AdmissionProgram::class, 'admissionRound'],
    'program major' => [AdmissionProgram::class, 'major'],
    'program method' => [AdmissionProgram::class, 'admissionMethod'],
    'application candidate' => [Application::class, 'candidateProfile'],
    'application round' => [Application::class, 'admissionRound'],
    'score candidate' => [CandidateScore::class, 'candidateProfile'],
    'wish program' => [AdmissionWish::class, 'admissionProgram'],
    'result wish' => [AdmissionResult::class, 'admissionWish'],
    'announcement author' => [Announcement::class, 'creator'],
]);

test('deleting an application without results removes its documents and wishes but retains profile scores', function () {
    $application = Application::factory()->create();
    $document = CandidateDocument::factory()->for($application)->create();
    $wish = AdmissionWish::factory()->for($application)->create();
    $score = CandidateScore::factory()->for($application->candidateProfile)->create();

    $application->delete();

    $this->assertModelMissing($application);
    $this->assertModelMissing($document);
    $this->assertModelMissing($wish);
    $this->assertModelExists($score);
});

test('results prevent application deletion through cascading wishes', function () {
    $result = AdmissionResult::factory()->create();
    $application = $result->admissionWish->application;
    $document = CandidateDocument::factory()->for($application)->create();

    expect(fn () => $application->delete())->toThrow(QueryException::class);

    $this->assertModelExists($application);
    $this->assertModelExists($document);
    $this->assertModelExists($result);
});

test('deleting reviewers preserves reviewed records and clears their attribution', function (string $model, string $column, string $relationship) {
    $user = User::factory()->create();
    $record = $model::factory()->create([$column => $user->id]);

    $user->delete();

    $this->assertModelMissing($user);
    $this->assertDatabaseHas($record->getTable(), ['id' => $record->id, $column => null]);
    expect($record->refresh()->getRelationValue($relationship))->toBeNull();
})->with([
    [Application::class, 'reviewed_by', 'reviewer'],
    [CandidateDocument::class, 'verified_by', 'verifier'],
    [CandidateScore::class, 'verified_by', 'verifier'],
    [ActivityLog::class, 'user_id', 'user'],
]);

test('announcement recipients are unique and expose read state in both directions', function () {
    $announcement = Announcement::factory()->create();
    $recipient = User::factory()->create();
    $readAt = '2026-09-01 12:00:00';

    $announcement->users()->attach($recipient, ['read_at' => $readAt]);

    expect($recipient->announcements()->sole()->pivot->read_at)->toBe($readAt);
    expect($announcement->users()->sole()->id)->toBe($recipient->id);
    expect(fn () => $announcement->users()->attach($recipient))->toThrow(QueryException::class);
    $this->assertDatabaseCount('announcement_user', 1);
});

test('announcement recipient records cascade when either parent is deleted', function (string $parent) {
    $announcement = Announcement::factory()->create();
    $recipient = User::factory()->create();
    $announcement->users()->attach($recipient);

    ($parent === 'announcement' ? $announcement : $recipient)->delete();

    $this->assertDatabaseCount('announcement_user', 0);
    $this->assertModelExists($parent === 'announcement' ? $recipient : $announcement);
})->with(['announcement', 'recipient']);

test('activity subjects may disappear without deleting audit history', function () {
    $major = Major::factory()->create();
    $log = ActivityLog::factory()->for($major, 'subject')->create();

    expect($log->subject->is($major))->toBeTrue();
    $major->delete();

    $this->assertModelExists($log);
    expect($log->refresh()->subject)->toBeNull();
    expect($log->old_values)->toBeNull();
    expect($log->new_values)->toBe(['status' => 'draft']);
    expect($log->created_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($log->getAttributes())->not->toHaveKey('updated_at');
});

test('scores and fees retain configured decimal precision and metadata casts', function () {
    $score = CandidateScore::factory()->create(['score' => '8.125', 'verified' => true])->refresh();
    $program = AdmissionProgram::factory()->create(['tuition_fee' => '12345678.90', 'minimum_score' => '24.125'])->refresh();
    $method = AdmissionMethod::factory()->create(['score_config' => ['weights' => ['MATH' => 2]]])->refresh();
    $profile = CandidateProfile::factory()->create(['date_of_birth' => '2008-03-15'])->refresh();

    expect($score->score)->toBe('8.125');
    expect($score->verified)->toBeTrue();
    expect($program->tuition_fee)->toBe('12345678.90');
    expect($program->minimum_score)->toBe('24.125');
    expect($method->score_config)->toBe(['weights' => ['MATH' => 2]]);
    expect($method->is_active)->toBeTrue();
    expect($profile->date_of_birth->format('Y-m-d'))->toBe('2008-03-15');
});

test('the test schema uses SQLite foreign keys and omits deferred schema fields', function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    expect(DB::selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);
    expect(Schema::hasColumn('candidate_scores', 'candidate_profile_id'))->toBeTrue();
    expect(Schema::hasColumn('candidate_scores', 'application_id'))->toBeFalse();
    expect(Schema::hasColumn('candidate_scores', 'admission_method_id'))->toBeFalse();
    expect(Schema::hasColumn('candidate_scores', 'score_key'))->toBeFalse();
    expect(Schema::hasColumn('admission_wishes', 'admission_round_id'))->toBeFalse();
    expect(Schema::hasColumn('admission_results', 'decided_by'))->toBeFalse();
    expect(Schema::hasColumn('announcements', 'status'))->toBeFalse();
    expect(Schema::hasColumn('announcements', 'admission_round_id'))->toBeFalse();
});
