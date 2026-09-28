<?php
use Illuminate\Support\Facades\Artisan;
require __DIR__.'/school-year.php';
require __DIR__.'/school-subjects.php';
require __DIR__.'/school-teachers.php';
require __DIR__.'/school-staff.php';
require __DIR__.'/school-teacher-accounts.php';
require __DIR__.'/school-preschool-students.php';
Artisan::command('school:user {email}',function(){
 $password=$this->secret('Mot de passe (12 caractères minimum)');
 if(strlen($password??'')<12){$this->error('Mot de passe trop court.');return 1;}
 App\Models\User::updateOrCreate(['email'=>$this->argument('email')],['name'=>'Direction','password'=>Illuminate\Support\Facades\Hash::make($password)]);
 $this->info('Compte de démonstration créé.');
});

Artisan::command('school:provision {email} {--name=Madrassa} {--kind=school}', function () {
 if (!app()->environment('local') || !config('school.backend')) {
  $this->error('Provisionnement réservé à cet environnement local avec backend actif.'); return 1;
 }
 $user = App\Models\User::where('email', $this->argument('email'))->first();
 if (!$user) { $this->error('Créez le compte avec school:user avant de provisionner son établissement.'); return 1; }
 $kind = $this->option('kind');
 if (!in_array($kind, ['school','training','mixed'], true)) { $this->error('Type invalide.'); return 1; }
 $tenant = Illuminate\Support\Facades\DB::transaction(function () use ($user, $kind) {
  $db = Illuminate\Support\Facades\DB::class;
  $db::select("select pg_advisory_xact_lock(hashtextextended('school-deployment-provision', 0))");
  $db::select("select set_config('app.user_id', ?, true)", [(string) $user->id]);
  $id = \App\School\Services\DeploymentSchool::id();
  $existing = $id ? $db::table('school.memberships')->where('tenant_id', $id)->where('user_id', $user->id)->first() : null;
  if ($existing) return $existing->tenant_id;
  if (!$id) {
   $id = (string) Illuminate\Support\Str::uuid();
   $db::table('school.institutions')->insert(['id'=>$id,'name'=>$this->option('name'),'kind'=>$kind]);
   $db::table('school.deployment')->insert(['id'=>1,'institution_id'=>$id]);
  }
  $permissions = ['catalog.read','catalog.write','students.read','students.write','enrollments.read','enrollments.write','pedagogy.read','pedagogy.write','finance.read','finance.write','hr.read','hr.write','payroll.read','payroll.write','audit.read'];
  $db::table('school.memberships')->insert(['tenant_id'=>$id,'user_id'=>$user->id,'permissions'=>json_encode($permissions)]);
  return $id;
 });
 $this->info('Établissement accessible : '.$tenant);
});
