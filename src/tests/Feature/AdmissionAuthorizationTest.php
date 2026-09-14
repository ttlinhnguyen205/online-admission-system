<?php

use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** @return array{0: Model, 1: array<int, mixed>} */
function admissionAuthorizationSubject(string $model, User $owner): array
{
    if ($model === User::class) {
        return [$owner, [User::class]];
    }

    if (in_array($model, [AdmissionRound::class, Major::class, AdmissionMethod::class, AdmissionProgram::class, Announcement::class], true)) {
        return [$model::factory()->create(), [$model]];
    }

    $profile = CandidateProfile::factory()->for($owner)->create();

    if ($model === CandidateProfile::class) {
        return [$profile, [$model]];
    }

    if ($model === CandidateScore::class) {
        return [CandidateScore::factory()->for($profile)->create(), [$model, $profile]];
    }

    $application = Application::factory()->for($profile)->create();

    return match ($model) {
        Application::class => [$application, [$model, $profile]],
        CandidateDocument::class => [CandidateDocument::factory()->for($application)->create(), [$model, $application]],
        AdmissionWish::class => [AdmissionWish::factory()->for($application)->create(), [$model, $application]],
        AdmissionResult::class => [
            AdmissionResult::factory()->for(AdmissionWish::factory()->for($application))->create(['published_at' => now()]),
            [$model],
        ],
    };
}

dataset('admission abilities', [
    'profile' => [CandidateProfile::class, ['view', 'update'], ['viewAny', 'view'], ['viewAny', 'view']],
    'application' => [Application::class, ['view', 'create', 'update', 'submit'], ['viewAny', 'view', 'review'], ['viewAny', 'view', 'review']],
    'document' => [CandidateDocument::class, ['view', 'download', 'create', 'update', 'delete'], ['viewAny', 'view', 'download', 'verify', 'reject'], ['viewAny', 'view', 'download', 'verify', 'reject']],
    'score' => [CandidateScore::class, ['view', 'create', 'update', 'delete'], ['viewAny', 'view', 'verify'], ['viewAny', 'view', 'verify']],
    'wish' => [AdmissionWish::class, ['view', 'create', 'update', 'delete'], ['viewAny', 'view'], ['viewAny', 'view']],
    'result' => [AdmissionResult::class, ['view'], ['viewAny', 'view'], ['viewAny', 'view', 'create', 'update', 'publish']],
    'round' => [AdmissionRound::class, [], ['viewAny', 'view'], ['viewAny', 'view', 'create', 'update', 'delete']],
    'major' => [Major::class, [], ['viewAny', 'view'], ['viewAny', 'view', 'create', 'update', 'delete']],
    'method' => [AdmissionMethod::class, [], ['viewAny', 'view'], ['viewAny', 'view', 'create', 'update', 'delete']],
    'program' => [AdmissionProgram::class, [], ['viewAny', 'view'], ['viewAny', 'view', 'create', 'update', 'delete']],
    'unpublished announcement' => [Announcement::class, [], [], ['viewAny', 'view', 'create', 'update', 'delete', 'publish']],
    'own user account' => [User::class, ['delete'], ['delete'], ['delete']],
]);

test('policies enforce the complete role and account status ability matrix', function (
    string $model, array $candidate, array $staff, array $admin, UserRole $role, UserStatus $status
) {
    $this->freezeTime();
    $user = User::factory()->create(['role' => $role, 'status' => $status])->refresh();
    [$subject, $createArguments] = admissionAuthorizationSubject($model, $user);
    $allowed = $status === UserStatus::Active ? match ($role) {
        UserRole::Candidate => $candidate,
        UserRole::Staff => $staff,
        UserRole::Admin => $admin,
    } : [];

    foreach (['viewAny', 'view', 'create', 'update', 'submit', 'delete', 'download', 'review', 'verify', 'reject', 'publish', 'changeRole', 'restore', 'forceDelete', 'unknownAbility'] as $ability) {
        $arguments = match ($ability) {
            'viewAny' => [$model],
            'create' => $createArguments,
            default => [$subject],
        };

        expect(Gate::forUser($user)->allows($ability, $arguments), $model.' '.$ability)
            ->toBe(in_array($ability, $allowed, true));
    }
})->with('admission abilities')->with(UserRole::cases())->with(UserStatus::cases());

