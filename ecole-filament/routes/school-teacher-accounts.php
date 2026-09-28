<?php

use App\Models\User;
use App\School\Services\DeploymentSchool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('school:teacher-accounts', function () {
    if (!app()->environment('local') || !config('school.backend')) {
        $this->error('Configuration réservée au backend local.');
        return 1;
    }
    $path = storage_path('app/private/teacher-credentials-2026.csv');
    $createdFile = false;
    try {
        $count = DB::transaction(function () use ($path, &$createdFile) {
            DB::select("select pg_advisory_xact_lock(hashtextextended('school-teacher-accounts', 0))");
            $tenant = DeploymentSchool::id();
            if (!$tenant) throw new RuntimeException('Aucun établissement provisionné.');
            $actor = DB::table('school.memberships')->where('tenant_id', $tenant)->where('active', true)->get()
                ->first(fn ($m) => in_array('hr.write', json_decode($m->permissions, true), true));
            if (!$actor) throw new RuntimeException('Aucun membre autorisé à gérer les enseignants.');
            DB::select("select set_config('app.user_id', ?, true)", [(string) $actor->user_id]);
            DB::select("select set_config('app.tenant_id', ?, true)", [$tenant]);
            $teachers = DB::table('school.staff')->where('tenant_id', $tenant)
                ->where('is_teacher', true)->where('active', true)->orderBy('code')->get();
            $unlinked = $teachers->whereNull('user_id');
            if ($unlinked->isEmpty()) return 0;
            if (is_file($path)) throw new RuntimeException('Fichier d’identifiants déjà présent ; aucun compte supplémentaire créé.');
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) {
                throw new RuntimeException('Impossible de créer le dossier privé.');
            }
            $credentials = [];
            foreach ($unlinked as $teacher) {
                $email = strtolower($teacher->code).'@teachers.madrassa.test';
                if (User::where('email', $email)->exists()) {
                    throw new RuntimeException('Adresse de compte déjà utilisée : '.$email);
                }
                $password = bin2hex(random_bytes(16));
                $user = User::create(['name'=>$teacher->name, 'email'=>$email, 'password'=>$password]);
                DB::table('school.memberships')->insert([
                    'tenant_id'=>$tenant, 'user_id'=>$user->id, 'permissions'=>'[]', 'active'=>true,
                ]);
                DB::table('school.staff')->where('tenant_id', $tenant)->where('id', $teacher->id)
                    ->update(['user_id'=>$user->id]);
                $credentials[] = [$teacher->code, $teacher->name, $email, $password];
            }
            $file = fopen($path, 'x');
            if ($file === false) throw new RuntimeException('Impossible de créer le fichier privé.');
            $createdFile = true;
            try {
                if (fputcsv($file, ['code', 'enseignant', 'email', 'mot_de_passe_provisoire'], ';') === false) {
                    throw new RuntimeException('Impossible d’écrire les identifiants.');
                }
                foreach ($credentials as $row) {
                    if (fputcsv($file, $row, ';') === false) throw new RuntimeException('Impossible d’écrire les identifiants.');
                }
            } finally {
                fclose($file);
            }
            chmod($path, 0600);
            return count($credentials);
        });
        $this->info($count.' compte(s) enseignant(s) créé(s). Identifiants privés : '.$path);
        return 0;
    } catch (Throwable $exception) {
        if ($createdFile && is_file($path)) unlink($path);
        $this->error($exception->getMessage());
        return 1;
    }
});
