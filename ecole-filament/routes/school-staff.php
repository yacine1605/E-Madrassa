<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

Artisan::command('school:staff-2026', function () {
    if (!app()->environment('local') || !config('school.backend')) {
        $this->error('Configuration réservée au backend local.');
        return 1;
    }
    try {
        $created = DB::transaction(function () {
            DB::select("select pg_advisory_xact_lock(hashtextextended('school-staff-2026', 0))");
            $tenant = \App\School\Services\DeploymentSchool::id();
            if (!$tenant) throw new RuntimeException('Aucun établissement provisionné.');
            $actor = DB::table('school.memberships')->where('tenant_id', $tenant)->where('active', true)->get()
                ->first(fn ($m) => in_array('hr.write', json_decode($m->permissions, true), true));
            if (!$actor) throw new RuntimeException('Aucun membre autorisé à gérer le personnel.');
            DB::select("select set_config('app.user_id', ?, true)", [(string) $actor->user_id]);
            DB::select("select set_config('app.tenant_id', ?, true)", [$tenant]);

            $people = [
                ['STAFF-CUISINE-01', 'عبد القادر بن عيسى', 'Chef de cuisine'],
                ['STAFF-CUISINE-02', 'نصيرة بوعلام', 'Chef de cuisine'],
                ['STAFF-CUISINE-03', 'محمد الأمين رحماني', 'Chef de cuisine'],
                ['STAFF-CUISINE-04', 'فريدة بن سالم', 'Chef de cuisine'],
                ['STAFF-OUVRIER-01', 'مصطفى قاسمي', 'Ouvrier'],
                ['STAFF-OUVRIER-02', 'سمير حداد', 'Ouvrier'],
                ['STAFF-SECRETAIRE-01', 'ليلى منصوري', 'Secrétaire'],
                ['STAFF-SECRETAIRE-02', 'أمينة بوزيد', 'Secrétaire'],
                ['STAFF-SECRETAIRE-03', 'خديجة بلقاسم', 'Secrétaire'],
            ];
            $created = 0;
            foreach ($people as [$code, $name, $role]) {
                $old = DB::table('school.staff')->where('tenant_id', $tenant)->where('code', $code)->first();
                if ($old) {
                    if ($old->name !== $name || $old->role !== $role || $old->is_teacher || !$old->active) {
                        throw new RuntimeException('Code personnel déjà utilisé avec des données différentes : '.$code);
                    }
                    continue;
                }
                DB::table('school.staff')->insert([
                    'id'=>(string) Str::uuid(), 'tenant_id'=>$tenant, 'code'=>$code,
                    'name'=>$name, 'role'=>$role, 'is_teacher'=>false, 'active'=>true,
                ]);
                $created++;
            }
            return $created;
        });
        $this->info('Personnel ajouté : '.$created.' (4 chefs de cuisine, 2 ouvriers, 3 secrétaires).');
        return 0;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());
        return 1;
    }
});
