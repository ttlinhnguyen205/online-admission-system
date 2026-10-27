<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Candidate\AdmissionCounseling;
use App\Models\CandidateProfile;
use App\Models\User;
use App\Support\AdmissionCounselingHistory;
use App\Support\AdmissionCounselingProvider;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

require_once __DIR__.'/AdmissionCounselingFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['admission_chatbot.enabled' => true, 'services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-3.5-flash-lite']);
    $this->app->instance(AdmissionCounselingProvider::class, new FakeAdmissionCounselingProvider);
});

test('verified candidates can open floating counseling on each main screen', function (string $route, string $label) {
    $user = User::factory()->create();
    CandidateProfile::factory()->for($user)->create();
    $this->actingAs($user);

    $this->get(route($route))->assertOk()->assertSee('data-counseling-widget', false)
        ->assertSee('Mở tư vấn tuyển sinh')->assertSee('Đang hỗ trợ: '.$label)
        ->assertDontSee('href="'.route('candidate.counseling.index').'"', false);
})->with([
    ['dashboard', 'Trang chủ'],
    ['candidate.profile.edit', 'Hồ sơ cá nhân'],
    ['candidate.admission-information.index', 'Thông tin tuyển sinh'],
    ['candidate.scores.index', 'Điểm và minh chứng'],
    ['candidate.applications.index', 'Đăng ký nguyện vọng'],
    ['candidate.documents.index', 'Tài liệu hồ sơ'],
    ['candidate.results.index', 'Kết quả xét tuyển'],
    ['candidate.notifications.index', 'Thông báo'],
    ['news', 'Tin tức'],
    ['profile.edit', 'Cài đặt tài khoản'],
]);

test('floating counseling is not mounted for guests staff or admins', function () {
    $this->get(route('news'))->assertDontSee('data-counseling-widget', false);
    foreach ([UserRole::Staff, UserRole::Admin] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))->get(route('news'))
            ->assertDontSee('data-counseling-widget', false);
    }
});

test('unverified or locked candidates cannot access the floating assistant', function (bool $verified, UserStatus $status) {
    $user = User::factory()->create(['email_verified_at' => $verified ? now() : null, 'status' => $status]);
    $this->actingAs($user);

    $this->get(route('news'))->assertDontSee('data-counseling-widget', false);
    Livewire::test(AdmissionCounseling::class, ['floating' => true])->assertForbidden();
})->with([[false, UserStatus::Active], [true, UserStatus::Locked]]);

test('current screen questions return the corresponding function guide without contacting AI', function (string $route, string $expected, string $destination) {
    $this->actingAs(User::factory()->create());

    Livewire::test(AdmissionCounseling::class, ['floating' => true, 'screen' => $route])
        ->set('question', 'Trang này dùng để làm gì?')->call('send')->assertHasNoErrors()
        ->assertSee($expected)->assertSee(route($destination))->assertSet('question', '');
    expect(app(AdmissionCounselingProvider::class)->requests)->toBeEmpty();
    Http::assertNothingSent();
})->with([
    ['candidate.profile.edit', 'Ảnh chân dung dùng JPG hoặc PNG', 'candidate.profile.edit'],
    ['candidate.admission-information.index', 'Với chứng chỉ, kiểm tra loại', 'candidate.admission-information.index'],
    ['candidate.scores.index', 'Ở Điểm và minh chứng', 'candidate.scores.index'],
    ['candidate.applications.show', 'Hồ sơ nháp chưa được coi là đã nộp', 'candidate.applications.index'],
    ['candidate.applications.documents.index', 'tối đa 10 MB', 'candidate.documents.index'],
    ['candidate.results.index', 'Xác nhận nhập học', 'candidate.results.index'],
    ['candidate.notifications.index', 'Đánh dấu đã đọc không tự bổ sung hồ sơ', 'candidate.notifications.index'],
]);

