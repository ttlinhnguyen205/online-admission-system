<?php

use App\Actions\AdmissionStatistics;
use App\Actions\BuildAdmissionCounselingContext;
use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\Dashboard as ReviewDashboard;
use App\Livewire\Candidate\Dashboard as CandidateDashboard;
use App\Models\AdmissionResult;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\User;
use App\Notifications\ApplicationRevisionRequested;
use App\Support\AdmissionReportFilters;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('admin dropdown filters explicitly sync and send a request on selection change', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $component = Livewire::test(ReviewDashboard::class);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
    foreach (['yearFilter', 'roundFilter', 'statusFilter'] as $property) {
        $select = (new DOMXPath($document))->query('//select[@name="'.$property.'"]')->item(0);
        expect($select)->toBeInstanceOf(DOMElement::class);
        expect($select->getAttribute('wire:model.change.live'))->toBe($property);
    }
});

test('admin year selection filters real years rounds statistics and resets incompatible round state', function () {
    $round = AdmissionRound::factory()->create(['year' => 2026, 'name' => 'ROUND-YEAR-2026']);
    $nextRound = AdmissionRound::factory()->create(['year' => 2027, 'name' => 'ROUND-YEAR-2027']);
    $first = Application::factory()->create(['admission_round_id' => $round->id, 'status' => 'submitted']);
    $second = Application::factory()->create(['admission_round_id' => $nextRound->id, 'status' => 'needs_revision']);
    foreach (range(1, 3) as $priority) {
        AdmissionWish::factory()->create(['application_id' => $first->id, 'priority' => $priority]);
    }
    AdmissionWish::factory()->create(['application_id' => $second->id]);
    $this->actingAs(User::factory()->create(['role' => 'admin']));

    $component = Livewire::test(ReviewDashboard::class)
        ->assertViewHas('years', fn ($years) => $years->all() === [2027, 2026])
        ->set('roundFilter', (string) $round->id)->call('setPage', 2)->set('yearFilter', '2027')
        ->assertSet('roundFilter', '')->assertViewHas('rounds', fn ($rows) => $rows->modelKeys() === [$nextRound->id])
        ->assertViewHas('records', fn ($rows) => $rows->currentPage() === 1 && $rows->modelKeys() === [$second->id])
        ->assertViewHas('summary', fn ($data) => $data['metrics']['Hồ sơ đã nộp'] === 1 && $data['metrics']['Nguyện vọng'] === 1)
        ->assertViewHas('pending', fn ($rows) => $rows->isEmpty())->assertDontSee('ROUND-YEAR-2026');
    foreach (['xlsx', 'pdf'] as $format) {
        $component->assertSee(e(route('admin.reports.download', ['format' => $format, 'roundFilter' => '', 'statusFilter' => '', 'search' => '', 'yearFilter' => '2027'])), false);
    }
    $component->set('roundFilter', (string) $round->id)->assertHasErrors('filters.roundFilter')
        ->assertViewHas('summary', null)->call('clearFilters')->assertSet('yearFilter', '')
        ->assertViewHas('summary', fn ($data) => $data['metrics']['Hồ sơ đã nộp'] === 2 && $data['metrics']['Nguyện vọng'] === 4)
        ->set('yearFilter', '2026')->assertViewHas('records', fn ($rows) => $rows->modelKeys() === [$first->id])
        ->assertViewHas('pending', fn ($rows) => $rows->modelKeys() === [$first->id]);
    foreach ($component->viewData('summary')['charts'] as $rows) {
        expect(array_sum(array_column($rows, 'count')))->toBe(3);
    }
    $component->set('yearFilter', '1999')->assertHasErrors('filters.yearFilter')->assertViewHas('summary', null);
});

test('staff dashboard does not expose or accept the new admin year filter', function () {
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    Livewire::test(ReviewDashboard::class)->assertDontSee('Năm tuyển sinh')->set('yearFilter', '2026')->assertForbidden();
});

