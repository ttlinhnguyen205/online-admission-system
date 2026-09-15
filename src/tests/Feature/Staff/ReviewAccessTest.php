<?php

use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Admin\ApplicationDetails;
use App\Livewire\Admin\Applications;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

require_once __DIR__.'/ReviewFixtures.php';

test('review routes require authenticated verified reviewers', function (string $route) {
    $application = Application::factory()->create();
    $url = route($route, $route === 'admin.applications.show' ? $application->id : []);
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create(['role' => UserRole::Staff]))->get($url)->assertRedirect(route('verification.notice'));
    $this->actingAs($application->candidateProfile->user)->get($url)->assertForbidden();
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
})->with(['admin.applications.index', 'admin.applications.show']);

test('both active reviewer roles can access review pages and navigation', function (UserRole $role) {
    $application = reviewApplication();
    $this->actingAs(User::factory()->create(['role' => $role]));
    $this->get(route('admin.applications.index'))->assertOk()->assertSee(route('admin.applications.show', $application->id));
    $this->get(route('admin.applications.show', $application->id))->assertOk();
    $this->get(route('profile.edit'))->assertSee(route('admin.applications.index'));
})->with([UserRole::Staff, UserRole::Admin]);

test('restricted reviewers cannot access or mount review pages', function (UserRole $role, UserStatus $status) {
    $application = Application::factory()->create();
    $this->actingAs(User::factory()->create(['role' => $role, 'status' => $status]));
    $this->get(route('admin.applications.index'))->assertForbidden();
    $this->get(route('admin.applications.show', $application->id))->assertForbidden();
    Livewire::test(Applications::class)->assertForbidden();
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->assertForbidden();
})->with([UserRole::Staff, UserRole::Admin])->with([UserStatus::Inactive, UserStatus::Locked]);

test('candidates cannot mount review components or see review navigation', function () {
    $application = Application::factory()->create();
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(Applications::class)->assertForbidden();
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->assertForbidden();
    $this->get(route('profile.edit'))->assertDontSee(route('admin.applications.index'));
});

test('review identifiers and confirmation context cannot be hydrated with replacements', function (string $field, mixed $value) {
    $application = reviewApplication();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id]);
    expect(fn () => $page->set($field, $value))->toThrow(CannotUpdateLockedPropertyException::class);
    $this->assertDatabaseCount('activity_logs', 0);
})->with([['applicationId', 99999], ['expected', 'forged'], ['operation', 'verify'], ['childId', 99999]]);

test('account changes after confirmation deny review mutation', function (string $field, mixed $value) {
    $application = reviewApplication(ApplicationStatus::Submitted);
    $reviewer = User::factory()->create(['role' => UserRole::Staff]);
    $this->actingAs($reviewer);
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', 'start');
    User::query()->whereKey($reviewer->id)->update([$field => $value]);
    $page->call('perform')->assertForbidden();
    expect($application->fresh()->status)->toBe(ApplicationStatus::Submitted);
    $this->assertDatabaseCount('activity_logs', 0);
})->with([['role', 'candidate'], ['status', 'inactive'], ['status', 'locked'], ['email_verified_at', null]]);

test('real Livewire review requests recheck account permissions', function (string $field, mixed $value) {
    $reviewer = User::factory()->create(['role' => UserRole::Staff]);
    $this->post(route('login.store'), ['email' => $reviewer->email, 'password' => 'password']);
    $response = $this->get(route('admin.applications.index'))->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    User::query()->whereKey($reviewer->id)->update([$field => $value]);
    Auth::forgetGuards();
    Livewire::flushState();
    $response = $this->postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => html_entity_decode($matches[1], ENT_QUOTES), 'updates' => [],
        'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
    ]]], ['X-Livewire' => 'true']);
    expect(in_array($response->status(), [403, 302], true))->toBeTrue();
    $this->assertDatabaseCount('activity_logs', 0);
})->with([['role', 'candidate'], ['status', 'locked'], ['email_verified_at', null]]);

test('review mutations reject extra workflow fields rather than using mass assignment', function (string $operation, string $field) {
    $application = reviewApplication($operation === 'start' ? ApplicationStatus::Submitted : ApplicationStatus::UnderReview);
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])->call('confirm', $operation);
    if ($operation === 'revision') {
        $page->set('form.revision_reason', 'Revise');
    }
    $page->set('form.'.$field, 'injected')->call('perform')->assertHasErrors('form');
    $this->assertDatabaseCount('activity_logs', 0);
})->with(['start', 'revision', 'verify'])->with(['status', 'reviewed_by', 'reviewed_at', 'submitted_at', 'candidate_profile_id', 'application_id', 'unexpected']);

test('nonexistent review application returns not found', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $this->get(route('admin.applications.show', 999999))->assertNotFound();
});
