<?php

use App\Enums\ApplicationStatus;
use App\Livewire\Candidate\ApplicationDetails;
use App\Livewire\Candidate\Applications;
use App\Livewire\Candidate\Documents;
use App\Livewire\Candidate\Notifications;
use App\Livewire\Candidate\Profile;
use App\Livewire\Candidate\Results;
use App\Livewire\Candidate\Scores;
use App\Models\Application;
use App\Support\CandidateStatusLabels;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

test('candidate journey pages use Vietnamese headings and keep application documents accessible', function () {
    $application = Application::factory()->create(['status' => ApplicationStatus::Draft]);
    $this->actingAs($application->candidateProfile->user);

    Livewire::test(Profile::class)->assertSee('Hồ sơ cá nhân')->assertDontSee('Save profile');
    Livewire::test(Scores::class)->assertSee('Điểm &amp; minh chứng', false)->assertDontSee('Score type');
    Livewire::test(Applications::class)->assertSee('Đăng ký nguyện vọng')->assertSee('Bản nháp')->assertSee('Mở hồ sơ');
    Livewire::test(ApplicationDetails::class, ['application' => $application->id])
        ->assertSee('Tài liệu hồ sơ')->assertSee(route('candidate.applications.documents.index', $application->id))
        ->assertSee('Tiếp theo: Kết quả xét tuyển')->assertSee(route('candidate.results.index'))
        ->assertDontSee('All applications');
    Livewire::test(Documents::class, ['application' => $application->id])
        ->assertSee('Tài liệu hồ sơ')->assertSee('Bản nháp')->assertDontSee('Upload document');
    Livewire::test(Results::class)->assertSee('Kết quả xét tuyển')->assertDontSee('My admission results');
    Livewire::test(Notifications::class)->assertSee('Thông báo')->assertDontSee('Notifications /');
});

test('candidate status labels and generic validation messages are Vietnamese', function () {
    expect(CandidateStatusLabels::application(ApplicationStatus::Processing))->toBe('Đang xét tuyển');
    $message = Validator::make(['form' => ['exam_year' => 'abc']], [
        'form.exam_year' => ['integer'],
    ])->errors()->first('form.exam_year');
    expect($message)->toContain('năm thi')->not->toContain('field');
});
