<?php

use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\ApplicationDetails;
use App\Models\AdmissionWish;
use App\Models\CandidateDocument;
use App\Models\CandidateScore;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

require_once __DIR__.'/ReviewFixtures.php';

test('review details contain only the application documents wishes and shared profile scores', function () {
    $application = reviewApplication();
    $own = CandidateScore::factory()->for($application->candidateProfile)->create(['subject_name' => 'Own shared score']);
    CandidateScore::factory()->create(['subject_name' => 'Foreign hidden score']);
    CandidateDocument::factory()->for($application)->create(['original_name' => 'own.pdf']);
    CandidateDocument::factory()->create(['original_name' => 'foreign-hidden.pdf']);
    $first = $application->wishes()->sole();
    $first->admissionProgram->major->update(['name' => 'First preference']);
    $second = AdmissionWish::factory()->for($application)->create(['priority' => 2]);
    $second->admissionProgram->major->update(['name' => 'Second preference']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->assertSee('Own shared score')->assertSee('own.pdf')->assertDontSee('Foreign hidden score')->assertDontSee('foreign-hidden.pdf')
        ->assertSeeInOrder(['First preference', 'Second preference'])->assertSee('Điểm thuộc hồ sơ thí sinh');
});

test('review details escape reasons identity filenames and catalog content without exposing paths', function () {
    $application = reviewApplication();
    $attack = '<script>alert(123)</script>';
    $application->update(['revision_reason' => $attack]);
    $application->candidateProfile->user->update(['name' => $attack]);
    $application->candidateProfile->update(['address' => $attack]);
    $application->wishes()->sole()->admissionProgram->major->update(['name' => $attack]);
    $document = CandidateDocument::factory()->for($application)->create(['original_name' => $attack, 'rejection_reason' => $attack]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $page = Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->assertSee('&lt;script&gt;alert(123)&lt;/script&gt;', false)->assertDontSee($attack, false)
        ->assertDontSee($document->file_path)->assertDontSee($application->candidateProfile->photo_path);
    expect($page->get('expected'))->toHaveLength(64);
    expect(Gate::allows('verify', $application->candidateProfile))->toBeFalse();
});

test('draft revision and later application states have no staff mutation controls', function (ApplicationStatus $state) {
    $application = reviewApplication($state);
    CandidateScore::factory()->for($application->candidateProfile)->create();
    CandidateDocument::factory()->for($application)->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->assertSee('Read-only application')->assertDontSee("wire:click=\"confirm('start')\"", false)
        ->assertDontSee("wire:click=\"confirm('revision')\"", false)->assertDontSee("wire:click=\"confirm('verify')\"", false);
})->with([ApplicationStatus::Draft, ApplicationStatus::NeedsRevision, ApplicationStatus::Verified, ApplicationStatus::Processing, ApplicationStatus::Completed]);

test('missing former reviewer is displayed without losing application history', function () {
    $application = reviewApplication();
    $previous = User::factory()->create(['role' => UserRole::Staff]);
    $application->update(['reviewed_by' => $previous->id]);
    $previous->delete();
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])->assertSee('Not recorded / unavailable');
    expect($application->fresh()->reviewed_by)->toBeNull();
});
