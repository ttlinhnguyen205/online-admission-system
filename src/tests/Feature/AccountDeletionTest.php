<?php

use App\Actions\DeleteUser;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Settings\DeleteUserForm;
use App\Models\ActivityLog;
use App\Models\AdmissionResult;
use App\Models\Announcement;
use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\CandidateScore;
use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

test('candidate profiles and authored announcements block deletion without logging out', function (string $model, string $relation, UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    $record = $model::factory()->for($user, $relation)->create();
    Event::fake([Logout::class]);
    $this->actingAs($user);

    Livewire::test(DeleteUserForm::class)->set('password', 'password')->call('deleteUser')
        ->assertHasErrors(['accountDeletion'])
        ->assertSee('Your account cannot be deleted because it has protected admission records or authored announcements.')
        ->assertNoRedirect();

    $this->assertModelExists($user);
    $this->assertModelExists($record);
    $this->assertAuthenticatedAs($user);
    Event::assertNotDispatched(Logout::class);
})->with([
    [CandidateProfile::class, 'user'], [Announcement::class, 'creator'],
])->with(UserRole::cases());

test('denied deletion retains the complete admission history', function () {
    $result = AdmissionResult::factory()->create();
    $wish = $result->admissionWish;
    $application = $wish->application;
    $profile = $application->candidateProfile;
    $document = CandidateDocument::factory()->for($application)->create();
    $score = CandidateScore::factory()->for($profile)->create();
    $this->actingAs($profile->user);

    Livewire::test(DeleteUserForm::class)->set('password', 'password')->call('deleteUser')->assertHasErrors(['accountDeletion']);

    foreach ([$result, $wish, $application, $profile, $document, $score] as $record) {
        $this->assertModelExists($record);
    }
    $this->assertAuthenticatedAs($profile->user);
});

test('eligible accounts are deleted before logout and the session is invalidated', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    $this->actingAs($user)->withSession(['deletion_marker' => 'present']);
    $deletedAtLogout = false;
    Event::listen(Logout::class, function (Logout $event) use (&$deletedAtLogout, $user): void {
        $deletedAtLogout = $event->user->getAuthIdentifier() === $user->id
            && ! User::query()->whereKey($user->id)->exists();
    });

    try {
        Livewire::test(DeleteUserForm::class)->set('password', 'password')->call('deleteUser')
            ->assertHasNoErrors()->assertRedirect('/');
    } finally {
        Event::forget(Logout::class);
    }

    $this->assertModelMissing($user);
    $this->assertGuest();
    expect(session()->has('deletion_marker'))->toBeFalse();
    expect($deletedAtLogout)->toBeTrue();
})->with(UserRole::cases());

test('review attribution and recipient records do not prevent safe deletion', function () {
    $user = User::factory()->create(['role' => UserRole::Staff]);
    $application = Application::factory()->for($user, 'reviewer')->create();
    $document = CandidateDocument::factory()->for($application)->for($user, 'verifier')->create();
    $score = CandidateScore::factory()->for($application->candidateProfile)->for($user, 'verifier')->create();
    $log = ActivityLog::factory()->for($user)->create();
    $announcement = Announcement::factory()->create();
    $announcement->users()->attach($user);
    $this->actingAs($user);

    Livewire::test(DeleteUserForm::class)->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');

    $this->assertModelMissing($user);
    $this->assertGuest();
    $this->assertModelExists($announcement);
    expect($announcement->users()->exists())->toBeFalse();
    expect($application->fresh()->reviewed_by)->toBeNull();
    expect($document->fresh()->verified_by)->toBeNull();
    expect($score->fresh()->verified_by)->toBeNull();
    expect($log->fresh()->user_id)->toBeNull();
});

test('password validation precedes protected record checks', function () {
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);

    Livewire::test(DeleteUserForm::class)->set('password', 'wrong-password')->call('deleteUser')
        ->assertHasErrors(['password'])->assertHasNoErrors(['accountDeletion'])->assertNoRedirect();

    $this->assertAuthenticatedAs($profile->user);
    $this->assertModelExists($profile);
    $this->assertModelExists($profile->user);
});

test('unverified accounts cannot invoke deletion directly', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    Livewire::test(DeleteUserForm::class)->set('password', 'password')->call('deleteUser')
        ->assertHasErrors(['accountDeletion'])->assertNoRedirect();

    $this->assertModelExists($user);
    $this->assertAuthenticatedAs($user);
});

test('deletion rechecks persisted status rather than a stale authenticated model', function (UserStatus $status) {
    $user = User::factory()->create();
    $this->actingAs($user);
    User::query()->whereKey($user->id)->update(['status' => $status]);

    Livewire::test(DeleteUserForm::class)->set('password', 'password')->call('deleteUser')
        ->assertHasErrors(['accountDeletion'])->assertNoRedirect();

    $this->assertModelExists($user);
    $this->assertAuthenticatedAs($user);
})->with([UserStatus::Inactive, UserStatus::Locked]);

test('fresh relationship checks ignore a cached absence of protected records', function () {
    $user = User::factory()->create();
    $user->load('candidateProfile', 'createdAnnouncements');
    $announcement = Announcement::factory()->for($user, 'creator')->create();
    $this->actingAs($user);

    Livewire::test(DeleteUserForm::class)->set('password', 'password')->call('deleteUser')->assertHasErrors(['accountDeletion']);

    $this->assertModelExists($announcement);
    $this->assertAuthenticatedAs($user);
});

test('a foreign key restriction arising after authorization becomes a controlled validation error', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    User::deleting(function (User $deletingUser): void {
        CandidateProfile::factory()->for($deletingUser)->create();
    });

    try {
        Livewire::test(DeleteUserForm::class)->set('password', 'password')->call('deleteUser')
            ->assertHasErrors(['accountDeletion'])
            ->assertSee('Your account cannot be deleted because it has protected admission records or authored announcements.')
            ->assertNoRedirect();
    } finally {
        Event::forget('eloquent.deleting: '.User::class);
    }

    $this->assertModelExists($user);
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseCount('candidate_profiles', 0);
});

test('unrelated database failures are not disguised as protected record errors', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    User::deleting(function (): void {
        DB::select('select * from missing_deletion_test_table');
    });

    try {
        expect(fn () => (new DeleteUser)($user))->toThrow(QueryException::class);
    } finally {
        Event::forget('eloquent.deleting: '.User::class);
    }

    $this->assertModelExists($user);
    $this->assertAuthenticatedAs($user);
});

test('a cancelled model deletion does not log the account out', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    User::deleting(fn (): bool => false);

    try {
        Livewire::test(DeleteUserForm::class)->set('password', 'password')->call('deleteUser')
            ->assertHasErrors(['accountDeletion'])->assertSee('Your account could not be deleted.')
            ->assertNoRedirect();
    } finally {
        Event::forget('eloquent.deleting: '.User::class);
    }

    $this->assertModelExists($user);
    $this->assertAuthenticatedAs($user);
});

test('foreign key failures from other statements are not reported as protected account records', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    User::deleting(function (): void {
        CandidateProfile::factory()->create(['user_id' => 999999]);
    });

    try {
        expect(fn () => (new DeleteUser)($user))->toThrow(QueryException::class);
    } finally {
        Event::forget('eloquent.deleting: '.User::class);
    }

    $this->assertModelExists($user);
    $this->assertAuthenticatedAs($user);
});
