<?php

use App\School\Services\DeploymentSchool;
use App\School\Services\SchoolService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

Artisan::command('school:preschool-students-2026', function () {
    if (!app()->environment('local') || !config('school.backend')) {
        $this->error('Configuration réservée au backend local.');
        return 1;
    }
    try {
        $counts = DB::transaction(function () {
            DB::select("select pg_advisory_xact_lock(hashtextextended('preschool-students-2026-A', 0))");
            $tenant = DeploymentSchool::id();
            if (!$tenant) throw new RuntimeException('Aucun établissement provisionné.');
            $actor = DB::table('school.memberships')->where('tenant_id', $tenant)->where('active', true)->get()
                ->first(fn ($m) => !array_diff(['students.write','enrollments.write'], json_decode($m->permissions, true)));
            if (!$actor) throw new RuntimeException('Aucun membre autorisé à créer les élèves et leurs inscriptions.');
            DB::select("select set_config('app.user_id', ?, true)", [(string)$actor->user_id]);
            DB::select("select set_config('app.tenant_id', ?, true)", [$tenant]);
            request()->attributes->set('school.tenant', $tenant);
            request()->attributes->set('school.permissions', json_decode($actor->permissions, true));

            $classes = DB::table('school.cohorts as c')->join('school.offerings as o', 'o.id', '=', 'c.offering_id')
                ->join('school.periods as p', 'p.id', '=', 'o.period_id')->where('c.tenant_id', $tenant)
                ->where('c.name', 'Préscolaire — Classe A')->where('p.name', '2026/2027')
                ->where('o.active', true)->get(['c.id','c.offering_id']);
            if ($classes->count() !== 1) throw new RuntimeException('Une classe Préscolaire — Classe A unique est nécessaire pour 2026/2027.');
            $class = $classes->first();
            $names = [
                ['آدم', 'بن علي'], ['ليان', 'منصوري'], ['ياسين', 'بوزيد'], ['مريم', 'بن صالح'],
                ['محمد أمين', 'قاسمي'], ['نور', 'رحماني'], ['يوسف', 'حداد'], ['سارة', 'بلقاسم'],
                ['إلياس', 'بن عمر'], ['جنى', 'حميدي'],
            ];
            $school = app(SchoolService::class);
            $counts = ['students'=>0, 'enrollments'=>0, 'invoices'=>0];
            foreach ($names as $index => [$firstName, $lastName]) {
                $matricule = 'PRE-A-2026-'.str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT);
                $student = $school->query('students')->where('matricule', $matricule)->first();
                if ($student && ($student->first_name !== $firstName || $student->last_name !== $lastName || !$student->active)) {
                    throw new RuntimeException('Matricule déjà utilisé avec des données différentes : '.$matricule);
                }
                if (!$student) {
                    $student = $school->insert('students', ['matricule'=>$matricule, 'first_name'=>$firstName, 'last_name'=>$lastName, 'active'=>true]);
                    $counts['students']++;
                }
                $previous = $school->query('enrollments')->where('student_id', $student->id)->where('offering_id', $class->offering_id)->get();
                if ($previous->isNotEmpty()) {
                    if ($previous->count() !== 1 || $previous->first()->cohort_id !== $class->id || $previous->first()->status === 'cancelled') {
                        throw new RuntimeException('Une inscription différente existe déjà pour '.$matricule);
                    }
                    if (!$school->query('invoices')->where('enrollment_id', $previous->first()->id)->exists()) {
                        throw new RuntimeException('Facture manquante pour une inscription existante : '.$matricule);
                    }
                    continue;
                }
                $school->enroll(['student_id'=>$student->id, 'offering_id'=>$class->offering_id,
                    'cohort_id'=>$class->id, 'due_on'=>now()->toDateString(), 'idempotency_key'=>(string)Str::uuid()]);
                $counts['enrollments']++;
                $counts['invoices']++;
            }
            return $counts;
        });
        $this->info('Préscolaire — Classe A : '.json_encode($counts));
        return 0;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());
        return 1;
    }
});
