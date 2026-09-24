<?php

use App\Actions\ConfirmAdmissionResult;
use App\Actions\PublishAdmissionResults;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Admin\AdmissionRounds;
use App\Livewire\Admin\Results as AdminResults;
use App\Livewire\Candidate\Notifications;
use App\Livewire\Candidate\Results;
use App\Models\ActivityLog;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/AdmissionEngineFixtures.php';

function publicationFixture(): array
{
    $program = engineProgram();
    $application = engineApplication($program, '8.125');
    $other = engineApplication($program, '7.500');
    test()->actingAs($admin = User::factory()->create(['role' => UserRole::Admin]));
    engineRun($program);

    return [$program, $application, $other, $admin];
}

test('admin reviews publishes and retries immutable results with one notification per application', function () {
    $this->freezeTime();
    [$program, $application, $other] = publicationFixture();
    $before = AdmissionResult::all()->map->only(['id', 'admission_wish_id', 'final_score', 'rank', 'decision', 'decided_at'])->all();
    $wishes = AdmissionWish::all()->toArray();
    $applications = Application::all()->toArray();

    $this->get(route('admin.results.index'))->assertOk();
    Livewire::test(AdminResults::class)->set('roundSelection', (string) $program->admission_round_id)
        ->assertSee($application->application_code)->assertSee('8.125')->call('publish')->assertHasNoErrors()->assertSee('Already published');

    expect($program->admissionRound->fresh()->status->value)->toBe('published');
    expect(AdmissionResult::all()->pluck('published_at')->map->format('Y-m-d H:i:s')->unique()->all())->toBe([now()->format('Y-m-d H:i:s')]);
    expect(AdmissionResult::all()->map->only(['id', 'admission_wish_id', 'final_score', 'rank', 'decision', 'decided_at'])->all())->toEqual($before);
    expect(AdmissionWish::all()->toArray())->toBe($wishes);
    expect(Application::all()->toArray())->toBe($applications);
    $publication = app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    $this->travel(5)->minutes();
    expect(app(PublishAdmissionResults::class)->publish($program->admission_round_id))->toBe($publication);
    expect(ActivityLog::where('action', 'admission_results.published')->count())->toBe(1);
    $this->assertDatabaseCount('notifications', 2);
    foreach ([$application, $other] as $app) {
        $notification = $app->candidateProfile->user->notifications()->sole();
        expect($notification->data)->toHaveKeys(['round_id', 'round_name', 'application_id', 'message']);
        expect(array_keys($notification->data))->toBe(['round_id', 'round_name', 'application_id', 'message']);
        expect($notification->data['application_id'])->toBe($app->id);
    }
});

test('publication policy requires an active verified admin', function (UserRole $role, UserStatus $status, bool $verified) {
    $user = User::factory()->create(['role' => $role, 'status' => $status, 'email_verified_at' => $verified ? now() : null]);
    expect(Gate::forUser($user)->allows('publishResults', AdmissionRound::class))
        ->toBe($role === UserRole::Admin && $status === UserStatus::Active && $verified);
})->with(UserRole::cases())->with(UserStatus::cases())->with([true, false]);

test('candidate and staff cannot access publication or call its action', function (UserRole $role) {
    [$program] = publicationFixture();
    $this->actingAs(User::factory()->create(['role' => $role]));
    $this->get(route('admin.results.index'))->assertForbidden();
    Livewire::test(AdminResults::class)->assertForbidden();
    expect(fn () => app(PublishAdmissionResults::class)->publish($program->admission_round_id))->toThrow(HttpException::class);
    $this->assertDatabaseCount('notifications', 0);
})->with([UserRole::Candidate, UserRole::Staff]);

test('stale admin cannot publish after account changes', function (array $changes) {
    [$program, , , $admin] = publicationFixture();
    $component = Livewire::test(AdminResults::class)->set('roundSelection', (string) $program->admission_round_id);
    User::whereKey($admin->id)->update($changes);
    $component->call('publish')->assertForbidden();
    expect(fn () => app(PublishAdmissionResults::class)->publish($program->admission_round_id))->toThrow(HttpException::class);
    $this->assertDatabaseCount('notifications', 0);
})->with([[['role' => 'candidate']], [['role' => 'staff']], [['status' => 'inactive']], [['status' => 'locked']], [['email_verified_at' => null]]]);

test('unverified admin is redirected and denied publication', function () {
    $this->actingAs(User::factory()->unverified()->create(['role' => UserRole::Admin]));
    $this->get(route('admin.results.index'))->assertRedirect(route('verification.notice'));
    Livewire::test(AdminResults::class)->assertForbidden();
});