test('explicit function questions can refer to another screen', function (string $question, string $destination, string $expected) {
    $this->actingAs(User::factory()->create());

    Livewire::test(AdmissionCounseling::class, ['floating' => true, 'screen' => 'news'])
        ->set('question', $question)->call('send')->assertHasNoErrors()->assertSee(route($destination))->assertSee($expected);
    expect(app(AdmissionCounselingProvider::class)->requests)->toBeEmpty();
})->with([
    ['Tôi thêm nguyện vọng như thế nào?', 'candidate.applications.index', 'sắp xếp thứ tự ưu tiên'],
    ['Làm thế nào để hoàn thiện hồ sơ cá nhân?', 'candidate.profile.edit', 'lưu hồ sơ'],
    ['Tôi khai báo học bạ và chứng chỉ như thế nào?', 'candidate.admission-information.index', 'ngày hết hạn'],
    ['Làm thế nào để tải minh chứng lên hồ sơ?', 'candidate.documents.index', 'Lưu tài liệu'],
    ['Tôi xem yêu cầu bổ sung hồ sơ ở đâu?', 'candidate.notifications.index', 'Thông báo'],
]);

test('history survives changing the screen and remains isolated between candidates', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Livewire::test(AdmissionCounseling::class, ['floating' => true, 'screen' => 'candidate.profile.edit'])
        ->set('question', 'Trang này dùng để làm gì?')->call('send')->assertHasNoErrors();
    Livewire::test(AdmissionCounseling::class, ['floating' => true, 'screen' => 'candidate.results.index'])
        ->assertSee('Ảnh chân dung dùng JPG hoặc PNG')->assertSee('Đang hỗ trợ: Kết quả xét tuyển')
        ->set('question', 'Trang này dùng để làm gì?')->call('send')->assertSee('sau khi được công bố');

    $history = app(AdmissionCounselingHistory::class);
    expect($history->read($user)['turns'])->toHaveCount(2);
    $this->actingAs($other = User::factory()->create());
    Livewire::test(AdmissionCounseling::class, ['floating' => true])->assertDontSee('Ảnh chân dung dùng JPG hoặc PNG');
    expect($history->read($other)['turns'])->toBeEmpty();
});

test('screen context is locked and unknown screens fall back to general guidance', function () {
    $this->actingAs(User::factory()->create());
    $page = Livewire::test(AdmissionCounseling::class, ['floating' => true, 'screen' => 'admin.applications.show'])
        ->assertSet('screen', 'overview')->set('question', 'Trang này dùng để làm gì?')->call('send')->assertHasNoErrors();

    expect(fn () => $page->set('screen', 'admin'))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('function guidance remains available when the AI configuration is missing', function () {
    config(['services.gemini.key' => null, 'services.gemini.model' => null]);
    $this->actingAs(User::factory()->create());

    Livewire::test(AdmissionCounseling::class, ['floating' => true, 'screen' => 'candidate.profile.edit'])
        ->assertSee('Bạn vẫn có thể hỏi cách sử dụng các chức năng')
        ->set('question', 'Trang này dùng để làm gì?')->call('send')->assertHasNoErrors()->assertSee('Ảnh chân dung');
    expect(app(AdmissionCounselingProvider::class)->requests)->toBeEmpty();
});

test('a stale widget refreshes its revision and can retry after a conversation on another screen', function () {
    $this->actingAs($user = User::factory()->create());
    $first = Livewire::test(AdmissionCounseling::class, ['floating' => true, 'screen' => 'candidate.profile.edit']);
    $second = Livewire::test(AdmissionCounseling::class, ['floating' => true, 'screen' => 'candidate.results.index']);
    $first->set('question', 'Trang này dùng để làm gì?')->call('send')->assertHasNoErrors();

    $second->set('question', 'Trang này dùng để làm gì?')->call('send')
        ->assertSet('question', 'Trang này dùng để làm gì?')->assertSet('revision', $first->get('revision'))
        ->assertSee('Ảnh chân dung');
    $second->call('send')->assertHasNoErrors()->assertSet('question', '');
    expect(app(AdmissionCounselingHistory::class)->read($user)['turns'])->toHaveCount(2);
    expect(app(AdmissionCounselingProvider::class)->requests)->toBeEmpty();
});

test('function questions containing private identifiers are redacted and never sent to AI', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test(AdmissionCounseling::class, ['floating' => true, 'screen' => 'candidate.profile.edit'])
        ->set('question', 'Hướng dẫn tôi dùng email private@example.test ở trang này')->call('send')
        ->assertSee('Chưa có dữ liệu được phê duyệt')->assertDontSee('private@example.test');
    expect(json_encode(app(AdmissionCounselingHistory::class)->read($user)['turns']))->not->toContain('private@example.test');
    expect(app(AdmissionCounselingProvider::class)->requests)->toBeEmpty();
});

test('admission facts still use the catalog provider when the question mentions the current screen', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(AdmissionCounseling::class, ['floating' => true, 'screen' => 'candidate.applications.index'])
        ->set('question', 'Học phí các ngành ở trang này là bao nhiêu?')->call('send')->assertHasNoErrors();

    $requests = app(AdmissionCounselingProvider::class)->requests;
    expect($requests)->toHaveCount(1);
    expect($requests[0]['question'])->toBe('Học phí các ngành ở trang này là bao nhiêu?');
});