test('candidates cannot access or mutate another candidates private records', function (string $model) {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    [$subject, $createArguments] = admissionAuthorizationSubject($model, $owner);

    expect(Gate::forUser($intruder)->inspect('view', $subject)->status())->toBe(404);

    foreach (['update', 'delete', 'download', 'review', 'verify', 'reject', 'publish'] as $ability) {
        expect(Gate::forUser($intruder)->allows($ability, $subject))->toBeFalse();
    }

    if ($model !== CandidateProfile::class) {
        expect(Gate::forUser($intruder)->allows('create', $createArguments))->toBeFalse();
    }
})->with([CandidateProfile::class, Application::class, CandidateDocument::class, CandidateScore::class, AdmissionWish::class, AdmissionResult::class]);

test('candidate application and child mutations follow the persisted application lifecycle', function (ApplicationStatus $status, bool $editable) {
    $application = Application::factory()->create(['status' => $status]);
    $user = $application->candidateProfile->user;
    $document = CandidateDocument::factory()->for($application)->create();
    $wish = AdmissionWish::factory()->for($application)->create();

    $application->status = ApplicationStatus::Draft;

    expect(Gate::forUser($user)->allows('update', $application))->toBe($editable);
    expect(Gate::forUser($user)->allows('submit', $application))->toBe($editable);
    expect(Gate::forUser($user)->allows('delete', $application))->toBeFalse();

    foreach ([$document, $wish] as $child) {
        expect(Gate::forUser($user)->allows('view', $child))->toBeTrue();
        expect(Gate::forUser($user)->allows('create', [$child::class, $application]))->toBe($editable);
        expect(Gate::forUser($user)->allows('update', $child))->toBe($editable);
        expect(Gate::forUser($user)->allows('delete', $child))->toBe($editable);
    }
})->with([
    [ApplicationStatus::Draft, true],
    [ApplicationStatus::NeedsRevision, true],
    [ApplicationStatus::Submitted, false],
    [ApplicationStatus::UnderReview, false],
    [ApplicationStatus::Verified, false],
    [ApplicationStatus::Processing, false],
    [ApplicationStatus::Completed, false],
]);

test('application history does not restrict candidate profile score mutations', function (ApplicationStatus $status) {
    $profile = CandidateProfile::factory()->create();
    Application::factory()->for($profile)->create(['status' => ApplicationStatus::Draft]);
    Application::factory()->for($profile)->create(['status' => $status]);
    CandidateScore::factory()->for($profile)->create(['exam_year' => 2025, 'verified' => true]);
    $score = CandidateScore::factory()->for($profile)->create(['exam_year' => 2027]);
    $gate = Gate::forUser($profile->user);

    expect($gate->allows('view', $score))->toBeTrue();
    expect($gate->allows('create', [CandidateScore::class, $profile, ['exam_year' => 2027]]))->toBeTrue();
    expect($gate->allows('update', $score))->toBeTrue();
    expect($gate->allows('delete', $score))->toBeTrue();
})->with(ApplicationStatus::cases());

test('verified scores remain immutable even when an in-memory score is changed', function () {
    $score = CandidateScore::factory()->create(['verified' => true]);
    $user = $score->candidateProfile->user;
    $score->verified = false;

    expect(Gate::forUser($user)->allows('view', $score))->toBeTrue();
    expect(Gate::forUser($user)->allows('update', $score))->toBeFalse();
    expect(Gate::forUser($user)->allows('delete', $score))->toBeFalse();
    expect(Gate::forUser($user)->allows('verify', $score))->toBeFalse();
});

