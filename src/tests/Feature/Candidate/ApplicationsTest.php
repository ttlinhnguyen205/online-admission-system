<?php

use App\Enums\AdmissionRoundStatus;
use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Candidate\ApplicationDetails;
use App\Livewire\Candidate\Applications;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
});

test('application pages require authenticated verified active candidates', function (string $page) {
    $application = Application::factory()->create();
    $url = $page === 'index' ? route('candidate.applications.index') : route('candidate.applications.show', $application->id);
    $component = $page === 'index' ? Applications::class : ApplicationDetails::class;
    $parameters = $page === 'index' ? [] : ['application' => $application->id];
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get($url)->assertRedirect(route('verification.notice'));
    $this->actingAs($application->candidateProfile->user)->get($url)->assertOk();
    foreach ([UserRole::Staff, UserRole::Admin] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))->get($url)->assertForbidden();
        Livewire::test($component, $parameters)->assertForbidden();
    }
    foreach ([UserStatus::Inactive, UserStatus::Locked] as $status) {
        $this->actingAs(User::factory()->create(['status' => $status]))->get($url)->assertForbidden();
        Livewire::test($component, $parameters)->assertForbidden();
    }
})->with(['index', 'detail']);

test('applications require a saved profile and never create one on behalf of the candidate', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test(Applications::class)->assertSee('Hãy tạo hồ sơ thí sinh');
    $this->get(route('candidate.applications.show', 999999))->assertNotFound();
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open]);
    $response = $this->get(route('candidate.applications.index'));
    preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $this->postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
        'updates' => ['form.admission_round_id' => $round->id],
        'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
    ]]], ['X-Livewire' => 'true'])->assertNotFound();
    $this->assertDatabaseCount('candidate_profiles', 0);
    $this->assertDatabaseCount('applications', 0);
});

test('an incomplete profile creates one owned draft with a server generated stable code and document links', function () {
    $profile = CandidateProfile::factory()->create();
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open]);
    $this->actingAs($profile->user);
    Livewire::test(Applications::class)->call('create')->set('form.admission_round_id', $round->id)->call('save')->assertHasNoErrors();
    $application = $profile->applications()->sole();
    expect($application->application_code)->toStartWith('APP-')->toHaveLength(30);
    expect(Str::isUlid(substr($application->application_code, 4)))->toBeTrue();
    expect($application->status)->toBe(ApplicationStatus::Draft);
    expect($application->admission_round_id)->toBe($round->id);
    expect($application->submitted_at)->toBeNull();
    expect($application->reviewed_by)->toBeNull();
    $this->get(route('candidate.applications.show', $application->id))->assertSee(route('candidate.applications.documents.index', $application->id));
    $this->get(route('candidate.documents.index'))->assertSee($application->application_code);
    $this->get(route('candidate.applications.documents.index', $application->id))->assertSee(route('candidate.applications.show', $application->id));
    expect($application->fresh()->application_code)->toBe($application->application_code);
});

test('duplicate candidate round applications are rejected and a different round is allowed', function () {
    $application = Application::factory()->create();
    $application->admissionRound->update(['status' => AdmissionRoundStatus::Open]);
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(Applications::class)->set('form.admission_round_id', $application->admission_round_id)->call('save')->assertHasErrors('form.admission_round_id');
    $this->assertDatabaseCount('applications', 1);
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open]);
    $page->set('form.admission_round_id', $round->id)->call('save')->assertHasNoErrors();
    $this->assertDatabaseCount('applications', 2);
});

test('creation rejects system and unexpected form keys', function (string $field) {
    $profile = CandidateProfile::factory()->create();
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open]);
    $this->actingAs($profile->user);
    Livewire::test(Applications::class)->set('form', ['admission_round_id' => $round->id, $field => 'tampered'])->call('save')->assertHasErrors('form');
    $this->assertDatabaseCount('applications', 0);
})->with(['application_code', 'candidate_profile_id', 'status', 'submitted_at', 'reviewed_by', 'reviewed_at', 'revision_reason', 'id', 'unexpected']);

test('creation validates the selected round identifier', function (mixed $id) {
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    Livewire::test(Applications::class)->set('form.admission_round_id', $id)->call('save')->assertHasErrors('form.admission_round_id');
    $this->assertDatabaseCount('applications', 0);
})->with([null, '', -1, 0, 1.5, 999999, [1]]);

test('creation requires the exact open round status', function (AdmissionRoundStatus $status) {
    $profile = CandidateProfile::factory()->create();
    $round = AdmissionRound::factory()->create(['status' => $status]);
    $this->actingAs($profile->user);
    $page = Livewire::test(Applications::class)->set('form.admission_round_id', $round->id)->call('save');
    if ($status === AdmissionRoundStatus::Open) {
        $page->assertHasNoErrors();
        $this->assertDatabaseCount('applications', 1);
    } else {
        $page->assertHasErrors('form.admission_round_id');
        $this->assertDatabaseCount('applications', 0);
    }
})->with(AdmissionRoundStatus::cases());

