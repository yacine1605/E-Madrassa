<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

Artisan::command('school:setup-2026', function () {
    if (!app()->environment('local') || !config('school.backend')) {
        $this->error('Configuration réservée au backend local.');
        return 1;
    }
    try {
        $counts = DB::transaction(function () {
            DB::select("select pg_advisory_xact_lock(hashtextextended('school-year-2026-2027', 0))");
            $tenant = \App\School\Services\DeploymentSchool::id();
            if (!$tenant) throw new RuntimeException('Aucun établissement provisionné.');
            $actor = DB::table('school.memberships')->where('tenant_id', $tenant)->where('active', true)->get()
                ->first(fn ($m) => in_array('catalog.write', json_decode($m->permissions, true), true));
            if (!$actor) throw new RuntimeException('Aucun membre autorisé à gérer le catalogue.');
            DB::select("select set_config('app.user_id', ?, true)", [(string) $actor->user_id]);
            DB::select("select set_config('app.tenant_id', ?, true)", [$tenant]);
            $campuses = DB::table('school.campuses')->where('tenant_id', $tenant)->get();
            if ($campuses->count() !== 1) throw new RuntimeException('Un campus unique est nécessaire ; aucun campus ne sera choisi arbitrairement.');
            $counts = ['programs'=>0, 'levels'=>0, 'offerings'=>0, 'cohorts'=>0];
            $ensure = function (string $table, array $key, array $values) use ($tenant, &$counts) {
                $query = DB::table('school.'.$table)->where('tenant_id', $tenant)->where($key);
                $old = $query->first();
                if ($old) {
                    foreach ($values as $field => $value) {
                        if ((string) $old->$field !== (string) $value) {
                            throw new RuntimeException("Conflit dans $table ($field). Données existantes conservées.");
                        }
                    }
                    return $old->id;
                }
                $id = (string) Str::uuid();
                DB::table('school.'.$table)->insert(['id'=>$id, 'tenant_id'=>$tenant, ...$key, ...$values]);
                if (isset($counts[$table])) $counts[$table]++;
                return $id;
            };
            $period = $ensure('periods', ['name'=>'2026/2027'], ['starts_on'=>'2026-09-21', 'ends_on'=>'2027-06-30']);
            $cycles = [
                ['Préscolaire', 'PRESCOLAIRE', 1, 25000],
                ['Primaire', 'PRIMAIRE', 5, 25000],
                ['Moyen', 'MOYEN', 4, 40000],
                ['Secondaire', 'SECONDAIRE', 3, 60000],
            ];
            foreach ($cycles as [$name, $code, $levels, $monthly]) {
                $existingPrograms = DB::table('school.programs')->where('tenant_id', $tenant)->where('name', $name)->get();
                if ($existingPrograms->count() > 1) throw new RuntimeException("Plusieurs programmes nommés $name.");
                $program = $existingPrograms->first();
                if ($program && (!$program->active || $program->kind !== 'school')) throw new RuntimeException("Programme $name incompatible.");
                $programId = $program ? $program->id : $ensure('programs', ['code'=>$code], [
                    'name'=>$name, 'kind'=>'school',
                    'description'=>"Abonnement : $monthly DA/mois. Année 2026/2027 : 10 mois, septembre à juin, sans proratisation.",
                ]);
                for ($year = 1; $year <= $levels; $year++) {
                    $levelName = $name === 'Préscolaire' ? 'Préscolaire' : "$name — ".$year.($year === 1 ? 're' : 'e').' année';
                    $existingFirstPrimary = $name === 'Primaire' && $year === 1
                        ? DB::table('school.levels')->where('tenant_id', $tenant)->where('program_id', $programId)->where('code', '1AP')->first()
                        : null;
                    $level = $existingFirstPrimary?->id ?? $ensure('levels',
                        ['program_id'=>$programId, 'code'=>$name === 'Primaire' ? $year.'AP' : (string) $year],
                        ['name'=>$levelName, 'position'=>$year]);
                    $offer = $ensure('offerings', ['name'=>"$levelName — 2026/2027 — $monthly DA/mois × 10"], [
                        'level_id'=>$level, 'period_id'=>$period, 'campus_id'=>$campuses->first()->id,
                        // Monetary amounts are stored in centimes. Invoice is the full ten-month total.
                        'price_minor'=>$monthly * 10 * 100,
                    ]);
                    $classes = $name === 'Primaire' && $year === 5 ? 1 : 2;
                    for ($class = 1; $class <= $classes; $class++) {
                        $ensure('cohorts', ['offering_id'=>$offer, 'name'=>"$levelName — Classe ".($class === 1 ? 'A' : 'B')], ['capacity'=>25]);
                    }
                }
            }
            return $counts;
        });
        $this->info('Année 2026/2027 configurée (10 mois, sans proratisation). Créations : '.json_encode($counts));
        return 0;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());
        return 1;
    }
});