test('candidate wish method display requires current program and method publication approvals', function () {
    $application = Application::factory()->create();
    $application->admissionRound->update(['status' => 'open']);
    $wish = AdmissionWish::factory()->create(['application_id' => $application->id]);
    $program = $wish->admissionProgram;
    $method = $program->admissionMethod;
    $method->update(['name' => 'AUTHORIZED-METHOD']);
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(CandidateDashboard::class)->assertDontSee('AUTHORIZED-METHOD');
    foreach ([['program', $program], ['method', $method]] as [$type, $model]) {
        config(['admission_chatbot.publications.'.$type.':'.$model->id.':'.$type => [
            'approved' => true, 'fingerprint' => BuildAdmissionCounselingContext::fingerprint($model, $type),
        ]]);
    }
    Livewire::test(CandidateDashboard::class)->assertSee('AUTHORIZED-METHOD');
    $method->update(['description' => 'Changed after approval']);
    Livewire::test(CandidateDashboard::class)->assertDontSee('AUTHORIZED-METHOD');
});

test('candidate revision actions stay visible independently of the application page', function () {
    $profile = CandidateProfile::factory()->create();
    $revisions = Application::factory()->count(6)->create([
        'candidate_profile_id' => $profile->id, 'status' => ApplicationStatus::NeedsRevision,
        'revision_reason' => 'Bổ sung giấy tờ của tôi',
    ]);
    Application::factory()->count(5)->create(['candidate_profile_id' => $profile->id]);
    $other = Application::factory()->create(['status' => ApplicationStatus::NeedsRevision, 'revision_reason' => 'PRIVATE-REVISION']);
    $revisions->first()->admissionRound->update(['status' => 'closed', 'end_date' => now()->subDay()]);
    $this->actingAs($profile->user);

    $component = Livewire::test(CandidateDashboard::class)
        ->assertSee('Hồ sơ cần bổ sung (6)')->assertSee('Bổ sung giấy tờ của tôi')
        ->assertSee($revisions->first()->application_code)->assertDontSee($other->application_code)
        ->assertDontSee('PRIVATE-REVISION')->assertSee(route('candidate.applications.show', $revisions->first()->id), false)
        ->assertViewHas('revisionApplications', fn ($rows) => $rows->total() === 6 && $rows->modelKeys() === $revisions->take(5)->modelKeys());
    $component->call('setPage', 2, 'revisionsPage')
        ->assertViewHas('revisionApplications', fn ($rows) => $rows->modelKeys() === [$revisions->last()->id])
        ->assertViewHas('applications', fn ($rows) => $rows->currentPage() === 1);
    expect($revisions->first()->fresh()->status)->toBe(ApplicationStatus::NeedsRevision);
});

test('candidate profile summary does not treat a stale complete status as complete information', function () {
    $profile = CandidateProfile::factory()->create(['profile_status' => 'complete']);
    $this->actingAs($profile->user);
    Livewire::test(CandidateDashboard::class)->assertViewHas('profileComplete', false)
        ->assertSee('Hồ sơ chưa đáp ứng đủ thông tin bắt buộc.')
        ->assertSee(route('candidate.profile.edit'), false);
});

test('candidate dashboard shows four primary cards and five recent notifications without marking them read', function () {
    $this->freezeTime();
    $candidate = User::factory()->create();
    foreach (range(1, 6) as $index) {
        $candidate->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => ApplicationRevisionRequested::class,
            'data' => ['reason' => 'Thông báo '.$index], 'created_at' => now()->subMinutes($index),
        ]);
    }
    $this->actingAs($candidate);
    $component = Livewire::test(CandidateDashboard::class)
        ->assertViewHas('notifications', fn ($rows) => $rows->count() === 5)
        ->assertSee(now()->subMinute()->format('d/m/Y H:i'))
        ->assertSee(route('candidate.notifications.index'), false)->assertSee('Chưa đọc');
    expect(substr_count($component->html(), 'wire:key="candidate-metric-'))->toBe(4)
        ->and($candidate->unreadNotifications()->count())->toBe(6);
});

test('candidate dashboard handles a missing profile without creating records', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('dashboard'))->assertOk()->assertSee('Tổng quan hồ sơ của tôi')->assertSee('Bạn chưa có hồ sơ đăng ký.');
    $this->assertDatabaseCount('candidate_profiles', 0);
    Livewire::test(CandidateDashboard::class)->assertViewHas('metrics', fn ($metrics) => array_sum($metrics) === 0);
});

