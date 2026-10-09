<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StudentLifecycleController as C;
Route::middleware(['auth',\App\Http\Middleware\SchoolModule::class.':lifecycle',\App\School\Http\TenantContext::class])->prefix('scolarite/{tenant}')->name('lifecycle.')->group(function () {
    Route::get('admissions',[C::class,'admissions'])->name('admissions');
    Route::post('admissions',[C::class,'apply'])->name('apply');
    Route::get('admissions/{application}',[C::class,'application'])->name('application');
    Route::post('admissions/{application}/decision',[C::class,'review'])->name('review');
    Route::post('admissions/{application}/inscrire',[C::class,'admit'])->name('admit');
    Route::middleware(\App\Http\Middleware\SchoolModule::class.':progression')->group(function () {
        Route::get('passage-annuel',[C::class,'progression'])->name('progression');
        Route::post('passage-annuel',[C::class,'progress'])->name('progress');
    });
    Route::get('eleves/{student}/historique',[C::class,'history'])->name('history');
});
