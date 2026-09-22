<?php

use App\Enums\AdmissionRoundStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Candidate\AdmissionInformation;
use App\Livewire\Candidate\ApplicationDetails;
use App\Livewire\Candidate\Applications;
use App\Livewire\Candidate\Documents;
use App\Livewire\Candidate\Profile;
use App\Livewire\Candidate\Scores;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\CandidateScore;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

dataset('candidate pages', [['candidate.profile.edit', Profile::class], ['candidate.admission-information.index', AdmissionInformation::class], ['candidate.scores.index', Scores::class], ['candidate.documents.index', Documents::class], ['candidate.applications.index', Applications::class]]);

test('candidate routes require authenticated verified candidates', function (string $route, string $component) {
    $this->get(route($route))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get(route($route))->assertRedirect(route('verification.notice'));
    $this->actingAs(User::factory()->create())->get(route($route))->assertOk();
    foreach ([UserRole::Staff, UserRole::Admin] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))->get(route($route))->assertForbidden();
        Livewire::test($component)->assertForbidden();
    }
})->with('candidate pages');

test('inactive and locked candidates cannot access pages or mount components', function (string $route, string $component, UserStatus $status) {
    $this->actingAs(User::factory()->create(['status' => $status]))->get(route($route))->assertForbidden();
    Livewire::test($component)->assertForbidden();
})->with('candidate pages')->with([UserStatus::Inactive, UserStatus::Locked]);

test('candidate navigation respects role and account status while account settings remain available', function (UserRole $role, UserStatus $status) {
    $this->actingAs(User::factory()->create(['role' => $role, 'status' => $status]));
    $response = $this->get(route('profile.edit'))->assertOk();
    if ($role === UserRole::Candidate && $status === UserStatus::Active) {
        $response->assertSeeInOrder(['Hồ sơ cá nhân', 'Thông tin tuyển sinh', 'Đăng ký nguyện vọng', 'Kết quả xét tuyển', 'Thông báo'])
            ->assertSee(route('candidate.profile.edit'))->assertSee(route('candidate.admission-information.index'))
            ->assertDontSee('Điểm & minh chứng')
            ->assertSee(route('candidate.applications.index'))->assertSee(route('candidate.results.index'))
            ->assertSee(route('candidate.notifications.index'))->assertDontSee(route('candidate.documents.index'));
    } else {
        $response->assertDontSee(route('candidate.profile.edit'));
    }
})->with(UserRole::cases())->with(UserStatus::cases());

test('real Livewire updates reject accounts restricted after page load', function (string $route, string $component, UserStatus $status) {
    $user = User::factory()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $response = $this->get(route($route))->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    User::query()->whereKey($user->id)->update(['status' => $status]);
    Auth::forgetGuards();
    Livewire::flushState();
    $this->postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => html_entity_decode($matches[1], ENT_QUOTES), 'updates' => [],
        'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
    ]]], ['X-Livewire' => 'true'])->assertForbidden();
})->with('candidate pages')->with([UserStatus::Inactive, UserStatus::Locked]);

test('candidate mutations reject a role changed after opening the editor', function (UserRole $role) {
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    $page = Livewire::test(Profile::class)->set('form.address', 'must not save');
    User::query()->whereKey($profile->user_id)->update(['role' => $role]);
    $page->call('save')->assertForbidden();
    expect($profile->fresh()->address)->toBeNull();
})->with([UserRole::Staff, UserRole::Admin]);

test('profile and child identifiers cannot be tampered with through hydration', function (string $kind) {
    $profile = CandidateProfile::factory()->create();
    $this->actingAs($profile->user);
    if ($kind === 'profile') {
        $page = Livewire::test(Profile::class);
        $property = 'profileId';
    } elseif (str_starts_with($kind, 'score')) {
        $score = CandidateScore::factory()->for($profile)->create();
        $property = $kind === 'score-edit' ? 'recordId' : 'deleteId';
        $page = Livewire::test(Scores::class)->call($kind === 'score-edit' ? 'edit' : 'confirmDeletion', $score->id);
    } else {
        $application = Application::factory()->for($profile)->create();
        $document = CandidateDocument::factory()->for($application)->create();
        $property = match ($kind) {
            'document-edit' => 'recordId', 'document-delete' => 'deleteId', default => 'applicationId'
        };
        $page = Livewire::test(Documents::class, ['application' => $application->id]);
        if ($property !== 'applicationId') {
            $page->call($property === 'recordId' ? 'edit' : 'confirmDeletion', $document->id);
        }
    }
    expect(fn () => $page->set($property, 999999))->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['profile', 'score-edit', 'score-delete', 'document-edit', 'document-delete', 'application']);

test('foreign score ids return 404 through real Livewire action requests', function (string $action) {
    $profile = CandidateProfile::factory()->create();
    $foreign = CandidateScore::factory()->create();
    $this->actingAs($profile->user);
    $response = $this->get(route('candidate.scores.index'));
    preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $this->postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => html_entity_decode($matches[1], ENT_QUOTES), 'updates' => [],
        'calls' => [['path' => '', 'method' => $action, 'params' => [$foreign->id]]],
    ]]], ['X-Livewire' => 'true'])->assertNotFound();
    $this->assertModelExists($foreign);
})->with(['edit', 'confirmDeletion']);