test('candidate dashboard scopes applications wishes results and notifications to its owner', function () {
    $this->freezeTime();
    $profile = CandidateProfile::factory()->create();
    $own = Application::factory()->create(['candidate_profile_id' => $profile->id, 'status' => 'needs_revision', 'revision_reason' => '<script>secret</script>']);
    $own->admissionRound->update(['status' => 'published']);
    $wish = AdmissionWish::factory()->create(['application_id' => $own->id]);
    $result = AdmissionResult::factory()->create(['admission_wish_id' => $wish->id, 'decision' => 'admitted', 'published_at' => now()]);
    $other = Application::factory()->create(['status' => 'completed']);
    $other->admissionRound->update(['status' => 'published']);
    $otherWish = AdmissionWish::factory()->create(['application_id' => $other->id]);
    AdmissionResult::factory()->create(['admission_wish_id' => $otherWish->id, 'published_at' => now()]);
    foreach ([$profile->user, $other->candidateProfile->user] as $user) {
        $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => ApplicationRevisionRequested::class,
            'data' => ['application_id' => $user->id === $profile->user_id ? $own->id : $other->id, 'reason' => $user->id === $profile->user_id ? 'Bổ sung bằng tốt nghiệp' : 'PRIVATE-NOTIFICATION']]);
    }
    $this->actingAs($profile->user);
    Livewire::test(CandidateDashboard::class)->assertSee($own->application_code)->assertDontSee($other->application_code)
        ->assertSee($wish->admissionProgram->major->name)->assertDontSee($otherWish->admissionProgram->major->name)
        ->assertSee('Bổ sung bằng tốt nghiệp')->assertDontSee('PRIVATE-NOTIFICATION')->assertDontSee('<script>secret</script>', false)
        ->assertViewHas('results', fn ($results) => $results->modelKeys() === [$result->id])
        ->assertViewHas('metrics', fn ($metrics) => $metrics['Hồ sơ của tôi'] === 1 && $metrics['Nguyện vọng'] === 1 && $metrics['Thông báo chưa đọc'] === 1);
    expect($profile->user->unreadNotifications()->count())->toBe(1);
});

test('candidate dashboard hides results until authorized publication', function (string $visibility) {
    $this->freezeTime();
    $application = Application::factory()->create(['status' => 'completed']);
    $application->admissionRound->update(['status' => $visibility === 'closed' ? 'closed' : 'published']);
    $wish = AdmissionWish::factory()->create(['application_id' => $application->id]);
    AdmissionResult::factory()->create(['admission_wish_id' => $wish->id, 'published_at' => match ($visibility) {
        'unpublished' => null, 'future' => now()->addDay(), default => now(),
    }]);
    if ($visibility === 'cross round') {
        $wish->admissionProgram->update(['admission_round_id' => AdmissionRound::factory()->create()->id]);
    }
    $this->actingAs($application->candidateProfile->user);
    Livewire::test(CandidateDashboard::class)->assertViewHas('results', fn ($results) => $results->isEmpty())
        ->assertViewHas('metrics', fn ($metrics) => $metrics['Kết quả đã công bố'] === 0);
})->with(['unpublished', 'future', 'closed', 'cross round']);

