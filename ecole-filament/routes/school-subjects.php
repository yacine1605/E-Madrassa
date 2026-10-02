<?php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

Artisan::command('school:subjects-2026 {--apply : Appliquer le catalogue après sauvegarde}', function () {
    if (!app()->environment('local') || !config('school.backend')) {
        $this->error('Configuration réservée au backend local.'); return 1;
    }
    DB::beginTransaction();
    try {
        DB::select("select pg_advisory_xact_lock(hashtextextended('school-subjects-2026-2027', 0))");
        $tenant = \App\School\Services\DeploymentSchool::id();
        if (!$tenant) throw new RuntimeException('Aucun établissement provisionné.');
        $actor = DB::table('school.memberships')->where('tenant_id',$tenant)->where('active',true)->get()
            ->first(fn ($m) => in_array('catalog.write',json_decode($m->permissions,true),true));
        if (!$actor) throw new RuntimeException('Aucun membre autorisé à gérer le catalogue.');
        DB::select("select set_config('app.user_id', ?, true), set_config('app.tenant_id', ?, true)",[(string)$actor->user_id,$tenant]);
        if ($this->option('apply')) {
            $backup = [];
            foreach (['subjects','curriculum','levels','offerings','cohorts'] as $table) {
                $backup[$table] = DB::table('school.'.$table)->where('tenant_id',$tenant)->get();
            }
            $path = 'catalog-backups/'.now()->format('Ymd-His').'-'.\Illuminate\Support\Str::uuid().'.json';
            if (!Storage::disk('local')->put($path,json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))) throw new RuntimeException('Sauvegarde impossible.');
            $this->info('Sauvegarde : '.$path);
        }
        $result = (new \App\School\Services\AlgerianCurriculum)->sync($tenant);
        if ($this->option('apply')) DB::commit(); else DB::rollBack();
        $this->info(($this->option('apply') ? 'Appliqué' : 'Simulation').' : '.json_encode($result));
        return 0;
    } catch (Throwable $error) {
        DB::rollBack(); $this->error($error->getMessage()); return 1;
    }
});
