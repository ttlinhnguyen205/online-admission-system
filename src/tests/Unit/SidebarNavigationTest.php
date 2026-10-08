<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('admin sidebar has exactly the requested three groups and nine destinations', function () {
    $this->actingAs(User::factory()->make(['role' => UserRole::Admin]));

    $view = $this->view('layouts.app.sidebar', ['slot' => '']);

    $view->assertSeeTextInOrder([
        'QUẢN LÝ HỒ SƠ', 'Hồ sơ xét tuyển', 'Lịch sử xử lý',
        'QUẢN LÝ XÉT TUYỂN', 'Công cụ xét tuyển', 'Kết quả xét tuyển',
        'CẤU HÌNH TUYỂN SINH', 'Đợt tuyển sinh', 'Ngành đào tạo',
        'Phương thức xét tuyển', 'Chương trình tuyển sinh', 'Ngành mở xét tuyển',
    ])->assertDontSeeText('Hệ thống')->assertDontSeeText('Trang cấu hình');
    expect(preg_match_all('/\sdata-flux-sidebar-group[\s>]/', (string) $view))->toBe(3);
    $view->assertSeeInOrder(array_map(fn (string $name): string => 'href="'.route($name).'"', [
        'admin.applications.index', 'admin.review-history.index', 'admin.admission-engine',
        'admin.results.index', 'admin.admission-rounds.index', 'admin.majors.index',
        'admin.admission-methods.index', 'admin.admission-programs.index', 'admin.candidate-major-offerings.index',
    ]), false);
});

test('admin sidebar highlights the current destination including application details', function (string $name, string $destination) {
    $this->actingAs(User::factory()->make(['role' => UserRole::Admin]));
    request()->setRouteResolver(fn () => Route::getRoutes()->getByName($name));

    $view = $this->view('layouts.app.sidebar', ['slot' => '']);

    preg_match_all('/<a\b[^>]*\sdata-current(?:[\s>]|="[^"]*")[^>]*>/', (string) $view, $matches);
    expect($matches[0])->toHaveCount(1);
    expect($matches[0][0])->toContain('href="'.route($destination).'"', 'wire:navigate');
})->with([
    ['admin.applications.index', 'admin.applications.index'],
    ['admin.applications.show', 'admin.applications.index'],
    ['admin.review-history.index', 'admin.review-history.index'],
    ['admin.admission-engine', 'admin.admission-engine'],
    ['admin.results.index', 'admin.results.index'],
    ['admin.admission-rounds.index', 'admin.admission-rounds.index'],
    ['admin.majors.index', 'admin.majors.index'],
    ['admin.admission-methods.index', 'admin.admission-methods.index'],
    ['admin.admission-programs.index', 'admin.admission-programs.index'],
    ['admin.candidate-major-offerings.index', 'admin.candidate-major-offerings.index'],
]);

test('restricted admins cannot see admission navigation', function (UserStatus $status) {
    $this->actingAs(User::factory()->make(['role' => UserRole::Admin, 'status' => $status]));

    $this->view('layouts.app.sidebar', ['slot' => ''])
        ->assertDontSeeText('QUẢN LÝ HỒ SƠ')
        ->assertDontSeeText('QUẢN LÝ XÉT TUYỂN')
        ->assertDontSeeText('CẤU HÌNH TUYỂN SINH');
})->with([UserStatus::Inactive, UserStatus::Locked]);

test('unverified admins cannot see admission processing or results links', function () {
    $this->actingAs(User::factory()->unverified()->make(['role' => UserRole::Admin]));

    $this->view('layouts.app.sidebar', ['slot' => ''])
        ->assertDontSeeText('QUẢN LÝ XÉT TUYỂN')
        ->assertDontSee(route('admin.admission-engine'))
        ->assertDontSee(route('admin.results.index'));
});

test('staff sidebar retains its existing groups labels and configuration landing link', function () {
    $this->actingAs(User::factory()->make(['role' => UserRole::Staff]));

    $this->view('layouts.app.sidebar', ['slot' => ''])
        ->assertSeeTextInOrder(['Hệ thống', 'Bảng điều khiển', 'Duyệt hồ sơ', 'Duyệt hồ sơ xét tuyển', 'Lịch sử xử lý', 'Cấu hình tuyển sinh', 'Trang cấu hình', 'Đợt tuyển sinh', 'Ngành đào tạo', 'Phương thức xét tuyển', 'Chương trình tuyển sinh'])
        ->assertSee('href="'.route('admin.home').'"', false)
        ->assertDontSee(route('admin.candidate-major-offerings.index'))
        ->assertDontSeeText('QUẢN LÝ HỒ SƠ');
});

test('candidate sidebar retains its existing navigation', function () {
    $this->actingAs(User::factory()->make());

    $this->view('layouts.app.sidebar', ['slot' => ''])
        ->assertSeeTextInOrder(['Hệ thống', 'Bảng điều khiển', 'Tuyển sinh thí sinh', 'Hồ sơ cá nhân', 'Thông tin tuyển sinh', 'Điểm & minh chứng', 'Đăng ký nguyện vọng', 'Kết quả xét tuyển', 'Thông báo'])
        ->assertDontSeeText('QUẢN LÝ HỒ SƠ')
        ->assertDontSee(route('admin.applications.index'));
});