test('review dashboard counts actual workflow statuses without multiplying applications', function (UserRole $role) {
    $profile = CandidateProfile::factory()->create();
    $round = AdmissionRound::factory()->create();
    foreach (ApplicationStatus::cases() as $status) {
        $application = Application::factory()->create(['status' => $status, 'candidate_profile_id' => $profile->id,
            'admission_round_id' => $status === ApplicationStatus::Submitted ? $round->id : AdmissionRound::factory()->create()->id,
            'reviewed_at' => $status === ApplicationStatus::Verified ? now() : null]);
        AdmissionWish::factory()->create(['application_id' => $application->id]);
        AdmissionWish::factory()->create(['application_id' => $application->id, 'priority' => 2]);
    }
    $this->actingAs($actor = User::factory()->create(['role' => $role]));
    $summary = app(AdmissionStatistics::class)->build($actor, new AdmissionReportFilters);
    expect($summary['metrics']['Hồ sơ đã nộp'])->toBe(7)
        ->and($summary['metrics']['Thí sinh có hồ sơ'])->toBe(1)
        ->and($summary['metrics']['Nguyện vọng'])->toBe(14)
        ->and($summary['metrics']['Đã có lần xét duyệt'])->toBe(1)
        ->and(array_column($summary['statuses'], 'count'))->toBe(array_fill(0, 7, 1));
    if ($role === UserRole::Admin) {
        expect($summary['metrics']['Bản nháp chưa nộp (thống kê riêng)'])->toBe(1);
        foreach ($summary['charts'] as $rows) {
            expect(array_sum(array_column($rows, 'count')))->toBe(14);
        }
    } else {
        expect($summary['charts'])->toBe([])->and($summary['metrics'])->not->toHaveKey('Bản nháp chưa nộp (thống kê riêng)');
    }
    $component = Livewire::test(ReviewDashboard::class)->set('roundFilter', (string) $round->id)->set('statusFilter', 'submitted');
    $component->assertViewHas('summary', fn ($data) => $data['metrics']['Hồ sơ đã nộp'] === 1 && $data['metrics']['Nguyện vọng'] === 2)
        ->assertViewHas('records', fn ($records) => $records->total() === 1)
        ->assertViewHas('pending', fn ($pending) => $pending->count() === 1)
        ->call('clearFilters')->assertSet('roundFilter', '')->assertSet('statusFilter', '');
})->with([UserRole::Staff, UserRole::Admin]);

test('dashboard search applies to cards charts and lists consistently', function (string $field) {
    $application = Application::factory()->create(['status' => 'submitted']);
    Application::factory()->create(['status' => 'submitted']);
    $search = match ($field) {
        'application' => $application->application_code,
        'candidate' => $application->candidateProfile->candidate_code,
        'name' => 'Nguyễn Văn Riêng Biệt', 'email' => 'unique-search@example.test',
    };
    if ($field === 'name' || $field === 'email') {
        $application->candidateProfile->user->update([$field => $search]);
    }
    AdmissionWish::factory()->create(['application_id' => $application->id]);
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    Livewire::test(ReviewDashboard::class)->set('search', $search)->assertViewHas('summary', fn ($summary) => $summary['metrics']['Hồ sơ đã nộp'] === 1 && $summary['metrics']['Nguyện vọng'] === 1)
        ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $application->id);
})->with(['application', 'candidate', 'name', 'email']);

test('dashboard rejects invalid filters without falling back to unrestricted data', function (string $property, string $value) {
    Application::factory()->create(['status' => 'submitted']);
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    Livewire::test(ReviewDashboard::class)->set($property, $value)->assertHasErrors(['filters.'.$property])
        ->assertViewHas('summary', null)->assertViewHas('records', null)->assertDontSee('Xuất Excel');
})->with([['roundFilter', '999999'], ['roundFilter', 'invalid'], ['statusFilter', 'draft'], ['statusFilter', 'approved'], ['search', str_repeat('a', 101)]]);

test('review dashboard handles an empty dataset and accessible chart labels', function (UserRole $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $this->get(route('dashboard'))->assertOk()->assertSee('Hồ sơ theo trạng thái')->assertSee('Chưa có hồ sơ phù hợp với bộ lọc.');
    Livewire::test(ReviewDashboard::class)->assertViewHas('summary', fn ($summary) => array_sum($summary['metrics']) === 0);
    expect(view('components.admission-chart', ['title' => 'Biểu đồ kiểm tra', 'rows' => [['label' => 'Đã nộp', 'count' => 3]]])->render())
        ->toContain('<figcaption', 'scope="row"', 'aria-hidden="true"', 'Đã nộp', '>3<');
})->with([UserRole::Staff, UserRole::Admin]);

test('dashboards enforce role and refreshed account authorization', function () {
    $candidate = User::factory()->create();
    $this->actingAs($candidate);
    Livewire::test(ReviewDashboard::class)->assertForbidden();
    $staff = User::factory()->create(['role' => 'staff']);
    $this->actingAs($staff);
    Livewire::test(CandidateDashboard::class)->assertForbidden();
    $dashboard = Livewire::test(ReviewDashboard::class);
    User::whereKey($staff->id)->update(['role' => 'candidate']);
    $dashboard->call('clearFilters')->assertForbidden();
});