test('creation observes inclusive dates in the configured timezone without a current year restriction', function (string $time, bool $allowed, string $timezone) {
    config(['app.timezone' => $timezone]);
    $this->travelTo(CarbonImmutable::parse($time, $timezone));
    $profile = CandidateProfile::factory()->create();
    $round = AdmissionRound::factory()->create(['year' => 2000, 'status' => AdmissionRoundStatus::Open, 'start_date' => '2000-12-31 23:59:12', 'end_date' => '2001-01-01 00:00:34']);
    $this->actingAs($profile->user);
    $page = Livewire::test(Applications::class)->set('form.admission_round_id', $round->id)->call('save');
    $allowed ? $page->assertHasNoErrors() : $page->assertHasErrors('form.admission_round_id');
    $this->assertDatabaseCount('applications', $allowed ? 1 : 0);
})->with([
    ['2000-12-31 23:59:11', false], ['2000-12-31 23:59:12', true],
    ['2001-01-01 00:00:34', true], ['2001-01-01 00:00:35', false],
])->with(['UTC', 'Asia/Ho_Chi_Minh']);

test('creation rechecks a round closed after the creation form opened', function () {
    $profile = CandidateProfile::factory()->create();
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open]);
    $this->actingAs($profile->user);
    $page = Livewire::test(Applications::class)->call('create')->set('form.admission_round_id', $round->id);
    $round->update(['status' => AdmissionRoundStatus::Closed]);
    $page->call('save')->assertHasErrors('form.admission_round_id');
    $this->assertDatabaseCount('applications', 0);
});

test('application lists are owner scoped and paginate while foreign details return 404', function () {
    $profile = CandidateProfile::factory()->create();
    Application::factory()->for($profile)->count(16)->create();
    $foreign = Application::factory()->create(['application_code' => 'FOREIGN-SECRET']);
    $this->actingAs($profile->user);
    Livewire::test(Applications::class)->assertDontSee('FOREIGN-SECRET')
        ->assertViewHas('records', fn ($records) => $records->total() === 16 && $records->count() === 15)
        ->call('setPage', 2)->assertViewHas('records', fn ($records) => $records->count() === 1);
    $this->get(route('candidate.applications.show', $foreign->id))->assertNotFound();
    $this->get(route('candidate.applications.show', 999999))->assertNotFound();
});

test('real code collisions retry without retrying ownership uniqueness', function (bool $exhausted) {
    $existing = Application::factory()->create(['application_code' => 'APP-01K00000000000000000000000']);
    $profile = CandidateProfile::factory()->create();
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open]);
    $this->actingAs($profile->user);
    $attempts = 0;
    Str::createUlidsUsing(function () use (&$attempts, $exhausted): string {
        $attempts++;

        return $exhausted || $attempts === 1 ? '01K00000000000000000000000' : '01K00000000000000000000001';
    });
    try {
        $page = Livewire::test(Applications::class)->set('form.admission_round_id', $round->id)->call('save');
        $exhausted ? $page->assertHasErrors('form') : $page->assertHasNoErrors();
        expect($attempts)->toBe($exhausted ? 3 : 2);
        expect($profile->applications()->count())->toBe($exhausted ? 0 : 1);
        $this->assertModelExists($existing);
    } finally {
        Str::createUlidsNormally();
    }
})->with([false, true]);

test('a candidate round uniqueness race becomes a validation error and rolls back', function () {
    $profile = CandidateProfile::factory()->create();
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open]);
    $this->actingAs($profile->user);
    $attempts = 0;
    Event::listen('eloquent.creating: '.Application::class, function (Application $application) use (&$attempts): void {
        $attempts++;
        DB::table('applications')->insert(['application_code' => 'RACE', 'candidate_profile_id' => $application->candidate_profile_id, 'admission_round_id' => $application->admission_round_id]);
    });
    try {
        Livewire::test(Applications::class)->set('form.admission_round_id', $round->id)->call('save')->assertHasErrors('form.admission_round_id');
        expect($attempts)->toBe(1);
        $this->assertDatabaseCount('applications', 0);
    } finally {
        Event::forget('eloquent.creating: '.Application::class);
    }
});

test('cancelled application persistence never reports success', function () {
    $profile = CandidateProfile::factory()->create();
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open]);
    $this->actingAs($profile->user);
    Event::listen('eloquent.saving: '.Application::class, fn () => false);
    try {
        Livewire::test(Applications::class)->set('form.admission_round_id', $round->id)->call('save')->assertHasErrors('form');
        $this->assertDatabaseCount('applications', 0);
    } finally {
        Event::forget('eloquent.saving: '.Application::class);
    }
});

test('application creation must authorize the existing create ability', function () {
    $profile = CandidateProfile::factory()->create();
    $round = AdmissionRound::factory()->create(['status' => AdmissionRoundStatus::Open]);
    $this->actingAs($profile->user);
    Gate::before(fn (User $user, string $ability) => $ability === 'create' ? false : null);
    Livewire::test(Applications::class)->set('form.admission_round_id', $round->id)->call('save')->assertForbidden();
    $this->assertDatabaseCount('applications', 0);
});
