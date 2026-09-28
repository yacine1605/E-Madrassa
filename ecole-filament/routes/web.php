<?php
use Illuminate\Support\Facades\Route;
require __DIR__.'/teacher.php';
Route::redirect('/','/admin');
Route::get('/demo/attachment/{module}/{id}',function(string $module,string $id){
 abort_unless(config('school.demo') && app()->environment('local'),403);
 abort_unless(in_array($module,['lessons','assessments','payments','expenses','attendance']),404);
 $record=App\Support\DemoStore::find($module,$id);$path=$record['attachment']??null;
 abort_unless($path && str_starts_with($path,'demo-uploads/'),404);
 return Illuminate\Support\Facades\Storage::disk('local')->download($path);
})->middleware('auth')->name('demo.attachment');
