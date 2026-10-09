<?php
namespace App\Models;
class User extends \Illuminate\Foundation\Auth\User implements \Filament\Models\Contracts\FilamentUser {
 protected $fillable=['name','email','password'];
 protected $hidden=['password','remember_token'];
 protected function casts(): array { return ['password'=>'hashed']; }
 public function canAccessPanel(\Filament\Panel $panel):bool {
  if (!config('school.backend')) return config('school.demo') && app()->environment('local');
  return \Illuminate\Support\Facades\DB::transaction(function () {
   $member = \App\School\Services\DeploymentSchool::membership($this->id);
   if (!$member) return false;
   $modules = app(\App\School\Modules\ModuleRegistry::class);
   return $modules->effectivePermissions(json_decode($member->permissions, true)) !== []
    || ($modules->enabled('teacher_portal') && \App\Http\Middleware\TeacherPortal::teacher($this->id) !== null);
  });
 }
}
