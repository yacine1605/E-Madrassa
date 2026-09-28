<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('school:teachers-2026', function () {
    if (!app()->environment('local') || !config('school.backend')) {
        $this->error('Configuration réservée au backend local.');
        return 1;
    }
    try {
        $counts = DB::transaction(function () {
            DB::select("select pg_advisory_xact_lock(hashtextextended('school-teachers-2026-2027', 0))");
            $tenant = \App\School\Services\DeploymentSchool::id();
            if (!$tenant) throw new RuntimeException('Aucun établissement provisionné.');
            $actor = DB::table('school.memberships')->where('tenant_id', $tenant)->where('active', true)->get()
                ->first(fn ($m) => in_array('hr.write', json_decode($m->permissions, true), true));
            if (!$actor) throw new RuntimeException('Aucun membre autorisé à gérer les enseignants.');
            DB::select("select set_config('app.user_id', ?, true)", [(string) $actor->user_id]);
            DB::select("select set_config('app.tenant_id', ?, true)", [$tenant]);

            $period = DB::table('school.periods')->where('tenant_id', $tenant)->where('name', '2026/2027')->first();
            if (!$period) throw new RuntimeException('Année 2026/2027 introuvable.');
            $subjects = DB::table('school.subjects')->where('tenant_id', $tenant)
                ->whereIn('code', ['PRE-AR', 'PRI-AR-LECT', 'PRI-FR', 'PRI-EN'])->pluck('id', 'code');
            if ($subjects->count() !== 4) throw new RuntimeException('Matières préscolaires et primaires manquantes.');
            $classes = DB::table('school.cohorts as c')
                ->join('school.offerings as o', 'o.id', '=', 'c.offering_id')
                ->join('school.levels as l', 'l.id', '=', 'o.level_id')
                ->join('school.programs as p', 'p.id', '=', 'l.program_id')
                ->where('c.tenant_id', $tenant)->where('o.period_id', $period->id)
                ->whereIn('p.name', ['Préscolaire', 'Primaire'])
                ->select('c.id', 'c.name', 'l.code as level_code', 'p.name as cycle')
                ->orderBy('p.name')->orderBy('l.code')->orderBy('c.name')->get();
            if ($classes->where('cycle', 'Préscolaire')->count() !== 2 || $classes->where('cycle', 'Primaire')->count() !== 9) {
                throw new RuntimeException('Les 2 classes préscolaires et 9 classes primaires attendues sont absentes.');
            }
            $names = [
                'أمينة بن صالح', 'خديجة بوزيد',
                'فاطمة الزهراء بن عمر', 'ليلى حميدي', 'ياسمين بوعلام',
                'سميرة قاسمي', 'نادية براهيمي', 'سارة منصوري',
                'مريم حداد', 'أحلام درار', 'نسيمة بلقاسم',
                'زهرة مرابط', 'إيمان رحماني', 'نوال بن عيسى',
                'رقية سعيدي', 'كريمة شريف', 'أسماء خالدي',
                'صفية عمراوي', 'حنان زروقي', 'لمياء عياد',
                'زينب طاهري', 'جميلة بلحاج', 'رانية بوشارب',
                'سعاد بن يوسف', 'حنين غربي', 'مليكة بن داود',
                'هدى معمري', 'كوثر عزوز', 'إكرام بن ناصر',
            ];
            $counts = ['teachers'=>0, 'assignments'=>0];
            $index = 0;
            foreach ($classes as $class) {
                $classLetter = str_ends_with($class->name, 'A') ? 'A' : (str_ends_with($class->name, 'B') ? 'B' : null);
                if (!$classLetter) throw new RuntimeException('Nom de classe inattendu : '.$class->name);
                $prefix = $class->cycle === 'Préscolaire' ? 'PRE' : 'PRI-'.$class->level_code;
                $subjectsForClass = $class->cycle === 'Préscolaire'
                    ? ['AR'=>'PRE-AR']
                    : ['AR'=>'PRI-AR-LECT', 'FR'=>'PRI-FR', 'EN'=>'PRI-EN'];
                foreach ($subjectsForClass as $suffix => $subjectCode) {
                    $name = $names[$index++];
                    $subjectId = $subjects[$subjectCode];
                    $existing = DB::table('school.teacher_assignments')->where('tenant_id', $tenant)
                        ->where('cohort_id', $class->id)->where('subject_id', $subjectId)->first();
                    if ($existing) continue;
                    $code = 'T-2026-'.$prefix.'-'.$classLetter.'-'.$suffix;
                    $teacher = DB::table('school.staff')->where('tenant_id', $tenant)->where('code', $code)->first();
                    if ($teacher && ($teacher->name !== $name || !$teacher->is_teacher || !$teacher->active)) {
                        throw new RuntimeException('Code enseignant déjà utilisé : '.$code);
                    }
                    if (!$teacher) {
                        $teacherId = (string) \Illuminate\Support\Str::uuid();
                        DB::table('school.staff')->insert(['id'=>$teacherId, 'tenant_id'=>$tenant,
                            'code'=>$code, 'name'=>$name, 'is_teacher'=>true, 'active'=>true]);
                        $counts['teachers']++;
                    } else $teacherId = $teacher->id;
                    DB::table('school.teacher_assignments')->insert(['id'=>(string) \Illuminate\Support\Str::uuid(),
                        'tenant_id'=>$tenant, 'cohort_id'=>$class->id, 'subject_id'=>$subjectId, 'teacher_id'=>$teacherId]);
                    $counts['assignments']++;
                }
            }
            return $counts;
        });
        $this->info('Enseignants 2026/2027 configurés : '.json_encode($counts));
        return 0;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());
        return 1;
    }
});