test('dashboard is inaccessible to inactive locked or unverified users', function (UserRole $role, string $change) {
    $user = User::factory()->create(['role' => $role]);
    User::whereKey($user->id)->update($change === 'unverified' ? ['email_verified_at' => null] : ['status' => $change]);
    $response = $this->actingAs($user->fresh())->get(route('dashboard'));
    if ($change === 'unverified') {
        $response->assertRedirect(route('verification.notice'));
    } else {
        $response->assertForbidden();
    }
    Livewire::test($role === UserRole::Candidate ? CandidateDashboard::class : ReviewDashboard::class)->assertForbidden();
})->with(UserRole::cases())->with(['inactive', 'locked', 'unverified']);

test('staff statistics include only published results while admin sees internal results', function () {
    $this->freezeTime();
    foreach (['published', 'closed', 'future', 'unpublished'] as $visibility) {
        $application = Application::factory()->create(['status' => 'completed']);
        $application->admissionRound->update(['status' => $visibility === 'closed' ? 'closed' : 'published']);
        $wish = AdmissionWish::factory()->create(['application_id' => $application->id]);
        AdmissionResult::factory()->create(['admission_wish_id' => $wish->id, 'decision' => 'admitted',
            'published_at' => match ($visibility) {
                'future' => now()->addDay(), 'unpublished' => null, default => now()
            },
            'confirmed_at' => $visibility === 'published' ? now() : null]);
    }
    $service = app(AdmissionStatistics::class);
    $staff = $service->build(User::factory()->create(['role' => 'staff']), new AdmissionReportFilters);
    $admin = $service->build(User::factory()->create(['role' => 'admin']), new AdmissionReportFilters);
    expect($staff['metrics']['Kết quả xét tuyển'])->toBe(1)->and($admin['metrics']['Kết quả xét tuyển'])->toBe(4)
        ->and($admin['metrics']['Kết quả đã công bố'])->toBe(1)->and($admin['metrics']['Đã xác nhận nhập học'])->toBe(1);
});

test('admin dashboard highlights four KPIs and retains all other metrics in collapsed details', function () {
    Application::factory()->count(2)->create(['status' => 'submitted']);
    Application::factory()->create(['status' => 'under_review']);
    Application::factory()->create(['status' => 'needs_revision']);
    Application::factory()->create(['status' => 'verified']);
    $this->actingAs($admin = User::factory()->create(['role' => 'admin']));

    $component = Livewire::test(ReviewDashboard::class);
    $html = $component->html();
    preg_match('/aria-label="Chỉ số chính"(.*?)<details wire:key="admin-statistics-details"/s', $html, $primary);
    expect(substr_count($primary[1], '<article '))->toBe(4);
    expect($primary[1])->toContain('Hồ sơ đã nộp', 'Thí sinh có hồ sơ', 'Chờ xử lý', 'Cần bổ sung');
    preg_match('/<h2[^>]*>Chờ xử lý<\/h2>.*?<p[^>]*>([^<]+)<\/p>/s', $primary[1], $pending);
    expect(trim($pending[1]))->toBe('3');
    preg_match('/<details wire:key="admin-statistics-details"([^>]*)>(.*?)<\/details>/s', $html, $details);
    expect($details[1])->not->toContain('open');
    expect($details[2])->toContain('Thống kê chi tiết', 'Chờ bắt đầu xét duyệt', 'Đang xét duyệt');
    $summary = app(AdmissionStatistics::class)->build($admin, new AdmissionReportFilters);
    foreach (array_keys($summary['metrics']) as $label) {
        if (! in_array($label, ['Hồ sơ đã nộp', 'Thí sinh có hồ sơ', 'Cần bổ sung'], true)) {
            expect($details[2])->toContain($label);
        }
    }
});

