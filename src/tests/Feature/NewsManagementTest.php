<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Admin\NewsArticles;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

test('only active verified administrators can manage news', function (UserRole $role, UserStatus $status, bool $verified, bool $allowed) {
    $user = User::factory()->create(['role' => $role, 'status' => $status, 'email_verified_at' => $verified ? now() : null]);
    $article = NewsArticle::factory()->create();

    foreach (['viewAny', 'create'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, NewsArticle::class))->toBe($allowed);
    }
    foreach (['update', 'delete'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, $article))->toBe($allowed);
    }
})->with([
    'admin' => [UserRole::Admin, UserStatus::Active, true, true],
    'staff' => [UserRole::Staff, UserStatus::Active, true, false],
    'candidate' => [UserRole::Candidate, UserStatus::Active, true, false],
    'locked admin' => [UserRole::Admin, UserStatus::Locked, true, false],
    'inactive admin' => [UserRole::Admin, UserStatus::Inactive, true, false],
    'unverified admin' => [UserRole::Admin, UserStatus::Active, false, false],
]);

test('news administration requires authentication and administrator permissions', function () {
    $this->get(route('admin.news.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));
    $this->get(route('admin.news.index'))->assertForbidden();
    Livewire::test(NewsArticles::class)->assertForbidden();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $this->get(route('admin.news.index'))->assertSeeText('Quản lý tin tức')->assertSeeText('Tạo bài viết');
    $this->get(route('admin.home'))->assertSee(route('admin.news.index'));
});

test('administrators can save a draft then edit and publish it', function () {
    $this->freezeTime();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin);
    $page = Livewire::test(NewsArticles::class)->call('create')
        ->set('form.title', '  Tuyển sinh năm 2026  ')
        ->set('form.category', 'admissions')
        ->set('form.summary', 'Thông tin đăng ký')
        ->set('form.content', "Nội dung tuyển sinh.\nHướng dẫn đăng ký.")
        ->call('save')->assertHasNoErrors()->assertSet('showEditor', false);
    $article = NewsArticle::query()->sole();
    expect($article->title)->toBe('Tuyển sinh năm 2026');
    expect($article->status)->toBe('draft');
    expect($article->created_by)->toBe($admin->id);
    expect($article->published_at)->toBeNull();
    $this->get(route('news'))->assertDontSeeText($article->title);
    $this->get(route('news.show', $article->id))->assertNotFound();

    $page->call('edit', $article->id)->set('form.title', 'Thông báo tuyển sinh chính thức')
        ->set('form.status', 'published')->call('save')->assertHasNoErrors();
    $article->refresh();
    expect($article->status)->toBe('published');
    expect($article->published_at->timestamp)->toBe(now()->timestamp);
    $this->get(route('news'))->assertSeeText('Thông báo tuyển sinh chính thức');
    $this->get(route('news.show', $article->id))->assertSeeText('Nội dung tuyển sinh.');
});

test('publication hiding and deletion change public availability', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $article = NewsArticle::factory()->create();
    $page = Livewire::test(NewsArticles::class)->call('publish', $article->id)->assertHasNoErrors();
    expect($article->fresh()->status)->toBe('published');
    $this->get(route('news.show', $article->id))->assertOk();

    $page->call('hide', $article->id)->assertHasNoErrors();
    expect($article->fresh()->status)->toBe('hidden');
    $this->get(route('news'))->assertDontSeeText($article->title);
    $this->get(route('news.show', $article->id))->assertNotFound();

    $page->call('publish', $article->id)->call('delete', $article->id)->assertHasNoErrors();
    $this->assertModelMissing($article);
    $this->get(route('news.show', $article->id))->assertNotFound();
});

test('a new article can be published directly from its editor', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Livewire::test(NewsArticles::class)->call('create')->set('form.title', 'Tin mới xuất bản')
        ->set('form.content', 'Nội dung công khai')->set('form.status', 'published')
        ->call('save')->assertHasNoErrors();

    $article = NewsArticle::query()->sole();
    expect($article->published_at)->not->toBeNull();
    $this->get(route('news.show', $article->id))->assertSeeText('Nội dung công khai');
});

test('missing article fields report Vietnamese validation errors', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Livewire::test(NewsArticles::class)->call('create')->call('save')
        ->assertHasErrors(['form.title' => 'required', 'form.content' => 'required'])
        ->assertSee('Vui lòng nhập tiêu đề.')->assertSee('Vui lòng nhập nội dung.');
    $this->assertDatabaseCount('news_articles', 0);
});

