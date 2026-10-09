<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use App\School\Services\SchoolService;
if (!app()->environment('local') || !str_starts_with((string)getenv('SESSION_COOKIE'),'madrassa-integration-archives-')) throw new RuntimeException('Disposable archive test installation required');
$user = App\Models\User::where('email','browser-integration@example.test')->firstOrFail();
$tenant = DB::table('school.deployment')->value('institution_id');
$permissions = json_decode(DB::table('school.memberships')->where('tenant_id',$tenant)->where('user_id',$user->id)->value('permissions'),true);
request()->attributes->set('school.tenant',$tenant);
request()->attributes->set('school.permissions',$permissions);
request()->setUserResolver(fn()=>$user);
$action = $argv[1] ?? 'seed';
$result = DB::transaction(function() use ($action,$tenant,$user) {
    DB::select("select set_config('app.tenant_id', ?, true), set_config('app.user_id', ?, true)",[$tenant,(string)$user->id]);
    $s = new SchoolService();
    if ($action === 'seed') {
        $campus = $s->insert('campuses',['name'=>'Archive campus']);
        $period = $s->insert('periods',['name'=>'2026/2027','starts_on'=>'2026-09-01','ends_on'=>'2027-06-30']);
        $program = $s->insert('programs',['code'=>'MOYEN','name'=>'Moyen','kind'=>'school']);
        $level = $s->insert('levels',['program_id'=>$program->id,'code'=>'4AM','name'=>'4AM']);
        $math = $s->insert('subjects',['code'=>'MOY-MATH','name'=>'الرياضيات']);
        $art = $s->insert('subjects',['code'=>'MOY-ART','name'=>'الفنون']);
        foreach([$math,$art] as $subject) $s->insert('curriculum',['level_id'=>$level->id,'subject_id'=>$subject->id,'coefficient'=>1]);
        $offer = $s->insert('offerings',['level_id'=>$level->id,'period_id'=>$period->id,'campus_id'=>$campus->id,'name'=>'4AM','price_minor'=>10000]);
        $cohort = $s->insert('cohorts',['offering_id'=>$offer->id,'name'=>'Classe Archives','capacity'=>100]);
        $empty = $s->insert('cohorts',['offering_id'=>$offer->id,'name'=>'Classe vide','capacity'=>100]);
        $rules = [$math->id=>['coefficient'=>4,'continu'=>0,'devoir'=>0,'examen'=>1,'qualitative'=>false],
            $art->id=>['coefficient'=>1,'continu'=>0,'devoir'=>0,'examen'=>0,'qualitative'=>true]];
        $terms = []; $exams = [];
        foreach([1=>['2026-09-01','2026-12-31','2026-10-01'],2=>['2027-01-01','2027-03-31','2027-02-01'],3=>['2027-04-01','2027-06-30','2027-05-01']] as $number=>$dates) {
            $terms[$number] = $s->insert('report_card_terms',['level_id'=>$level->id,'period_id'=>$period->id,'trimester'=>$number,
                'starts_on'=>$dates[0],'ends_on'=>$dates[1],'scale'=>20,'reference'=>'Test : composition seule, pas une règle nationale',
                'rules'=>json_encode($rules),'created_by'=>$user->id])->id;
            foreach([0,1] as $index) $exams[$number][$index] = $s->insert('assessments',['cohort_id'=>$cohort->id,'subject_id'=>$math->id,
                'kind'=>'examen','title'=>'Composition '.$number.'-'.$index,'max_score'=>20,'coefficient'=>$index ? ($number===3 ? 7 : 2) : 3,
                'held_on'=>$dates[2]])->id;
        }
        $pupils = [];
        for($i=1;$i<=52;$i++) {
            $student = $s->insert('students',['matricule'=>sprintf('ARC-%03d',$i),'first_name'=>'Élève','last_name'=>sprintf('Archive %03d',$i),'birth_date'=>'2011-02-03']);
            $enrollment = $s->enroll(['student_id'=>$student->id,'offering_id'=>$offer->id,'cohort_id'=>$cohort->id,
                'due_on'=>'2026-10-01','idempotency_key'=>(string)Str::uuid()]);
            $pupils[] = ['student'=>$student->id,'enrollment'=>$enrollment->id];
            foreach([1=>14,2=>16,3=>18] as $number=>$base) {
                foreach($exams[$number] as $index=>$exam) {
                    $missing = $i===52 && $number===1 && $index===0;
                    $s->insert('grades',['assessment_id'=>$exam,'enrollment_id'=>$enrollment->id,'status'=>$missing ? 'missing' : 'marked',
                        'score'=>$missing ? null : $base+($i===1 && $index===1 ? 0.01 : 0)]);
                }
                $s->insert('report_card_observations',['term_id'=>$terms[$number],'enrollment_id'=>$enrollment->id,'subject_id'=>$art->id,'observation'=>'Participation satisfaisante']);
            }
        }
        foreach($exams as $group) foreach($group as $exam) $s->query('assessments')->where('id',$exam)->update(['published_at'=>now()]);
        return ['cohort'=>$cohort->id,'empty'=>$empty->id,'period'=>$period->id,'math'=>$math->id,'art'=>$art->id,'terms'=>$terms,'exams'=>$exams,'pupils'=>$pupils];
    }
    if ($action === 'unpublish') {
        // Simulate an administrator's later source correction in this isolated test only.
        $s->row('assessments',$GLOBALS['argv'][2]);
        $s->query('assessments')->where('id',$GLOBALS['argv'][2])->update(['published_at'=>null]);
        return ['ok'=>true];
    }
    if ($action === 'read-only' || $action === 'no-students') {
        DB::table('school.memberships')->where('tenant_id',$tenant)->where('user_id',$user->id)
            ->update(['permissions'=>json_encode($action === 'read-only' ? ['catalog.read','students.read','pedagogy.read'] : ['pedagogy.read'])]);
        return ['ok'=>true];
    }
    if ($action === 'verify-immutability') {
        $row = $s->query('report_card_archives')->where('status','closed')->firstOrFail();
        $blocked = 0;
        foreach(['update','delete'] as $operation) {
            try {
                DB::transaction(function() use ($s,$row,$operation) {
                    $q = $s->query('report_card_archives')->where('id',$row->id);
                    if ($operation==='update') $q->update(['status'=>'draft']); else $q->delete();
                });
                throw new RuntimeException('Closed archive was modified');
            } catch (Illuminate\Database\QueryException $error) {
                if ($error->getCode() !== '23514') throw $error;
                $blocked++;
            }
        }
        return ['blocked'=>$blocked,'closed'=>$s->query('report_card_archives')->where('status','closed')->count(),
            'invalid_dates'=>$s->query('report_card_archives')->where('status','closed')->whereColumn('validated_at','>','closed_at')->count(),
            'events'=>$s->query('audit_events')->where('entity','report_card_archives')->count()];
    }
    if ($action === 'mutate-sources') {
        $student = $s->query('students')->where('matricule','ARC-001')->firstOrFail();
        $s->query('students')->where('id',$student->id)->update(['last_name'=>'NOM MODIFIÉ']);
        $s->query('enrollments')->where('student_id',$student->id)->update(['status'=>'cancelled']);
        $s->query('subjects')->update(['name'=>'MATIÈRE MODIFIÉE']);
        $s->query('curriculum')->update(['coefficient'=>99]);
        DB::table('school.institutions')->where('id',$tenant)->update(['name'=>'ÉCOLE MODIFIÉE']);
        file_put_contents(public_path('css/documents.css'),'body { color: red } /* MODIFIED STYLES */');
        foreach(['term-card','annual-card'] as $view) file_put_contents(resource_path('views/documents/partials/'.$view.'.blade.php'),'<article class="sheet">MODIFIED TEMPLATE</article>');
        Artisan::call('view:clear');
        return ['ok'=>true];
    }
    throw new RuntimeException('Unknown fixture action');
});
echo json_encode($result,JSON_THROW_ON_ERROR);
