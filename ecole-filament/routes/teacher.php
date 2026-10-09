<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TeacherPortalController as Teacher;

Route::middleware(\App\Http\Middleware\SchoolModule::class.':teacher_portal')->prefix('enseignant')->name('teacher.')->group(function () {
    Route::get('login', [Teacher::class, 'loginForm'])->name('login');
    Route::post('login', [Teacher::class, 'login'])->middleware('throttle:school-login')->name('authenticate');
    Route::post('logout', [Teacher::class, 'logout'])->name('logout');
    Route::middleware(\App\Http\Middleware\TeacherPortal::class)->group(function () {
        Route::middleware(\App\Http\Middleware\SchoolModule::class.':payroll')->group(function () {
            Route::get('remuneration',[\App\Http\Controllers\TeacherPayController::class,'index'])->name('pay');
            Route::post('remuneration/heures',[\App\Http\Controllers\TeacherPayController::class,'declare'])->name('pay.declare');
            Route::get('remuneration/recu/{payment}',[\App\Http\Controllers\TeacherPayController::class,'receipt'])->name('pay.receipt');
        });
        Route::get('notes', [\App\Http\Controllers\TeacherGradesController::class, 'index'])->name('grades.index');
        Route::post('notes', [\App\Http\Controllers\TeacherGradesController::class, 'create'])->name('grades.create');
        Route::get('notes/{assessment}', [\App\Http\Controllers\TeacherGradesController::class, 'show'])->name('grades.show');
        Route::post('notes/{assessment}', [\App\Http\Controllers\TeacherGradesController::class, 'save'])->name('grades.save');
        Route::get('/', [Teacher::class, 'home'])->name('home');
        Route::get('classes/{cohort}', [Teacher::class, 'classroom'])->name('classroom');
        Route::get('planning', [Teacher::class, 'schedule'])->name('schedule');
        Route::view('compte', 'teacher.account')->name('account');
        Route::post('compte/password', [Teacher::class, 'password'])->middleware('throttle:6,1')->name('password');
    });
});
