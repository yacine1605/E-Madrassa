<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

Artisan::command('school:subjects-2026', function () {
    if (!app()->environment('local') || !config('school.backend')) {
        $this->error('Configuration réservée au backend local.');
        return 1;
    }
    try {
        $result = DB::transaction(function () {
            DB::select("select pg_advisory_xact_lock(hashtextextended('school-subjects-2026-2027', 0))");
            $tenant = \App\School\Services\DeploymentSchool::id();
            if (!$tenant) throw new RuntimeException('Aucun établissement provisionné.');
            $actor = DB::table('school.memberships')->where('tenant_id', $tenant)->where('active', true)->get()
                ->first(fn ($m) => in_array('catalog.write', json_decode($m->permissions, true), true));
            if (!$actor) throw new RuntimeException('Aucun membre autorisé à gérer le catalogue.');
            DB::select("select set_config('app.user_id', ?, true)", [(string) $actor->user_id]);
            DB::select("select set_config('app.tenant_id', ?, true)", [$tenant]);
            $period = DB::table('school.periods')->where('tenant_id', $tenant)->where('name', '2026/2027')->first();
            if (!$period) throw new RuntimeException('Créez d’abord l’année 2026/2027.');
            $plans = [
                'Préscolaire' => [
                    ['PRE-AR', 'Arabe (اللغة العربية)'],
                ],
                'Primaire' => [
                    ['PRI-AR-LECT', 'Langue arabe (اللغة العربية) — Lecture'],
                    ['PRI-MATH', 'Mathématiques (الرياضيات)'],
                    ['PRI-FR', 'Langue française (اللغة الفرنسية)'],
                    ['PRI-EN', 'Langue anglaise (اللغة الإنجليزية)'],
                    ['PRI-ISLAM', 'Éducation islamique (التربية الإسلامية)'],
                    ['PRI-CIV', 'Éducation civique (التربية المدنية)'],
                    ['PRI-HG', 'Histoire et Géographie (التاريخ والجغرافيا)'],
                    ['PRI-SCI-TECH', 'Éducation scientifique et technologique (التربية العلمية والتكنولوجية)'],
                    ['PRI-ART', 'Éducation artistique (التربية الفنية)'],
                    ['PRI-SPORT', 'Éducation sportive (التربية البدنية)'],
                ],
                'Moyen' => [
                    ['MOY-AR', 'Langue arabe (اللغة العربية)'],
                    ['MOY-MATH', 'Mathématiques (الرياضيات)'],
                    ['MOY-FR', 'Langue française (اللغة الفرنسية)'],
                    ['MOY-EN', 'Langue anglaise (اللغة الإنجليزية)'],
                    ['MOY-ISLAM', 'Éducation islamique (التربية الإسلامية)'],
                    ['MOY-CIV', 'Éducation civique (التربية المدنية)'],
                    ['MOY-SCI-TECH', 'Éducation scientifique et technologique (التربية العلمية والتكنولوجية)'],
                    ['MOY-HG', 'Histoire et Géographie (التاريخ والجغرافيا)'],
                    ['MOY-SPORT', 'Éducation physique et sportive (التربية البدنية)'],
                    ['MOY-ART', 'Éducation artistique'],
                ],
            ];
            $created = ['subjects'=>0, 'curriculum'=>0];
            foreach ($plans as $cycle => $subjects) {
                $program = DB::table('school.programs')->where('tenant_id', $tenant)->where('name', $cycle)->first();
                if (!$program) throw new RuntimeException("Programme $cycle introuvable.");
                $levels = DB::table('school.levels as l')
                    ->join('school.offerings as o', fn ($join) => $join->on('o.level_id', '=', 'l.id')->on('o.tenant_id', '=', 'l.tenant_id'))
                    ->where('l.tenant_id', $tenant)->where('l.program_id', $program->id)
                    ->where('o.period_id', $period->id)->select('l.id')->distinct()->get();
                $expected = match ($cycle) {'Préscolaire'=>1, 'Primaire'=>5, 'Moyen'=>4};
                if ($levels->count() !== $expected) throw new RuntimeException("$cycle : $expected niveaux attendus pour 2026/2027.");
                foreach ($subjects as [$code, $name]) {
                    $subject = DB::table('school.subjects')->where('tenant_id', $tenant)->where('code', $code)->first();
                    if ($subject && $subject->name !== $name) throw new RuntimeException("Code de matière $code déjà utilisé avec un autre nom.");
                    if (!$subject) {
                        $id = (string) Str::uuid();
                        DB::table('school.subjects')->insert(['id'=>$id, 'tenant_id'=>$tenant, 'code'=>$code, 'name'=>$name]);
                        $subject = (object) ['id'=>$id];
                        $created['subjects']++;
                    }
                    foreach ($levels as $level) {
                        $exists = DB::table('school.curriculum')->where('tenant_id', $tenant)
                            ->where('level_id', $level->id)->where('subject_id', $subject->id)->exists();
                        if (!$exists) {
                            DB::table('school.curriculum')->insert([
                                'id'=>(string) Str::uuid(), 'tenant_id'=>$tenant,
                                'level_id'=>$level->id, 'subject_id'=>$subject->id,
                                'coefficient'=>1,
                            ]);
                            $created['curriculum']++;
                        }
                    }
                }
            }
            return $created;
        });
        $this->info('Matières 2026/2027 configurées : '.json_encode($result));
        return 0;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());
        return 1;
    }
});