test('news actions reject an administrator whose permissions were revoked', function (string $action) {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $article = NewsArticle::factory()->create();
    $this->actingAs($admin);
    $page = Livewire::test(NewsArticles::class)->call('edit', $article->id);
    $admin->forceFill(['status' => UserStatus::Locked])->save();

    $page->call($action, $article->id)->assertForbidden();
    expect($article->fresh()->status)->toBe('draft');
    $this->assertModelExists($article);
})->with(['save', 'edit', 'publish', 'hide', 'delete']);

test('article fields are validated without saving invalid or unexpected data', function (string $field, mixed $value, string $rule) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Livewire::test(NewsArticles::class)->call('create')
        ->set('form.title', 'Bài viết hợp lệ')->set('form.content', 'Nội dung bài viết')
        ->set('form.'.$field, $value)->call('save')->assertHasErrors(['form'.($rule === 'array' ? '' : '.'.$field) => $rule]);
    $this->assertDatabaseCount('news_articles', 0);
})->with([
    'missing title' => ['title', '  ', 'required'],
    'long title' => ['title', str_repeat('a', 256), 'max'],
    'missing content' => ['content', '', 'required'],
    'long content' => ['content', str_repeat('a', 100001), 'max'],
    'long summary' => ['summary', str_repeat('a', 1001), 'max'],
    'invalid category' => ['category', 'private', 'in'],
    'invalid status' => ['status', 'other', 'in'],
    'forged author' => ['created_by', 99, 'array'],
    'forged publication date' => ['published_at', '2020-01-01', 'array'],
]);

test('public news excludes drafts hidden posts and future publication dates', function () {
    $this->freezeTime();
    $visible = NewsArticle::factory()->published()->create(['title' => 'Tin công khai']);
    $draft = NewsArticle::factory()->create(['title' => 'Tin nháp riêng']);
    $hidden = NewsArticle::factory()->published()->create(['title' => 'Tin đã ẩn', 'status' => 'hidden']);
    $future = NewsArticle::factory()->published()->create(['title' => 'Tin chưa tới ngày', 'published_at' => now()->addDay()]);

    $response = $this->get(route('news'))->assertSeeText($visible->title);
    foreach ([$draft, $hidden, $future] as $article) {
        $response->assertDontSeeText($article->title);
        $this->get(route('news.show', $article->id))->assertNotFound();
    }
    $this->get(route('news.show', $visible->id))->assertSeeText($visible->content);
    $this->get(route('news.show', 999999))->assertNotFound();
    $this->get('/tin-tuc/not-an-id')->assertNotFound();

    $html = Blade::render('<x-admission.home />');
    expect($html)->toContain($visible->title)->not->toContain($draft->title, $hidden->title, $future->title);
});

test('news lists support categories pagination and admin title status filters', function () {
    $this->freezeTime();
    $school = NewsArticle::factory()->published()->create(['title' => 'Tin nhà trường duy nhất']);
    $admission = NewsArticle::factory()->published()->create(['category' => 'admissions', 'title' => 'Hướng dẫn nhập học']);
    $draft = NewsArticle::factory()->create(['title' => 'Nháp tuyển sinh']);
    NewsArticle::factory()->published()->count(12)->create(['category' => 'admissions']);

    $this->get(route('news', ['category' => 'school']))->assertSeeText($school->title)->assertDontSeeText($admission->title);
    $this->get(route('news', ['category' => 'admissions', 'page' => 2]))
        ->assertSeeText($admission->title)->assertDontSeeText($school->title)
        ->assertViewHas('articles', fn ($articles) => $articles->total() === 13 && $articles->count() === 1);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Livewire::test(NewsArticles::class)->set('search', 'Nháp tuyển sinh')->assertSee($draft->title)->assertDontSee($school->title)
        ->set('statusFilter', 'published')->assertDontSee($draft->title)->set('statusFilter', 'draft')->assertSee($draft->title);
});

test('article text is escaped in public and administrator views', function () {
    $payload = '<script>alert("news")</script>';
    $article = NewsArticle::factory()->published()->create(['title' => $payload, 'summary' => $payload, 'content' => $payload]);

    $this->get(route('news'))->assertSee(e($payload), false)->assertDontSee($payload, false);
    $this->get(route('news.show', $article->id))->assertSee(e($payload), false)->assertDontSee($payload, false);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $this->get(route('admin.news.index'))->assertSee(e($payload), false)->assertDontSee($payload, false);
});
