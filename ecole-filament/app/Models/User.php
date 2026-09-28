<?php
namespace App\Models;
class User extends \Illuminate\Foundation\Auth\User implements \Filament\Models\Contracts\FilamentUser {
 protected $fillable=['name','email','password'];
 protected $hidden=['password','remember_token'];
 protected function casts(): array { return ['password'=>'hashed']; }
 public function canAccessPanel(\Filament\Panel $panel):bool {
  if (!config('school.backend')) return config('school.demo') && app()->environment('local');
  return \Illuminate\Support\Facades\DB::transaction(function () {
   return \App\School\Services\DeploymentSchool::membership($this->id) !== null;
  });
 }
}
