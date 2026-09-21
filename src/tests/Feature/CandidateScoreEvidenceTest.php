<?php

use App\Actions\CandidateFiles;
use App\Enums\UserRole;
use App\Models\CandidateScore;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('private score evidence is available to its owner and authorized reviewers', function (string $actor) {
    Storage::fake(CandidateFiles::DISK);
    $score = CandidateScore::factory()->create(['evidence_path' => 'candidate-scores/proof.png']);
    $bytes = file_get_contents(base_path('tests/Fixtures/small.png'));
    Storage::disk(CandidateFiles::DISK)->put($score->evidence_path, $bytes);
    $user = $actor === 'candidate' ? $score->candidateProfile->user : User::factory()->create(['role' => UserRole::from($actor)]);

    $response = $this->actingAs($user)->get(route('admission.scores.evidence', $score->id))
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    expect($response->streamedContent())->toBe($bytes);
})->with(['candidate', 'staff', 'admin']);

test('foreign candidates and missing or unsafe evidence cannot access private score images', function () {
    Storage::fake(CandidateFiles::DISK);
    $score = CandidateScore::factory()->create(['evidence_path' => 'candidate-scores/proof.png']);
    Storage::disk(CandidateFiles::DISK)->put($score->evidence_path, file_get_contents(base_path('tests/Fixtures/small.png')));
    $this->actingAs(User::factory()->create())->get(route('admission.scores.evidence', $score->id))->assertNotFound();

    $this->actingAs($score->candidateProfile->user);
    $score->update(['evidence_path' => 'candidate-scores/../secret.png']);
    $this->get(route('admission.scores.evidence', $score->id))->assertNotFound();
    $score->update(['evidence_path' => null]);
    $this->get(route('admission.scores.evidence', $score->id))->assertNotFound();
});

test('score evidence requires authentication and verified active accounts', function () {
    $score = CandidateScore::factory()->create();
    $url = route('admission.scores.evidence', $score->id);
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get($url)->assertRedirect(route('verification.notice'));
});