test('the old full page counseling does not mount a second floating conversation', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('candidate.counseling.index'))->assertSee('Câu hỏi của bạn')
        ->assertDontSee('data-counseling-widget', false);
});

test('a real floating chat submission preserves its root element and hydration identity', function () {
    $this->actingAs(User::factory()->create());
    $this->withSession([])->withCookie(config('session.cookie'), session()->getId())->withCredentials();
    $page = $this->get(route('news'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$page->getContent());
    $xpath = new DOMXPath($document);
    $widget = $xpath->query('//*[@data-counseling-widget]')->item(0);
    $root = $xpath->query('ancestor-or-self::*[@*[name()="wire:id"]][1]', $widget)->item(0);
    expect($root)->not->toBeNull();
    $snapshot = $root->getAttribute('wire:snapshot');
    expect($snapshot)->not->toBeEmpty();

    $response = $this->postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => $snapshot,
        'updates' => ['question' => 'Trang này dùng để làm gì?'],
        'calls' => [['path' => '', 'method' => 'send', 'params' => []]],
    ]]], ['X-Livewire' => 'true'])->assertOk();
    expect(json_decode($response->json('components.0.snapshot'), true)['data']['failure'])->toBeNull();
    $html = $response->json('components.0.effects.html');
    $updated = new DOMDocument;
    @$updated->loadHTML('<?xml encoding="UTF-8">'.$html);
    $updatedXpath = new DOMXPath($updated);
    $updatedWidget = $updatedXpath->query('//*[@data-counseling-widget]')->item(0);
    $updatedRoot = $updatedXpath->query('ancestor-or-self::*[@*[name()="wire:id"]][1]', $updatedWidget)->item(0);

    expect($updatedRoot)->not->toBeNull();
    expect($updatedRoot->tagName)->toBe($root->tagName);
    expect($updatedRoot->getAttribute('wire:id'))->toBe($root->getAttribute('wire:id'));
    expect(json_decode($response->json('components.0.snapshot'), true)['memo']['id'])->toBe($root->getAttribute('wire:id'));
    expect($updatedXpath->query('.//*[@aria-controls="floating-counseling-panel"]', $updatedRoot)->length)->toBe(1);

    $next = $this->postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => $response->json('components.0.snapshot'),
        'updates' => ['question' => 'Tôi xem yêu cầu bổ sung hồ sơ ở đâu?'],
        'calls' => [['path' => '', 'method' => 'send', 'params' => []]],
    ]]], ['X-Livewire' => 'true'])->assertOk();
    expect($next->json('components.0.effects.html'))->toContain('Đánh dấu đã đọc không tự bổ sung hồ sơ');
    expect(json_decode($next->json('components.0.snapshot'), true)['data']['failure'])->toBeNull();
});
