<?php

use App\Actions\CandidateFiles;
use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Candidate\Documents;
use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

test('private downloads allow owner and reviewers and never expose a public storage URL', function (string $actor) {
    Storage::fake(CandidateFiles::DISK);
    $document = CandidateDocument::factory()->create(['original_name' => "..\\secret\r\n.pdf"]);
    $document->application->update(['status' => ApplicationStatus::Completed]);
    Storage::disk(CandidateFiles::DISK)->put($document->file_path, '%PDF-1.4 private contents');
    $user = $actor === 'owner' ? $document->application->candidateProfile->user : User::factory()->create(['role' => UserRole::from($actor)]);
    $response = $this->actingAs($user)->get(route('admission.documents.download', $document->id))
        ->assertOk()->assertDownload('secret.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    expect($response->streamedContent())->toBe('%PDF-1.4 private contents');
    if ($actor === 'owner') {
        $this->get(route('candidate.applications.documents.index', $document->application_id))
            ->assertDontSee('/storage/candidate-documents')->assertDontSee($document->file_path);
    }
})->with(['owner', 'staff', 'admin']);

test('foreign private files and missing records return not found', function () {
    Storage::fake(CandidateFiles::DISK);
    $document = CandidateDocument::factory()->create();
    $profile = $document->application->candidateProfile;
    $profile->update(['photo_path' => 'candidate-photos/portrait.png']);
    Storage::disk(CandidateFiles::DISK)->put($document->file_path, 'secret');
    Storage::disk(CandidateFiles::DISK)->put($profile->photo_path, file_get_contents(base_path('tests/Fixtures/portrait.png')));
    $this->actingAs(User::factory()->create());
    $this->get(route('admission.documents.download', $document->id))->assertNotFound()->assertDontSee('secret');
    $this->get(route('admission.profiles.photo', $profile->id))->assertNotFound();
    $this->get(route('admission.documents.download', 999999))->assertNotFound();
    $this->actingAs($profile->user);
    Storage::disk(CandidateFiles::DISK)->delete($document->file_path);
    $this->get(route('admission.documents.download', $document->id))->assertNotFound();
});

test('photos are controlled inline images accessible to owners and permitted reviewers', function (string $actor) {
    Storage::fake(CandidateFiles::DISK);
    $profile = CandidateProfile::factory()->create(['photo_path' => 'candidate-photos/portrait.png']);
    Storage::disk(CandidateFiles::DISK)->put($profile->photo_path, file_get_contents(base_path('tests/Fixtures/portrait.png')));
    $user = $actor === 'owner' ? $profile->user : User::factory()->create(['role' => UserRole::from($actor)]);
    $response = $this->actingAs($user)->get(route('admission.profiles.photo', $profile->id))
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline;');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    expect($response->streamedContent())->toBe(file_get_contents(base_path('tests/Fixtures/portrait.png')));
})->with(['owner', 'staff', 'admin']);

test('private endpoints reject inactive or locked users of every role', function (UserRole $role, UserStatus $status) {
    $user = User::factory()->create(['role' => $role, 'status' => $status]);
    $profile = CandidateProfile::factory()->for($user)->create();
    $document = CandidateDocument::factory()->for(Application::factory()->for($profile))->create();
    $this->actingAs($user)->get(route('admission.documents.download', $document->id))->assertForbidden();
    $this->get(route('admission.profiles.photo', $profile->id))->assertForbidden();
})->with(UserRole::cases())->with([UserStatus::Inactive, UserStatus::Locked]);

test('private endpoints require authentication and email verification', function (string $route) {
    $this->get(route($route, 1))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get(route($route, 1))->assertRedirect(route('verification.notice'));
})->with(['admission.documents.download', 'admission.profiles.photo']);

test('stored paths cannot traverse private storage and photo endpoints reject nonimages', function () {
    Storage::fake(CandidateFiles::DISK);
    $document = CandidateDocument::factory()->create(['file_path' => 'candidate-documents/../secret.txt']);
    $profile = $document->application->candidateProfile;
    $profile->update(['photo_path' => 'candidate-photos/fake.png']);
    Storage::disk(CandidateFiles::DISK)->put('secret.txt', 'never expose this');
    Storage::disk(CandidateFiles::DISK)->put($profile->photo_path, '<script>bad</script>');
    $this->actingAs($profile->user)->get(route('admission.documents.download', $document->id))->assertNotFound();
    $this->get(route('admission.profiles.photo', $profile->id))->assertNotFound();
});

test('document client names are bounded escaped metadata and never storage paths', function () {
    Storage::fake(CandidateFiles::DISK);
    $application = Application::factory()->create();
    $this->actingAs($application->candidateProfile->user);
    $name = '..\\<script>alert(1)</script>'.str_repeat('x', 260).'.pdf';
    $file = UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF");
    Livewire::test(Documents::class, ['application' => $application->id])->call('create')->set('form.document_type', 'transcript')
        ->set('file', $file)->call('save')->assertHasNoErrors();
    $document = $application->documents()->sole();
    expect(mb_strlen($document->original_name))->toBeLessThanOrEqual(255);
    expect($document->file_path)->not->toContain('script')->not->toContain('..');
    $this->get(route('candidate.applications.documents.index', $application->id))->assertDontSee('<script>alert(1)</script>', false);
    expect(CandidateFiles::displayName("..\\name\r\n.pdf"))->toBe('name.pdf');
});

test('temporary upload endpoint requires auth active account and a signed request', function () {
    $url = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), [], absolute: false);
    $this->post($url, ['files' => []])->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['status' => UserStatus::Locked]))->post($url, ['files' => []])->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route('livewire.upload-file'), ['files' => []])->assertUnauthorized();
    $this->post($url, ['files' => [UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1;')]])->assertSessionHasErrors('files.0');
});
