<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (!app()->environment('local') || !str_starts_with((string) getenv('SESSION_COOKIE'), 'madrassa-integration-teacher-')) {
    throw new RuntimeException('Dedicated disposable teacher integration environment required.');
}
if (($argv[1] ?? '') === 'disable') {
    DB::table('school.staff')->where('code', 'TEACHER-1')->update(['active'=>false]);
    echo json_encode(['disabled'=>true]); exit;
}
if (($argv[1] ?? '') === 'publish-exam') {
    DB::table('school.assessments')->where('kind', 'examen')->update(['published_at'=>now()]);
    echo json_encode(['published'=>true]); exit;
}
if (($argv[1] ?? '') === 'grades') {
    echo json_encode(DB::table('school.grades as g')->join('school.enrollments as e', 'e.id', '=', 'g.enrollment_id')
        ->join('school.students as s', 's.id', '=', 'e.student_id')->join('school.assessments as a', 'a.id', '=', 'g.assessment_id')
        ->orderBy('a.title')->orderBy('s.matricule')->get(['a.title','a.kind','s.matricule','g.status','g.score','g.reason'])); exit;
}
if (DB::table('school.deployment')->exists()) throw new RuntimeException('Refusing to overwrite existing school.');
echo json_encode(DB::transaction(function () {
    $tenant = (string) Str::uuid();
    DB::table('school.institutions')->insert(['id'=>$tenant,'name'=>'École de test','kind'=>'school']);
    DB::table('school.deployment')->insert(['id'=>1,'institution_id'=>$tenant]);
    $insert = function ($table, $data) use ($tenant) {
        $id = (string) Str::uuid();
        DB::table('school.'.$table)->insert(['id'=>$id,'tenant_id'=>$tenant,...$data]);
        return $id;
    };
    $campus = $insert('campuses', ['name'=>'Campus']);
    $period = $insert('periods', ['name'=>'2026/2027','starts_on'=>'2026-01-01','ends_on'=>'2027-12-31']);
    $program = $insert('programs', ['code'=>'PRI','name'=>'Primaire','kind'=>'school']);
    $level = $insert('levels', ['program_id'=>$program,'code'=>'1AP','name'=>'Première année']);
    $subject = $insert('subjects', ['code'=>'AR','name'=>'Arabe']);
    $otherSubject = $insert('subjects', ['code'=>'FR','name'=>'Français']);
    $insert('curriculum', ['level_id'=>$level,'subject_id'=>$subject]);
    $result = ['tenant'=>$tenant,'teachers'=>[]];
    foreach ([1,2] as $n) {
        $password = Str::random(32);
        $user = App\Models\User::create(['name'=>$n === 1 ? 'أمينة بن صالح' : 'خديجة بوزيد','email'=>"teacher$n@example.test",'password'=>$password]);
        DB::table('school.memberships')->insert(['tenant_id'=>$tenant,'user_id'=>$user->id,'permissions'=>'[]']);
        DB::select("select set_config('app.user_id', ?, true)", [(string) $user->id]);
        DB::select("select set_config('app.tenant_id', ?, true)", [$tenant]);
        $teacher = $insert('staff', ['code'=>"TEACHER-$n",'name'=>$user->name,'user_id'=>$user->id,'is_teacher'=>true]);
        $offer = $insert('offerings', ['name'=>"Offre $n",'campus_id'=>$campus,'period_id'=>$period,'level_id'=>$level,'price_minor'=>100]);
        $cohort = $insert('cohorts', ['name'=>"Classe $n",'offering_id'=>$offer,'capacity'=>25]);
        $assignment = $insert('teacher_assignments', ['cohort_id'=>$cohort,'subject_id'=>$subject,'teacher_id'=>$teacher]);
        $student = $insert('students', ['matricule'=>"STUDENT-$n",'first_name'=>"Prenom$n",'last_name'=>"Nom$n",'phone'=>'PRIVATE-PHONE']);
        $enrollment = $insert('enrollments', ['student_id'=>$student,'cohort_id'=>$cohort,'offering_id'=>$offer,'price_minor'=>100,'idempotency_key'=>(string) Str::uuid()]);
        if ($n === 1) {
            $extra = $insert('students', ['matricule'=>'STUDENT-1B','first_name'=>'PrenomB','last_name'=>'NomB']);
            $result['extraEnrollment'] = $insert('enrollments', ['student_id'=>$extra,'cohort_id'=>$cohort,'offering_id'=>$offer,'price_minor'=>100,'idempotency_key'=>(string) Str::uuid()]);
            $cancelled = $insert('students', ['matricule'=>'CANCELLED','first_name'=>'Cancelled','last_name'=>'Cancelled']);
            $result['cancelledEnrollment'] = $insert('enrollments', ['student_id'=>$cancelled,'cohort_id'=>$cohort,'offering_id'=>$offer,'price_minor'=>100,'status'=>'cancelled','idempotency_key'=>(string) Str::uuid()]);
            $result['otherSubjectAssessment'] = $insert('assessments', ['cohort_id'=>$cohort,'subject_id'=>$otherSubject,'title'=>'Private French exam','kind'=>'examen','held_on'=>now()->toDateString()]);
        }
        $assessment = $insert('assessments', ['cohort_id'=>$cohort,'subject_id'=>$subject,'title'=>"Assessment $n",'held_on'=>now()->toDateString()]);
        $room = $insert('rooms', ['campus_id'=>$campus,'name'=>"Salle $n",'capacity'=>25]);
        $insert('sessions', ['cohort_id'=>$cohort,'teacher_id'=>$teacher,'subject_id'=>$subject,'room_id'=>$room,
            'starts_at'=>now('Africa/Algiers')->startOfDay()->addHours(8)->toIso8601String(),
            'ends_at'=>now('Africa/Algiers')->startOfDay()->addHours(9)->toIso8601String()]);
        $result['teachers'][] = ['email'=>$user->email,'password'=>$password,'cohort'=>$cohort,'assignment'=>$assignment,'enrollment'=>$enrollment,'assessment'=>$assessment];
    }
    return $result;
}));
