<?php

use App\Http\Controllers\AdmissionReportController;
use App\Livewire\Admin;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Application;
use App\Models\CandidateMajorOffering;
use App\Models\Major;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('reports/{format}', AdmissionReportController::class)->whereIn('format', ['xlsx', 'pdf'])
        ->can('export', Application::class)->middleware('throttle:6,1')->name('reports.download');
    Route::livewire('results', Admin\Results::class)->can('publishResults', AdmissionRound::class)->name('results.index');
    Route::livewire('admission-engine', Admin\AdmissionEngine::class)->can('process', AdmissionRound::class)->name('admission-engine');
    Route::livewire('applications', Admin\Applications::class)->can('viewAny', Application::class)->name('applications.index');
    Route::livewire('applications/{application}', Admin\ApplicationDetails::class)->whereNumber('application')
        ->can('viewAny', Application::class)->name('applications.show');
    Route::livewire('review-history', Admin\ReviewHistory::class)
        ->can('viewAny', Application::class)
        ->name('review-history.index');
    Route::livewire('/', Admin\Home::class)->can('viewAny', AdmissionRound::class)->name('home');
    Route::livewire('admission-rounds', Admin\AdmissionRounds::class)->can('viewAny', AdmissionRound::class)->name('admission-rounds.index');
    Route::livewire('majors', Admin\Majors::class)->can('viewAny', Major::class)->name('majors.index');
    Route::livewire('admission-methods', Admin\AdmissionMethods::class)->can('viewAny', AdmissionMethod::class)->name('admission-methods.index');
    Route::livewire('admission-programs', Admin\AdmissionPrograms::class)->can('viewAny', AdmissionProgram::class)->name('admission-programs.index');
    Route::livewire('candidate-major-offerings', Admin\CandidateMajorOfferings::class)->can('viewAny', CandidateMajorOffering::class)->name('candidate-major-offerings.index');
});