test('documents cannot be accessed through another application even when both applications are owned', function (bool $sameOwner, string $action) {
    $application = Application::factory()->create();
    $other = $sameOwner ? Application::factory()->for($application->candidateProfile)->create() : Application::factory()->create();
    $document = CandidateDocument::factory()->for($other)->create();
    $this->actingAs($application->candidateProfile->user);
    $response = $this->get(route('candidate.applications.documents.index', $application->id));
    preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $this->postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => html_entity_decode($matches[1], ENT_QUOTES), 'updates' => [],
        'calls' => [['path' => '', 'method' => $action, 'params' => [$document->id]]],
    ]]], ['X-Livewire' => 'true'])->assertNotFound();
    $this->assertModelExists($document);
})->with([true, false])->with(['edit', 'confirmDeletion']);

test('ownership is rechecked from storage before saving an already opened score', function () {
    $score = CandidateScore::factory()->create();
    $this->actingAs($score->candidateProfile->user);
    $page = Livewire::test(Scores::class)->call('edit', $score->id);
    $score->update(['candidate_profile_id' => CandidateProfile::factory()->create()->id]);
    expect(fn () => $page->set('form.score', '9')->call('save'))->toThrow(ModelNotFoundException::class);
    expect($score->fresh()->score)->toBe('8.250');
});

test('application context and order cannot be replaced through Livewire hydration', function (string $property) {
    $application = Application::factory()->create();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id]);
    expect(fn () => $page->set($property, $property === 'expectedOrder' ? [999999] : 999999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['applicationId', 'deleteId', 'expectedOrder']);

test('real application update requests reject changed account permissions', function (string $change) {
    $application = Application::factory()->create();
    $user = $application->candidateProfile->user;
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $response = $this->get(route('candidate.applications.show', $application->id));
    preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $attributes = match ($change) {
        'inactive' => ['status' => UserStatus::Inactive], 'locked' => ['status' => UserStatus::Locked],
        'staff' => ['role' => UserRole::Staff], 'admin' => ['role' => UserRole::Admin], 'unverified' => ['email_verified_at' => null],
    };
    User::query()->whereKey($user->id)->update($attributes);
    Auth::forgetGuards();
    Livewire::flushState();
    $response = $this->postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => html_entity_decode($matches[1], ENT_QUOTES), 'updates' => [],
        'calls' => [['path' => '', 'method' => 'submit', 'params' => []]],
    ]]], ['X-Livewire' => 'true']);
    $response->assertForbidden();
    expect($application->fresh()->submitted_at)->toBeNull();
})->with(['inactive', 'locked', 'staff', 'admin', 'unverified']);

test('application actions recheck ownership after the page was mounted', function (string $action) {
    $application = Application::factory()->create();
    $application->admissionRound->update(['status' => AdmissionRoundStatus::Open]);
    $wish = AdmissionWish::factory()->for($application)->create();
    $this->actingAs($application->candidateProfile->user);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmDeletion', $wish->id)
        ->set('form.admission_program_id', $wish->admission_program_id);
    $application->update(['candidate_profile_id' => CandidateProfile::factory()->create()->id]);
    expect(fn () => $page->call($action, ...($action === 'reorderWishes' ? [[$wish->id]] : [])))->toThrow(ModelNotFoundException::class);
    $this->assertModelExists($wish);
    expect($application->fresh()->submitted_at)->toBeNull();
})->with(['addWish', 'deleteWish', 'reorderWishes', 'submit']);

test('actions reject a changed role status or verification after application forms opened', function (string $change, string $action) {
    $application = Application::factory()->create();
    $application->admissionRound->update(['status' => AdmissionRoundStatus::Open]);
    $wish = AdmissionWish::factory()->for($application)->create();
    $user = $application->candidateProfile->user;
    $this->actingAs($user);
    $page = $action === 'save' ? Livewire::test(Applications::class)->set('form.admission_round_id', $application->admission_round_id)
        : Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirmDeletion', $wish->id)->set('form.admission_program_id', $wish->admission_program_id);
    User::query()->whereKey($user->id)->update(match ($change) {
        'inactive' => ['status' => UserStatus::Inactive], 'locked' => ['status' => UserStatus::Locked],
        'staff' => ['role' => UserRole::Staff], 'admin' => ['role' => UserRole::Admin], 'unverified' => ['email_verified_at' => null],
    });
    $page->call($action, ...($action === 'reorderWishes' ? [[$wish->id]] : []))->assertForbidden();
    $this->assertModelExists($wish);
    $this->assertDatabaseCount('applications', 1);
    expect($application->fresh()->submitted_at)->toBeNull();
})->with(['inactive', 'locked', 'staff', 'admin', 'unverified'])->with(['save', 'addWish', 'deleteWish', 'reorderWishes', 'submit']);
