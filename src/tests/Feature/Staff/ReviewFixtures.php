<?php

use App\Actions\AdmissionReviewSnapshot;
use App\Actions\CandidateFiles;
use App\Enums\ApplicationStatus;
use App\Enums\ProfileStatus;
use App\Models\AdmissionWish;
use App\Models\Application;
use Illuminate\Support\Facades\Storage;

function reviewApplication(ApplicationStatus $status = ApplicationStatus::UnderReview): Application
{
    Storage::fake(CandidateFiles::DISK);
    $application = Application::factory()->create(['status' => $status, 'submitted_at' => now()->subHour()]);
    $profile = $application->candidateProfile;
    $profile->update([
        'profile_status' => ProfileStatus::Complete, 'date_of_birth' => '2008-01-02', 'gender' => 'Female',
        'citizen_id' => fake()->unique()->numerify('############'), 'phone' => '0901234567', 'address' => 'Hanoi',
        'province_code' => '01', 'high_school_name' => 'Review school', 'graduation_year' => 2026,
        'photo_path' => 'candidate-photos/'.$profile->id.'/portrait.png',
    ]);
    Storage::disk(CandidateFiles::DISK)->put($profile->photo_path, file_get_contents(base_path('tests/Fixtures/portrait.png')));
    AdmissionWish::factory()->for($application)->create();

    return $application->fresh();
}

function reviewToken(Application $application): string
{
    $snapshots = new AdmissionReviewSnapshot;

    return $snapshots->fingerprint($snapshots->load($application->id));
}
