<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Notifications;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('admin toolbar links to the notifications page with an empty state', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $response = $this->get(route('notifications.index'));

    $response->assertSeeText('Chưa có thông báo nào.')
        ->assertSee('href="'.route('notifications.index').'"', false);
});

test('candidate toolbar keeps its dedicated notification destination', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('home'))->assertSee('href="'.route('candidate.notifications.index').'"', false);
});

test('notifications are private and marking read preserves the first read time', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $other = User::factory()->create(['role' => UserRole::Admin]);
    $own = $user->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'test.notification',
        'data' => ['title' => 'Thông báo của tôi', 'message' => 'Nội dung riêng'],
    ]);
    $foreign = $other->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'test.notification',
        'data' => ['title' => 'Thông báo của người khác'],
    ]);
    $this->actingAs($user);

    $page = Livewire::test(Notifications::class)->assertSee('Thông báo của tôi')
        ->assertDontSee('Thông báo của người khác')
        ->call('markRead', $own->id)->assertHasNoErrors();

    $readAt = $own->fresh()->read_at;
    expect($readAt)->not->toBeNull();
    $this->travel(1)->minutes();
    $page->call('markRead', $own->id);
    expect($own->fresh()->read_at->equalTo($readAt))->toBeTrue();
    expect($foreign->fresh()->read_at)->toBeNull();
    $page->call('markRead', $foreign->id)->assertNotFound();
});

test('notification content is escaped and supplied URLs are not rendered', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $user->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'test.notification',
        'data' => ['title' => '<script>alert(1)</script>', 'message' => '<img src=x onerror=alert(2)>', 'url' => 'https://example.invalid/unsafe'],
    ]);
    $this->actingAs($user);

    Livewire::test(Notifications::class)->assertSee('<script>alert(1)</script>')
        ->assertSee('<img src=x onerror=alert(2)>')
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertDontSeeHtml('<img src=x onerror=alert(2)>')
        ->assertDontSee('https://example.invalid/unsafe');
});

test('guests must sign in to view notifications', function () {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
});

test('unverified users must verify before viewing notifications', function () {
    $this->actingAs(User::factory()->unverified()->create(['role' => UserRole::Admin]));

    $this->get(route('notifications.index'))->assertRedirect(route('verification.notice'));
});

test('locked users cannot mark notifications read from an existing page', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $notification = $user->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'test.notification', 'data' => [],
    ]);
    $this->actingAs($user);
    $page = Livewire::test(Notifications::class);
    $user->update(['status' => UserStatus::Locked]);

    $page->call('markRead', $notification->id)->assertForbidden();

    expect($notification->fresh()->read_at)->toBeNull();
});