test('candidate score permissions reject verification ownership and unexpected attributes', function (string $attribute) {
    $score = CandidateScore::factory()->create();
    $profile = $score->candidateProfile;
    $gate = Gate::forUser($profile->user);

    expect($gate->allows('create', [CandidateScore::class, $profile, [$attribute => null]]))->toBeFalse();
    expect($gate->allows('update', [$score, [$attribute => null]]))->toBeFalse();
})->with(['verified', 'verified_by', 'candidate_profile_id', 'id', 'unexpected']);

test('candidate score permissions accept candidate fields without granting verification', function () {
    $score = CandidateScore::factory()->create();
    $profile = $score->candidateProfile;
    $attributes = ['score_type' => 'thpt', 'subject_code' => 'MATH', 'subject_name' => 'Mathematics', 'score' => '8.500', 'exam_year' => 2026];

    expect(Gate::forUser($profile->user)->allows('create', [CandidateScore::class, $profile, $attributes]))->toBeTrue();
    expect(Gate::forUser($profile->user)->allows('update', [$score, $attributes]))->toBeTrue();
});

test('altered ownership attributes and cached relations cannot authorize foreign records', function (string $model, string $foreignKey) {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    [$subject] = admissionAuthorizationSubject($model, $owner);
    [$intruderSubject] = admissionAuthorizationSubject($model, $intruder);
    $subject->setAttribute($foreignKey, $intruderSubject->getAttribute($foreignKey));
    $subject->setRelations($intruderSubject->getRelations());

    expect(Gate::forUser($intruder)->allows('view', $subject))->toBeFalse();
    expect(Gate::forUser($intruder)->allows('update', $subject))->toBeFalse();
})->with([
    [CandidateProfile::class, 'user_id'], [Application::class, 'candidate_profile_id'],
    [CandidateDocument::class, 'application_id'], [CandidateScore::class, 'candidate_profile_id'],
    [AdmissionWish::class, 'application_id'], [AdmissionResult::class, 'admission_wish_id'],
]);

test('result publication has an inclusive boundary and cannot be forged in memory', function (?string $publication, bool $visible) {
    $this->travelTo(now()->setDate(2026, 9, 12)->startOfDay());
    $result = AdmissionResult::factory()->create(['published_at' => $publication]);
    $user = $result->admissionWish->application->candidateProfile->user;
    $result->published_at = now()->subDay();

    expect(Gate::forUser($user)->allows('view', $result))->toBe($visible);
})->with([
    [null, false], ['2026-09-11 23:59:59', true],
    ['2026-09-12 00:00:00', true], ['2026-09-12 00:00:01', false],
]);

test('announcement visibility respects publication expiry and audience', function (
    ?string $publication, ?string $expiry, ?UserRole $audience, bool $candidate, bool $staff
) {
    $this->travelTo(now()->setDate(2026, 9, 12)->startOfDay());
    $announcement = Announcement::factory()->create(['published_at' => $publication, 'expires_at' => $expiry, 'target_role' => $audience]);
    $candidateUser = User::factory()->create();
    $staffUser = User::factory()->create(['role' => UserRole::Staff]);
    $announcement->users()->attach([$candidateUser->id, $staffUser->id]);

    expect(Gate::forUser($candidateUser)->allows('view', $announcement))->toBe($candidate);
    expect(Gate::forUser($staffUser)->allows('view', $announcement))->toBe($staff);
})->with([
    'unpublished' => [null, null, null, false, false],
    'future publication' => ['2026-09-12 00:00:01', null, null, false, false],
    'publication boundary' => ['2026-09-12 00:00:00', null, null, true, true],
    'expired' => ['2026-09-11 00:00:00', '2026-09-11 23:59:59', null, false, false],
    'expiry boundary' => ['2026-09-11 00:00:00', '2026-09-12 00:00:00', null, false, false],
    'not expired' => ['2026-09-11 00:00:00', '2026-09-12 00:00:01', null, true, true],
    'candidate audience' => ['2026-09-11 00:00:00', null, UserRole::Candidate, true, false],
    'staff audience' => ['2026-09-11 00:00:00', null, UserRole::Staff, false, true],
    'admin audience' => ['2026-09-11 00:00:00', null, UserRole::Admin, false, false],
]);

