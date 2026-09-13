<?php

use App\Livewire\Admin;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Major;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::livewire('/', Admin\Home::class)->can('viewAny', AdmissionRound::class)->name('home');
    Route::livewire('admission-rounds', Admin\AdmissionRounds::class)->can('viewAny', AdmissionRound::class)->name('admission-rounds.index');
    Route::livewire('majors', Admin\Majors::class)->can('viewAny', Major::class)->name('majors.index');
    Route::livewire('admission-methods', Admin\AdmissionMethods::class)->can('viewAny', AdmissionMethod::class)->name('admission-methods.index');
    Route::livewire('admission-programs', Admin\AdmissionPrograms::class)->can('viewAny', AdmissionProgram::class)->name('admission-programs.index');
});
