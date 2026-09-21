<?php

use App\Http\Controllers\CandidateAdmissionEvidenceController;
use App\Http\Controllers\CandidateCitizenIdController;
use App\Http\Controllers\CandidateDocumentDownloadController;
use App\Http\Controllers\CandidatePhotoController;
use App\Http\Controllers\CandidateScoreEvidenceController;
use App\Livewire\Candidate;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified'])->group(function (): void {
    Route::prefix('candidate')->name('candidate.')->group(function (): void {
        Route::livewire('results', Candidate\Results::class)->name('results.index');
        Route::livewire('notifications', Candidate\Notifications::class)->name('notifications.index');
        Route::livewire('profile', Candidate\Profile::class)->name('profile.edit');
        Route::livewire('scores', Candidate\Scores::class)->name('scores.index');
        Route::livewire('admission-information', Candidate\AdmissionInformation::class)->name('admission-information.index');
        Route::livewire('documents', Candidate\Documents::class)->name('documents.index');
        Route::livewire('applications', Candidate\Applications::class)->name('applications.index');
        Route::livewire('applications/{application}', Candidate\ApplicationDetails::class)
            ->whereNumber('application')->name('applications.show');
        Route::livewire('applications/{application}/documents', Candidate\Documents::class)
            ->whereNumber('application')->name('applications.documents.index');
    });

    Route::get('admission/documents/{document}/download', CandidateDocumentDownloadController::class)
        ->whereNumber('document')->name('admission.documents.download');

    Route::get('admission/profiles/{profile}/photo', CandidatePhotoController::class)
        ->whereNumber('profile')->name('admission.profiles.photo');

    Route::get('admission/scores/{score}/evidence', CandidateScoreEvidenceController::class)
        ->whereNumber('score')->name('admission.scores.evidence');

    Route::get('admission/information/{type}/{record}/evidence', CandidateAdmissionEvidenceController::class)
        ->whereIn('type', ['exam-results', 'transcripts', 'certificates', 'admission-claims'])
        ->whereNumber('record')
        ->name('candidate.admission-information.evidence');

    Route::get(
        'admission/profiles/{profile}/citizen-id/{side}',
        CandidateCitizenIdController::class
    )
        ->whereNumber('profile')
        ->whereIn('side', ['front', 'back'])
        ->name('admission.profiles.citizen-id');
});