test('admin chart shows percentages against the defined total with expandable full labels', function () {
    $fullLabel = str_repeat('Công nghệ thông tin ', 5).' (7480201) — Phương thức xét tuyển — ROUND-2026';
    $html = view('components.admin-admission-chart', [
        'title' => 'Nguyện vọng theo ngành', 'total' => 4, 'unit' => 'nguyện vọng',
        'rows' => [['label' => $fullLabel, 'count' => 3], ['label' => '<script>KHÔNG AN TOÀN</script>', 'count' => 1]],
    ])->render();

    expect($html)->toContain('Tỷ lệ trên 4 nguyện vọng theo bộ lọc', '75,0%', '25,0%', 'width: 75%', 'width: 25%',
        'scope="row"', '<details', $fullLabel, '&lt;script&gt;KHÔNG AN TOÀN&lt;/script&gt;')
        ->not->toContain('overflow-y-auto', 'max-h-96', '<script>KHÔNG AN TOÀN</script>');
});

test('admin chart handles an empty denominator without invalid percentages', function () {
    $html = view('components.admin-admission-chart', [
        'title' => 'Hồ sơ theo trạng thái', 'total' => 0, 'unit' => 'hồ sơ đã nộp',
        'rows' => [['label' => 'Đã nộp', 'count' => 0]], 'colors' => ['Đã nộp' => 'sky'],
    ])->render();

    expect($html)->toContain('Tỷ lệ trên 0 hồ sơ đã nộp', 'Chưa có dữ liệu phù hợp.', '0,0%', 'width: 0%', 'bg-sky-500')
        ->not->toContain('NaN', 'INF');
});

test('admin chart denominators and export links follow the same filters', function () {
    $target = Application::factory()->create(['status' => 'submitted', 'application_code' => 'UI-FILTER-TARGET']);
    $wish = AdmissionWish::factory()->create(['application_id' => $target->id]);
    AdmissionWish::factory()->create(['application_id' => $target->id, 'priority' => 2]);
    Application::factory()->create(['status' => 'submitted']);
    $this->actingAs(User::factory()->create(['role' => 'admin']));

    $component = Livewire::test(ReviewDashboard::class)->set('roundFilter', (string) $target->admission_round_id)
        ->set('statusFilter', 'submitted')->set('search', 'UI-FILTER-TARGET');

    $component->assertSee('Tỷ lệ trên 1 hồ sơ đã nộp')->assertSee('Tỷ lệ trên 2 nguyện vọng')->assertSee('100,0%')->assertSee('50,0%');
    foreach (['xlsx', 'pdf'] as $format) {
        $url = route('admin.reports.download', ['format' => $format, 'roundFilter' => (string) $target->admission_round_id, 'statusFilter' => 'submitted', 'search' => 'UI-FILTER-TARGET']);
        $component->assertSee(e($url), false);
    }
    $component->assertSee(e(route('admin.applications.show', $target->id)), false);
});

test('staff dashboard highlights review KPIs and keeps remaining statistics in collapsed details', function () {
    Application::factory()->count(2)->create(['status' => ApplicationStatus::Submitted]);
    Application::factory()->create(['status' => ApplicationStatus::UnderReview]);
    Application::factory()->create(['status' => ApplicationStatus::NeedsRevision]);
    Application::factory()->create(['status' => ApplicationStatus::Verified]);
    $this->actingAs($staff = User::factory()->create(['role' => UserRole::Staff]));

    $component = Livewire::test(ReviewDashboard::class);
    $html = $component->html();

    preg_match('/aria-label="Chỉ số chính"(.*?)<section aria-label="Hàng đợi xét duyệt"/s', $html, $primary);
    expect(substr_count($primary[1], '<article '))->toBe(4);
    expect($primary[1])->toContain('Hồ sơ đã nộp', 'Chờ xét duyệt', 'Cần bổ sung', 'Đã xác minh');
    preg_match('/<h2[^>]*>Chờ xét duyệt<\/h2>.*?<p[^>]*>([^<]+)<\/p>/s', $primary[1], $pending);
    expect(trim($pending[1]))->toBe('3');
    preg_match('/<details wire:key="staff-statistics-details"([^>]*)>(.*?)<\/details>/s', $html, $details);
    expect($details[1])->not->toContain('open');
    $metrics = app(AdmissionStatistics::class)->build($staff, new AdmissionReportFilters)['metrics'];
    foreach (array_keys($metrics) as $label) {
        if (! in_array($label, ['Hồ sơ đã nộp', 'Cần bổ sung', 'Đã xác minh'], true)) {
            expect($details[2])->toContain($label);
        }
    }
    $component->assertSee('Tỷ lệ trên 5 hồ sơ đã nộp')->assertSee('40,0%')->assertSee('20,0%')
        ->assertSee('Kết quả chỉ gồm các đợt đã công bố.')->assertDontSee('Kết quả gồm cả nội bộ chưa công bố.');
    expect($html)->not->toContain('max-h-96', 'overflow-y-auto');
});

