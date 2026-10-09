<?php

use App\Actions\AnswerAdmissionQuestion;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Candidate\AdmissionCounseling;
use App\Models\User;
use App\Support\AdmissionCounselingFailure;
use App\Support\AdmissionCounselingHistory;
use App\Support\AdmissionCounselingProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

require_once __DIR__.'/AdmissionCounselingFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 10, 8)->setTime(12, 0));
    config([
        'admission_chatbot.enabled' => true,
        'services.gemini.key' => 'test-placeholder-not-a-real-key',
        'services.gemini.model' => 'gemini-3.5-flash-lite',
    ]);
    $this->app->instance(AdmissionCounselingProvider::class, new FakeAdmissionCounselingProvider);
});

test('counseling route is private and restricts all noncandidate roles', function () {
    $this->get(route('candidate.counseling.index'))->assertRedirect(route('login'));
    foreach ([UserRole::Admin, UserRole::Staff] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get(route('candidate.counseling.index'))->assertForbidden();
        Livewire::test(AdmissionCounseling::class)->assertForbidden();
    }
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('candidate.counseling.index'))->assertRedirect(route('verification.notice'));
});

test('candidate without a profile can use counseling without exposing credentials', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('candidate.counseling.index'))
        ->assertSee('Tư vấn tuyển sinh')->assertSee('Google Gemini')
        ->assertDontSee('test-placeholder-not-a-real-key');
    Livewire::test(AdmissionCounseling::class)
        ->set('consent', true)->set('question', 'Ngành nào đang mở?')->call('send')
        ->assertHasNoErrors()->assertSee('Hiện không có đợt tuyển sinh nào đang nhận hồ sơ');
    Http::assertNothingSent();
});

test('read only counseling renders catalog facts and preserves admission and user tables', function () {
    $offering = counselingOffering();
    $user = User::factory()->create();
    $this->actingAs($user);
    $tables = ['users', 'admission_rounds', 'majors', 'admission_methods', 'admission_programs', 'candidate_major_offerings', 'applications', 'admission_wishes', 'candidate_scores', 'candidate_documents', 'admission_results', 'activity_logs', 'notifications'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->get()->toJson();
    }

    Livewire::test(AdmissionCounseling::class)->set('consent', true)
        ->set('question', 'Ngành nào đang mở?')->call('send')
        ->assertHasNoErrors()->assertSee($offering->major->name)
        ->assertSee('không phải cam kết trúng tuyển')->assertSee(route('candidate.applications.index'));

    foreach ($tables as $table) {
        expect(DB::table($table)->get()->toJson())->toBe($before[$table]);
    }
    $history = app(AdmissionCounselingHistory::class);
    expect(Cache::get($history->key($user)))->not->toContain('Ngành nào', $offering->major->name);
    Livewire::test(AdmissionCounseling::class)->assertSee($offering->major->name)->call('clear')
        ->assertDontSee($offering->major->name);
});

test('empty overlong or unconsented questions do not reach the provider', function (string $question, bool $consent, string $error) {
    $this->actingAs(User::factory()->create());

    Livewire::test(AdmissionCounseling::class)->set('question', $question)->set('consent', $consent)
        ->call('send')->assertHasErrors($error);

    expect(app(AdmissionCounselingProvider::class)->requests)->toBeEmpty();
})->with([['   ', true, 'question'], [str_repeat('a', 2001), true, 'question'], ['Ngành nào?', false, 'consent']]);

test('private results and unsupported policies bypass the provider', function (string $question, string $expected) {
    $this->actingAs(User::factory()->create());

    Livewire::test(AdmissionCounseling::class)->set('consent', true)->set('question', $question)
        ->call('send')->assertSee($expected);

    expect(app(AdmissionCounselingProvider::class)->requests)->toBeEmpty();
})->with([
    ['Kết quả của tôi thế nào?', 'Kết quả xét tuyển'],
    ['Tôi chắc chắn trúng tuyển không?', 'Chưa có dữ liệu được phê duyệt'],
    ['Trường có học bổng không?', 'Chưa có dữ liệu được phê duyệt'],
    ['Số căn cước 012345678901', 'Chưa có dữ liệu được phê duyệt'],
]);