test('only active admins may change another users role', function (UserRole $role, UserStatus $status) {
    $actor = User::factory()->create(['role' => $role, 'status' => $status]);
    $target = User::factory()->create();

    expect(Gate::forUser($actor)->allows('changeRole', $target))->toBe($role === UserRole::Admin && $status === UserStatus::Active);
    expect(Gate::forUser($actor)->allows('delete', $target))->toBeFalse();
})->with(UserRole::cases())->with(UserStatus::cases());

test('admin configuration deletion preserves referenced records', function (string $childModel, string $relationship) {
    $child = $childModel::factory()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    expect(Gate::forUser($admin)->allows('delete', $child->$relationship))->toBeFalse();
})->with([
    [AdmissionProgram::class, 'admissionRound'], [Application::class, 'admissionRound'],
    [AdmissionProgram::class, 'major'], [AdmissionProgram::class, 'admissionMethod'],
    [AdmissionWish::class, 'admissionProgram'],
]);

test('a wish with a result cannot be deleted even if the parent is draft', function () {
    $result = AdmissionResult::factory()->create();
    $wish = $result->admissionWish;

    expect(Gate::forUser($wish->application->candidateProfile->user)->allows('delete', $wish))->toBeFalse();
});

test('guests cannot authorize private admission access', function () {
    $profile = CandidateProfile::factory()->create();

    expect(Gate::allows('view', $profile))->toBeFalse();
});

test('contextual catalog browsing is limited to active verified candidates and owned context', function (UserRole $role, UserStatus $status) {
    $user = User::factory()->create(['role' => $role, 'status' => $status]);
    $profile = CandidateProfile::factory()->for($user)->create();
    $application = Application::factory()->for($profile)->create();
    $allowed = $role === UserRole::Candidate && $status === UserStatus::Active;
    expect(Gate::forUser($user)->allows('browseForCandidate', [AdmissionRound::class, $profile]))->toBe($allowed);
    expect(Gate::forUser($user)->allows('browseForApplication', [AdmissionProgram::class, $application]))->toBe($allowed);
    if ($allowed) {
        expect(Gate::forUser($user)->allows('viewAny', AdmissionRound::class))->toBeFalse();
        expect(Gate::forUser($user)->allows('viewAny', AdmissionProgram::class))->toBeFalse();
        expect(Gate::forUser($user)->allows('view', $application->admissionRound))->toBeFalse();
    }
})->with(UserRole::cases())->with(UserStatus::cases());

test('foreign or unverified context cannot authorize candidate catalog browsing or submission', function () {
    $application = Application::factory()->create();
    $intruder = User::factory()->create();
    expect(Gate::forUser($intruder)->allows('browseForCandidate', [AdmissionRound::class, $application->candidateProfile]))->toBeFalse();
    expect(Gate::forUser($intruder)->allows('browseForApplication', [AdmissionProgram::class, $application]))->toBeFalse();
    expect(Gate::forUser($intruder)->allows('submit', $application))->toBeFalse();
    $owner = $application->candidateProfile->user;
    $owner->forceFill(['email_verified_at' => null])->save();
    expect(Gate::forUser($owner)->allows('browseForCandidate', [AdmissionRound::class, $application->candidateProfile]))->toBeFalse();
    expect(Gate::forUser($owner)->allows('browseForApplication', [AdmissionProgram::class, $application]))->toBeFalse();
});