test('rounds without a recognized completed run cannot publish', function (string $status) {
    $round = AdmissionRound::factory()->create(['status' => $status]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    expect(fn () => app(PublishAdmissionResults::class)->publish($round->id))->toThrow(ValidationException::class);
    expect($round->fresh()->status->value)->toBe($status);
    $this->assertDatabaseCount('notifications', 0);
})->with(['draft', 'open', 'closed', 'processing', 'published']);

test('corrupted or partial output blocks publication without side effects', function (string $corruption) {
    [$program] = publicationFixture();
    $roundId = $program->admission_round_id;
    $result = AdmissionResult::firstOrFail();
    match ($corruption) {
        'score' => $result->update(['final_score' => '9.999']),
        'rank' => $result->update(['rank' => 99]),
        'decision' => $result->update(['decision' => 'not_admitted']),
        'missing' => $result->delete(),
        'partial publication' => $result->update(['published_at' => now()]),
        'confirmation' => $result->update(['confirmed_at' => now()]),
        'missing completion' => ActivityLog::where('action', 'admission_engine.completed')->delete(),
        'cross round' => $program->update(['admission_round_id' => AdmissionRound::factory()->create()->id]),
        'application' => $result->admissionWish->application->update(['status' => 'draft']),
    };
    expect(fn () => app(PublishAdmissionResults::class)->publish($roundId))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('notifications', 0);
    expect(ActivityLog::where('action', 'admission_results.published')->count())->toBe(0);
})->with(['score', 'rank', 'decision', 'missing', 'partial publication', 'confirmation', 'missing completion', 'cross round', 'application']);

test('publication rolls back results round notifications and audit if audit persistence fails', function () {
    [$program] = publicationFixture();
    ActivityLog::creating(function (ActivityLog $log) {
        if ($log->action === 'admission_results.published') {
            throw new RuntimeException('Audit unavailable');
        }
    });
    try {
        expect(fn () => app(PublishAdmissionResults::class)->publish($program->admission_round_id))->toThrow(RuntimeException::class);
    } finally {
        ActivityLog::flushEventListeners();
    }
    expect($program->admissionRound->fresh()->status->value)->toBe('processing');
    expect(AdmissionResult::whereNotNull('published_at')->count())->toBe(0);
    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('activity_logs', 2);
});

test('candidate sees only own published result with score rank major and decision', function () {
    [$program, $application, $other, $admin] = publicationFixture();
    $candidate = $application->candidateProfile->user;
    $this->actingAs($candidate);
    $this->get(route('candidate.results.index'))->assertOk()->assertDontSee('8.125')->assertDontSee('7.500');
    Livewire::test(Results::class)->assertDontSee('8.125')->assertDontSee('7.500');
    $this->actingAs($admin);
    app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    $this->actingAs($candidate);
    Livewire::test(Results::class)->assertSee('8.125')->assertSee('Trúng tuyển')->assertSee($program->major->name)
        ->assertDontSee($program->admissionMethod->name)->assertDontSee($program->admissionMethod->code)->assertDontSee('7.500')
        ->assertViewHas('results', fn ($results) => $results->count() === 1 && $results->first()->rank === 1);
    $this->actingAs($other->candidateProfile->user);
    Livewire::test(Results::class)->assertSee('Không trúng tuyển')->assertSee('Điểm xét tuyển')
        ->assertSee('Thứ hạng')->assertSee('Thời gian công bố')->assertDontSee('8.125');
});

test('candidate confirms once and publication retries remain read only after confirmation', function () {
    $this->freezeTime();
    [$program, $application, , $admin] = publicationFixture();
    app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    $result = AdmissionResult::where('decision', 'admitted')->sole();
    $before = $result->only(['final_score', 'rank', 'decision', 'published_at']);
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(Results::class)->call('confirm', $result->id)->assertHasNoErrors()->assertSee('Đã xác nhận');
    $time = $result->fresh()->confirmed_at->format('Y-m-d H:i:s');
    $this->travel(10)->minutes();
    Livewire::test(Results::class)->call('confirm', $result->id)->assertHasNoErrors();
    expect($result->fresh()->confirmed_at->format('Y-m-d H:i:s'))->toBe($time);
    expect($result->fresh()->only(['final_score', 'rank', 'decision', 'published_at']))->toEqual($before);
    expect(ActivityLog::where('action', 'admission_result.confirmed')->count())->toBe(1);
    $this->actingAs($admin);
    app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    $this->assertDatabaseCount('notifications', 2);
    $this->assertDatabaseCount('activity_logs', 4);
});

test('candidate cannot confirm an ineligible target', function (string $case) {
    [$program, $application, $other] = publicationFixture();
    if ($case !== 'unpublished') {
        app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    }
    $result = AdmissionResult::where('decision', $case === 'not admitted' ? 'not_admitted' : 'admitted')->sole();
    $actor = $case === 'foreign' || $case === 'not admitted' ? $other->candidateProfile->user : $application->candidateProfile->user;
    if ($case === 'round') {
        $program->admissionRound->update(['status' => 'processing']);
    }
    if ($case === 'relationship') {
        $program->update(['admission_round_id' => AdmissionRound::factory()->create()->id]);
    }
    $this->actingAs($actor);
    if ($case === 'not admitted') {
        Livewire::test(Results::class)->call('confirm', $result->id)->assertNotFound();
    } else {
        expect(fn () => Livewire::test(Results::class)->call('confirm', $result->id))->toThrow(ModelNotFoundException::class);
    }
    expect($result->fresh()->confirmed_at)->toBeNull();
    expect(ActivityLog::where('action', 'admission_result.confirmed')->count())->toBe(0);
})->with(['unpublished', 'not admitted', 'foreign', 'round', 'relationship']);

test('candidate notifications are private and read state is idempotent', function () {
    $this->freezeTime();
    [$program, $application, $other] = publicationFixture();
    app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    $candidate = $application->candidateProfile->user;
    $own = $candidate->notifications()->sole();
    $foreign = $other->candidateProfile->user->notifications()->sole();
    $this->actingAs($candidate);
    Livewire::test(Notifications::class)->assertSee('Chưa đọc')->assertSee(route('candidate.results.index'))
        ->assertViewHas('notifications', fn ($items) => $items->count() === 1)
        ->call('markRead', $own->id)->assertSee('Đã đọc')->assertDontSee('Đánh dấu đã đọc');
    $time = $own->fresh()->read_at->format('Y-m-d H:i:s');
    $this->travel(10)->minutes();
    Livewire::test(Notifications::class)->call('markRead', $own->id);
    expect($own->fresh()->read_at->format('Y-m-d H:i:s'))->toBe($time);
    expect(fn () => Livewire::test(Notifications::class)->call('markRead', $foreign->id))->toThrow(ModelNotFoundException::class);
    expect($foreign->fresh()->read_at)->toBeNull();
});

test('publication rejects corrupted completed publication history without repairing it', function (string $case) {
    [$program] = publicationFixture();
    app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    $result = AdmissionResult::firstOrFail();
    match ($case) {
        'missing timestamp' => $result->update(['published_at' => null]),
        'different timestamp' => $result->update(['published_at' => now()->subDay()]),
        'missing audit' => ActivityLog::where('action', 'admission_results.published')->delete(),
        'missing notification' => DatabaseNotification::firstOrFail()->delete(),
        'wrong recipient' => DatabaseNotification::firstOrFail()->update(['notifiable_id' => User::factory()->create()->id]),
        'changed score' => $result->update(['final_score' => '9.999']),
    };
    $before = AdmissionResult::all()->toArray();
    $notificationCount = DatabaseNotification::count();
    expect(fn () => app(PublishAdmissionResults::class)->publish($program->admission_round_id))->toThrow(ValidationException::class);
    expect(AdmissionResult::all()->toArray())->toBe($before);
    expect(DatabaseNotification::count())->toBe($notificationCount);
})->with(['missing timestamp', 'different timestamp', 'missing audit', 'missing notification', 'wrong recipient', 'changed score']);

test('notification persistence failure rolls publication back', function () {
    [$program] = publicationFixture();
    DatabaseNotification::creating(fn () => false);
    try {
        expect(fn () => app(PublishAdmissionResults::class)->publish($program->admission_round_id))->toThrow(ValidationException::class);
    } finally {
        DatabaseNotification::flushEventListeners();
    }
    expect($program->admissionRound->fresh()->status->value)->toBe('processing');
    expect(AdmissionResult::whereNotNull('published_at')->count())->toBe(0);
    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('activity_logs', 2);
});

test('confirmation audit failure rolls back confirmation', function () {
    [$program, $application] = publicationFixture();
    app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    $result = AdmissionResult::where('decision', 'admitted')->sole();
    $this->actingAs($application->candidateProfile->user);
    ActivityLog::creating(function (ActivityLog $log) {
        if ($log->action === 'admission_result.confirmed') {
            return false;
        }
    });
    try {
        expect(fn () => app(ConfirmAdmissionResult::class)->confirm($result->id))->toThrow(ValidationException::class);
    } finally {
        ActivityLog::flushEventListeners();
    }
    expect($result->fresh()->confirmed_at)->toBeNull();
    expect(ActivityLog::where('action', 'admission_result.confirmed')->count())->toBe(0);
});

test('stale candidate cannot confirm or read notifications after losing account access', function (array $changes) {
    [$program, $application] = publicationFixture();
    app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    $result = AdmissionResult::where('decision', 'admitted')->sole();
    $candidate = $application->candidateProfile->user;
    $notification = $candidate->notifications()->sole();
    $this->actingAs($candidate);
    $results = Livewire::test(Results::class);
    $notifications = Livewire::test(Notifications::class);
    User::whereKey($candidate->id)->update($changes);
    $results->call('confirm', $result->id)->assertForbidden();
    $notifications->call('markRead', $notification->id)->assertForbidden();
    expect($result->fresh()->confirmed_at)->toBeNull();
    expect($notification->fresh()->read_at)->toBeNull();
})->with([[['role' => 'staff']], [['status' => 'locked']], [['status' => 'inactive']], [['email_verified_at' => null]]]);

test('guests cannot access results or notifications', function () {
    foreach (['admin.results.index', 'candidate.results.index', 'candidate.notifications.index'] as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }
    expect(fn () => app(ConfirmAdmissionResult::class)->confirm(1))->toThrow(HttpException::class);
    expect(fn () => app(PublishAdmissionResults::class)->publish(1))->toThrow(HttpException::class);
});

test('staff and admin cannot use candidate results or notifications', function (UserRole $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $this->get(route('candidate.results.index'))->assertForbidden();
    $this->get(route('candidate.notifications.index'))->assertForbidden();
    expect(fn () => app(ConfirmAdmissionResult::class)->confirm(1))->toThrow(HttpException::class);
})->with([UserRole::Staff, UserRole::Admin]);

test('candidate result responses escape names and contain no unpublished result state or private profile data', function () {
    [$program, $application, , $admin] = publicationFixture();
    $program->major->update(['name' => '<script>alert(1)</script>']);
    $application->candidateProfile->update(['citizen_id' => '123456789123', 'address' => 'SECRET ADDRESS']);
    $this->actingAs($application->candidateProfile->user);
    $component = Livewire::test(Results::class)->assertViewHas('results', fn ($items) => $items->isEmpty());
    expect($component->snapshot['data'])->not->toHaveKeys(['results', 'decision', 'final_score', 'rank']);
    $this->get(route('candidate.results.index'))->assertDontSee('8.125')->assertDontSee('123456789123')->assertDontSee('SECRET ADDRESS');
    $this->actingAs($admin);
    app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(Results::class)->assertSee('<script>alert(1)</script>')->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertDontSee('123456789123')->assertDontSee('SECRET ADDRESS');
});

test('invalid persisted decision is blocked without an enum exception', function () {
    [$program] = publicationFixture();
    AdmissionResult::query()->update(['decision' => 'corrupt']);
    expect(fn () => app(PublishAdmissionResults::class)->publish($program->admission_round_id))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('notifications', 0);
});

test('one candidate with multiple wishes receives one notification', function () {
    $program = engineProgram();
    $application = engineApplication($program);
    $second = engineProgram($program->admissionRound);
    AdmissionWish::factory()->for($application)->for($second)->create(['priority' => 2]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    engineRun($program);
    app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    $this->assertDatabaseCount('admission_results', 2);
    $this->assertDatabaseCount('notifications', 1);
});

test('configuration cannot create a published round or reopen a published round', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Livewire::test(AdmissionRounds::class)->call('create')->set('form', [
        'code' => 'BYPASS', 'name' => 'Bypass', 'year' => 2026, 'start_date' => '2026-01-01T00:00',
        'end_date' => '2026-02-01T00:00', 'result_date' => null, 'status' => 'published',
    ])->call('save')->assertHasErrors('form.status');
    $this->assertDatabaseCount('admission_rounds', 0);
    [$program] = publicationFixture();
    app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    Livewire::test(AdmissionRounds::class)->call('edit', $program->admission_round_id)
        ->set('form.status', 'open')->call('save')->assertHasErrors('form.status');
    expect($program->admissionRound->fresh()->status->value)->toBe('published');
});

test('forged Livewire requests cannot confirm foreign results or read foreign notifications', function (string $target) {
    [$program, $application, $other] = publicationFixture();
    app(PublishAdmissionResults::class)->publish($program->admission_round_id);
    $this->actingAs($other->candidateProfile->user);
    $route = $target === 'result' ? 'candidate.results.index' : 'candidate.notifications.index';
    $id = $target === 'result' ? AdmissionResult::where('decision', 'admitted')->sole()->id : $application->candidateProfile->user->notifications()->sole()->id;
    $response = $this->get(route($route))->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    expect($matches)->toHaveCount(2);
    $this->postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => html_entity_decode($matches[1], ENT_QUOTES), 'updates' => [],
        'calls' => [['path' => '', 'method' => $target === 'result' ? 'confirm' : 'markRead', 'params' => [$id]]],
    ]]], ['X-Livewire' => 'true'])->assertNotFound();
    expect(AdmissionResult::whereNotNull('confirmed_at')->count())->toBe(0);
    expect(DatabaseNotification::whereNotNull('read_at')->count())->toBe(0);
})->with(['result', 'notification']);