test('provider errors retain the question and do not add a fabricated answer', function () {
    $this->actingAs(User::factory()->create());
    $this->app->instance(AdmissionCounselingProvider::class, new FakeAdmissionCounselingProvider(function (): array {
        throw new AdmissionCounselingFailure('network');
    }));

    Livewire::test(AdmissionCounseling::class)->set('consent', true)->set('question', 'Ngành nào?')
        ->call('send')->assertSet('question', 'Ngành nào?')->assertSee('tạm thời không phản hồi')
        ->assertSee('Gửi lại');
});

test('database changes during generation discard stale facts', function () {
    $offering = counselingOffering();
    $this->actingAs(User::factory()->create());
    $this->app->instance(AdmissionCounselingProvider::class, new FakeAdmissionCounselingProvider(function (string $question, array $context) use ($offering): array {
        $offering->update(['is_selectable' => false]);

        return counselingPlan('majors', array_column(array_filter($context['facts'], fn ($fact) => $fact['kind'] === 'offering'), 'ref'));
    }));

    Livewire::test(AdmissionCounseling::class)->set('consent', true)->set('question', 'Ngành nào?')
        ->call('send')->assertSee('Thông tin tuyển sinh vừa thay đổi')->assertDontSee($offering->major->name);
});

test('account revocation during generation blocks release of the answer', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->app->instance(AdmissionCounselingProvider::class, new FakeAdmissionCounselingProvider(function () use ($user): array {
        User::query()->whereKey($user->id)->update(['status' => UserStatus::Locked]);

        return counselingPlan();
    }));

    Livewire::test(AdmissionCounseling::class)->set('consent', true)->set('question', 'Ngành nào?')
        ->call('send')->assertForbidden();
});

test('conversation history supplies prior focus but refreshes database facts', function () {
    $offering = counselingOffering();
    $user = User::factory()->create();
    $this->actingAs($user);
    $page = Livewire::test(AdmissionCounseling::class)->set('consent', true)->set('question', $offering->major->name)->call('send');
    $offering->update(['is_selectable' => false]);

    $page->set('question', 'Ngành đó còn đăng ký được không?')->call('send');

    $requests = app(AdmissionCounselingProvider::class)->requests;
    expect($requests)->toHaveCount(2);
    expect($requests[1]['history'][0]['focus'][0])->toContain($offering->major->name);
    expect(array_column($requests[1]['context']['facts'], 'kind'))->not->toContain('offering');
    expect(json_encode($requests))->not->toContain($user->email, $user->name);
});

test('history is isolated by user and session and expires', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $history = app(AdmissionCounselingHistory::class);
    $history->append($user, $history->read($user), 'Câu hỏi', ['body' => 'Trả lời', 'focus' => []]);

    expect($history->read($other)['turns'])->toBeEmpty();
    $this->travel(121)->minutes();
    expect($history->read($user)['turns'])->toBeEmpty();
    $history->append($user, $history->read($user), 'Mới', ['body' => 'Mới', 'focus' => []]);
    session()->migrate();
    expect($history->read($user)['turns'])->toBeEmpty();
});

test('stale revisions and concurrent sends cannot duplicate provider requests', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $history = app(AdmissionCounselingHistory::class);
    $revision = $history->read($user)['revision'];
    $action = app(AnswerAdmissionQuestion::class);
    $action->answer($user, 'Ngành nào?', $revision, true);

    expect(fn () => $action->answer($user, 'Ngành nào?', $revision, true))->toThrow(AdmissionCounselingFailure::class, 'stale');
    $lock = Cache::lock('admission-chat:user:'.$user->id, 45);
    $lock->get();
    try {
        expect(fn () => $action->answer($user, 'Ngành nào?', $history->read($user)['revision'], true))->toThrow(AdmissionCounselingFailure::class, 'busy');
    } finally {
        $lock->release();
    }
    expect(app(AdmissionCounselingProvider::class)->requests)->toHaveCount(1);
});

