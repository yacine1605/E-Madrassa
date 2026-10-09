<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SchoolDocumentsController as Documents;
Route::middleware(['auth', \App\Http\Middleware\SchoolModule::class.':report_cards', \App\School\Http\TenantContext::class])->prefix('documents/{tenant}')->name('documents.')->group(function () {
    Route::get('/', [Documents::class, 'index'])->name('index');
    Route::post('terms', [Documents::class, 'configure'])->name('configure');
    Route::get('bulletins/{term}/{cohort}', [Documents::class, 'bulletins'])->name('bulletins');
    Route::post('bulletins/{term}/{cohort}/observations', [Documents::class, 'observations'])->name('observations');
    Route::post('bulletins/{term}/{cohort}/validate', [Documents::class, 'validateTerm'])->name('validate-term');
    Route::post('bulletins/{term}/{cohort}/close', [Documents::class, 'closeTerm'])->name('close-term');
    Route::post('bulletins/{term}/{cohort}/draft', [Documents::class, 'draftTerm'])->name('draft-term');
    Route::get('annual/{cohort}', [Documents::class, 'annual'])->name('annual');
    Route::post('annual/{cohort}/validate', [Documents::class, 'validateAnnual'])->name('validate-annual');
    Route::post('annual/{cohort}/close', [Documents::class, 'closeAnnual'])->name('close-annual');
    Route::post('annual/{cohort}/draft', [Documents::class, 'draftAnnual'])->name('draft-annual');
    Route::get('archives/{archive}', [Documents::class, 'archive'])->name('archive');
});
