<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StaffPayrollController as Pay;
Route::middleware(['auth',\App\Http\Middleware\SchoolModule::class.':payroll',\App\School\Http\TenantContext::class])->prefix('remunerations/{tenant}')->name('staff-pay.')->group(function () {
    Route::get('/',[Pay::class,'index'])->name('index');
    Route::post('contrats',[Pay::class,'contract'])->name('contract');
    Route::post('heures',[Pay::class,'declare'])->name('declare');
    Route::post('heures/{log}/validation',[Pay::class,'review'])->name('review');
    Route::post('calculer',[Pay::class,'calculate'])->name('calculate');
    Route::post('calculs/{run}/validation',[Pay::class,'validateRun'])->name('validate');
    Route::post('calculs/{run}/annulation',[Pay::class,'discardRun'])->name('discard');
    Route::post('versements/{run}',[Pay::class,'pay'])->name('pay');
    Route::get('recu/{payment}',[Pay::class,'receipt'])->name('receipt');
});