test('staff priority queue shows only the five oldest eligible applications within the round filter', function () {
    $this->freezeTime();
    $round = AdmissionRound::factory()->create();
    $eligible = [];
    for ($index = 0; $index < 7; $index++) {
        $eligible[] = Application::factory()->create(['application_code' => 'QUEUE-'.$index, 'admission_round_id' => $round->id,
            'status' => $index % 2 === 0 ? ApplicationStatus::Submitted : ApplicationStatus::UnderReview,
            'submitted_at' => now()->subDays(7 - $index)]);
    }
    foreach ([ApplicationStatus::Draft, ApplicationStatus::NeedsRevision, ApplicationStatus::Verified, ApplicationStatus::Rejected, ApplicationStatus::Processing, ApplicationStatus::Completed] as $status) {
        Application::factory()->create(['application_code' => 'EXCLUDED-'.$status->value, 'admission_round_id' => $round->id, 'status' => $status, 'submitted_at' => now()->subMonth()]);
    }
    Application::factory()->create(['application_code' => 'OTHER-ROUND', 'status' => ApplicationStatus::Submitted, 'submitted_at' => now()->subMonths(2)]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));

    $component = Livewire::test(ReviewDashboard::class)->set('roundFilter', (string) $round->id);

    $component->assertViewHas('pending', fn ($pending) => $pending->modelKeys() === array_map(fn ($application) => $application->id, array_slice($eligible, 0, 5)));
    preg_match('/<section aria-label="Hàng đợi xét duyệt"(.*?)<\/section>/s', $component->html(), $queue);
    expect($queue[1])->toContain('Hồ sơ ưu tiên xử lý', 'Đã nộp', 'Đang xét duyệt', $round->name, 'Xem hồ sơ')
        ->not->toContain('QUEUE-5', 'QUEUE-6', 'EXCLUDED-', 'OTHER-ROUND');
    expect(substr_count($queue[1], '<li '))->toBe(5);
    foreach (array_slice($eligible, 0, 5) as $application) {
        expect($queue[1])->toContain($application->application_code, e(route('admin.applications.show', $application->id)));
    }
});

test('staff filters preserve table pagination chart counts and export parameters', function () {
    $round = AdmissionRound::factory()->create();
    for ($index = 1; $index <= 16; $index++) {
        Application::factory()->create(['application_code' => 'STAFF-'.$index, 'status' => ApplicationStatus::Submitted, 'admission_round_id' => $round->id]);
    }
    Application::factory()->create(['application_code' => 'OTHER-ROUND', 'status' => ApplicationStatus::Submitted]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));

    $component = Livewire::test(ReviewDashboard::class)->set('roundFilter', (string) $round->id)
        ->set('statusFilter', ApplicationStatus::Submitted->value)->set('search', 'STAFF-')->call('setPage', 2);

    $component->assertViewHas('records', fn ($records) => $records->total() === 16 && $records->currentPage() === 2)
        ->assertSee('STAFF-16')->assertDontSee('OTHER-ROUND')
        ->set('search', 'STAFF-16')->assertSet('paginators.page', 1)
        ->assertViewHas('records', fn ($records) => $records->total() === 1)
        ->assertSee('Tỷ lệ trên 1 hồ sơ đã nộp')->assertSee('100,0%');
    foreach (['xlsx', 'pdf'] as $format) {
        $url = route('admin.reports.download', ['format' => $format, 'roundFilter' => (string) $round->id, 'statusFilter' => 'submitted', 'search' => 'STAFF-16']);
        $component->assertSee(e($url), false);
    }
});