test('rate limiting happens inside the send action and disabled mode is safe', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    config(['admission_chatbot.per_minute' => 1]);
    $page = Livewire::test(AdmissionCounseling::class)->set('consent', true)->set('question', 'Ngành nào?')->call('send');

    $page->set('question', 'Đợt nào?')->call('send')->assertSee('Đã đạt giới hạn');
    expect(app(AdmissionCounselingProvider::class)->requests)->toHaveCount(1);
    config(['admission_chatbot.enabled' => false]);
    $page->call('send')->assertSee('chưa được bật');
    expect(app(AdmissionCounselingProvider::class)->requests)->toHaveCount(1);
});

test('forged livewire revisions cannot substitute another conversation', function () {
    $this->actingAs(User::factory()->create());
    $page = Livewire::test(AdmissionCounseling::class);

    expect(fn () => $page->set('revision', 'forged'))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(app(AdmissionCounselingProvider::class)->requests)->toBeEmpty();
});

test('logout clears history without changing admission records', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $history = app(AdmissionCounselingHistory::class);
    $history->append($user, $history->read($user), 'Ngành nào?', ['body' => 'Danh mục', 'focus' => []]);
    $key = $history->key($user);

    $this->withCookie(config('session.cookie'), session()->getId())
        ->post(route('logout'))->assertRedirect();

    expect(Cache::has($key))->toBeFalse();
});

test('history retains only bounded recent turns and absolute expiry is not extended', function () {
    $user = User::factory()->create();
    $history = app(AdmissionCounselingHistory::class);
    for ($index = 0; $index < 12; $index++) {
        $history->append($user, $history->read($user), 'Câu '.$index, ['body' => 'Trả lời', 'focus' => []]);
    }
    $state = $history->read($user);
    expect($state['turns'])->toHaveCount(10);
    expect($state['turns'][0]['question'])->toBe('Câu 2');
    $this->travel(23)->hours();
    $state['created'] = now()->subHours(23)->getTimestamp();
    $history->append($user, $state, 'Tiếp tục', ['body' => 'Trả lời', 'focus' => []]);
    $this->travel(61)->minutes();

    expect($history->read($user)['turns'])->toBeEmpty();
});

test('time passing beyond deadline during generation invalidates availability', function () {
    $offering = counselingOffering();
    $this->actingAs(User::factory()->create());
    $this->app->instance(AdmissionCounselingProvider::class, new FakeAdmissionCounselingProvider(function (string $question, array $context) use ($offering): array {
        test()->travelTo($offering->admissionRound->end_date->addSecond());

        return counselingPlan('availability', array_column(array_filter($context['facts'], fn ($fact) => $fact['kind'] === 'offering'), 'ref'));
    }));

    Livewire::test(AdmissionCounseling::class)->set('consent', true)->set('question', 'Ngành này còn mở không?')
        ->call('send')->assertSee('Thông tin tuyển sinh vừa thay đổi');
});

test('untrusted names and approved descriptions are escaped in conversation output', function () {
    $offering = counselingOffering();
    $offering->major->update(['name' => '<script>alert(1)</script>']);
    $this->actingAs(User::factory()->create());

    Livewire::test(AdmissionCounseling::class)->set('consent', true)->set('question', 'Ngành nào?')
        ->call('send')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

test('global limit and provider circuit breaker fail closed without extra requests', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    config(['admission_chatbot.global_per_day' => 1]);
    Livewire::test(AdmissionCounseling::class)->set('consent', true)->set('question', 'Ngành nào?')->call('send');
    $this->actingAs(User::factory()->create());
    Livewire::test(AdmissionCounseling::class)->set('consent', true)->set('question', 'Ngành nào?')
        ->call('send')->assertSee('Đã đạt giới hạn');
    expect(app(AdmissionCounselingProvider::class)->requests)->toHaveCount(1);

    config(['admission_chatbot.global_per_day' => 1000]);
    for ($index = 0; $index < 5; $index++) {
        RateLimiter::hit('admission-chat:failures', 60);
    }
    Livewire::test(AdmissionCounseling::class)->set('consent', true)->set('question', 'Ngành nào?')
        ->call('send')->assertSee('tạm thời không phản hồi');
    expect(app(AdmissionCounselingProvider::class)->requests)->toHaveCount(1);
});
