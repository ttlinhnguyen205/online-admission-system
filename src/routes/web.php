<?php

use App\Http\Controllers\NewsController;
use App\Livewire\Notifications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (): RedirectResponse => to_route('login'))->name('home');
Route::get('tin-tuc', [NewsController::class, 'index'])->name('news');
Route::get('tin-tuc/{article}', [NewsController::class, 'show'])->whereNumber('article')->name('news.show');
Route::view('huong-dan-dang-ky', 'welcome', ['page' => 'guidance', 'title' => 'Hướng dẫn đăng ký'])->name('registration-guidance');

Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('notifications', Notifications::class)->name('notifications.index');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
require __DIR__.'/candidate.php';
