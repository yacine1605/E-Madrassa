<?php
require '/var/www/html/vendor/autoload.php';
$source = getenv('SCHOOL_SOURCE') ?: '/workspace/madrassa-backend';
foreach (['Services/Curriculum','Services/TeacherEligibility','Http/SchoolController'] as $file) require $source.'/app/School/'.$file.'.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['school_backend'=>require $source.'/config/school_backend.php']);
config(['app.key'=>'base64:'.base64_encode(random_bytes(32))]);
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
function verify($condition,$message) { if (!$condition) throw new RuntimeException($message); }
DB::beginTransaction();
try {
    if (!Illuminate\Support\Facades\Schema::hasTable('school.teacher_qualifications')) {
        (require $source.'/database/migrations/2026_10_02_000001_create_teacher_qualifications.php')->up();
    }
    $tenant = App\School\Services\DeploymentSchool::id();
    $user = App\Models\User::create(['name'=>'Teacher eligibility test','email'=>Str::uuid().'@example.test','password'=>Str::random(32)]);
    DB::table('school.memberships')->insert(['tenant_id'=>$tenant,'user_id'=>$user->id,'permissions'=>'["catalog.write","hr.read","hr.write","pedagogy.write"]']);
    DB::select("select set_config('app.user_id', ?, true), set_config('app.tenant_id', ?, true)", [(string)$user->id,$tenant]);
    $insert = function($table,$data) use($tenant) {
        $id=(string)Str::uuid(); DB::table('school.'.$table)->insert(['id'=>$id,'tenant_id'=>$tenant,...$data]); return $id;
    };
    $tag=(string)Str::uuid();
    $program=$insert('programs',['code'=>$tag,'name'=>$tag,'kind'=>'school']);
    $level=$insert('levels',['program_id'=>$program,'code'=>'L1','name'=>'L1']);
    $otherLevel=$insert('levels',['program_id'=>$program,'code'=>'L2','name'=>'L2']);
    $subject=$insert('subjects',['code'=>$tag,'name'=>$tag]);
    $otherSubject=$insert('subjects',['code'=>$tag.'x','name'=>'Other']);
    $campus=$insert('campuses',['name'=>$tag]);
    $period=$insert('periods',['name'=>$tag,'starts_on'=>'2026-01-01','ends_on'=>'2026-12-31']);
    $offering=$insert('offerings',['name'=>$tag,'level_id'=>$level,'period_id'=>$period,'campus_id'=>$campus,'price_minor'=>0]);
    $cohort=$insert('cohorts',['name'=>$tag,'offering_id'=>$offering,'capacity'=>20]);
    $insert('curriculum',['level_id'=>$level,'subject_id'=>$subject,'coefficient'=>1]);
    $teachers=[];
    foreach (['valid','level','subject','inactive','nonteacher','unconfigured'] as $kind) {
        $teachers[$kind]=$insert('staff',['code'=>Str::uuid(),'name'=>$kind,'is_teacher'=>$kind!=='nonteacher','active'=>$kind!=='inactive']);
        if ($kind !== 'unconfigured') $insert('teacher_qualifications',['teacher_id'=>$teachers[$kind],'level_id'=>$kind==='level'?$otherLevel:$level,'subject_id'=>$kind==='subject'?$otherSubject:$subject]);
    }
    $request=Illuminate\Http\Request::create('/','GET',['cohort_id'=>$cohort,'subject_id'=>$subject]);
    $request->attributes->set('school.tenant',$tenant);
    $request->attributes->set('school.permissions',['hr.read','hr.write','pedagogy.write']);
    $app->instance('request',$request);
    $controller=$app->make(App\School\Http\SchoolController::class);
    $rows=$controller->index($request,$tenant,'staff')->getData(true);
    verify($rows['total']===1 && $rows['data'][0]['id']===$teachers['valid'],'Filtering must require exact subject, level, active teacher');
    foreach (['sessions','teacher_assignments'] as $resource) {
        foreach ($teachers as $kind=>$teacher) {
            if ($kind==='valid') continue;
            $data=['cohort_id'=>$cohort,'subject_id'=>$subject,'teacher_id'=>$teacher];
            if ($resource==='sessions') $data+=['room_id'=>(string)Str::uuid(),'starts_at'=>'2026-10-01T08:00:00+01:00','ends_at'=>'2026-10-01T09:00:00+01:00'];
            $write=Illuminate\Http\Request::create('/','POST',$data);
            try { $controller->create($write,$tenant,$resource); throw new RuntimeException('Invalid teacher accepted: '.$kind); }
            catch (Illuminate\Validation\ValidationException $e) { verify(isset($e->errors()['teacher_id']),'Expected teacher validation'); }
        }
    }
    $write=Illuminate\Http\Request::create('/','POST',['cohort_id'=>$cohort,'subject_id'=>$subject,'teacher_id'=>$teachers['valid']]);
    verify($controller->create($write,$tenant,'teacher_assignments')->getStatusCode()===201,'Valid assignment rejected');
    $room=$insert('rooms',['campus_id'=>$campus,'name'=>$tag,'capacity'=>20]);
    $write=Illuminate\Http\Request::create('/','POST',['cohort_id'=>$cohort,'subject_id'=>$subject,'teacher_id'=>$teachers['valid'],
        'room_id'=>$room,'starts_at'=>'2026-10-01T08:00:00+01:00','ends_at'=>'2026-10-01T09:00:00+01:00']);
    verify($controller->create($write,$tenant,'sessions')->getStatusCode()===201,'Valid session rejected');
    verify(App\School\Services\TeacherEligibility::teachers((string)Str::uuid(),$level,$subject)->count()===0,'Tenant isolation failed');

    $primary = DB::table('school.programs')->where('tenant_id',$tenant)->where('code','PRIMAIRE')->value('id')
        ?? $insert('programs',['code'=>'PRIMAIRE','name'=>'Primaire','kind'=>'school']);
    $primaryLevel = $insert('levels',['program_id'=>$primary,'code'=>$tag,'name'=>'Primary test']);
    $primaryOffering = $insert('offerings',['name'=>$tag.' primary','level_id'=>$primaryLevel,'period_id'=>$period,'campus_id'=>$campus,'price_minor'=>0]);
    $primaryClass = $insert('cohorts',['name'=>$tag.' primary','offering_id'=>$primaryOffering,'capacity'=>20]);
    $subjects = [];
    foreach (['PRI-AR-LECT','PRI-MATH','PRI-ISLAM','PRI-ART','PRI-CIV','PRI-HG','PRI-HIST','PRI-SCI-TECH','PRI-AMAZ','PRI-ATELIER','PRI-FR','PRI-EN','PRI-ES','PRI-SPORT'] as $code) {
        $subjects[$code] = DB::table('school.subjects')->where('tenant_id',$tenant)->where('code',$code)->value('id')
            ?? $insert('subjects',['code'=>$code,'name'=>$code]);
        $insert('curriculum',['level_id'=>$primaryLevel,'subject_id'=>$subjects[$code],'coefficient'=>1]);
    }
    $generalists = [];
    foreach (['valid','level','inactive','nonteacher'] as $kind) {
        $generalists[$kind] = $insert('staff',['code'=>Str::uuid(),'name'=>'Generalist '.$kind,'is_teacher'=>$kind!=='nonteacher','active'=>$kind!=='inactive']);
        $insert('teacher_qualifications',['teacher_id'=>$generalists[$kind],'level_id'=>$kind==='level'?$otherLevel:$primaryLevel,'subject_id'=>$subjects['PRI-AR-LECT']]);
    }
    $specialists = ['PRI-FR','PRI-EN','PRI-ES','PRI-SPORT'];
    foreach ($subjects as $code=>$id) {
        $request = Illuminate\Http\Request::create('/','GET',['cohort_id'=>$primaryClass,'subject_id'=>$id]);
        $rows = $controller->index($request,$tenant,'staff')->getData(true)['data'];
        $ids = array_column($rows,'id');
        verify($ids === (in_array($code,$specialists,true) ? [] : [$generalists['valid']]),'Incorrect primary teacher choices: '.$code);
        if (in_array($code,$specialists,true)) {
            foreach (['sessions','teacher_assignments'] as $resource) {
                $data = ['cohort_id'=>$primaryClass,'subject_id'=>$id,'teacher_id'=>$generalists['valid']];
                if ($resource==='sessions') $data += ['room_id'=>$room,'starts_at'=>'2026-10-02T08:00:00+01:00','ends_at'=>'2026-10-02T09:00:00+01:00'];
                try { $controller->create(Illuminate\Http\Request::create('/','POST',$data),$tenant,$resource); throw new RuntimeException('Specialist subject accepted: '.$code); }
                catch (Illuminate\Validation\ValidationException $e) { verify(isset($e->errors()['teacher_id']),'Expected specialist teacher validation'); }
            }
        } else {
            $write = Illuminate\Http\Request::create('/','POST',['cohort_id'=>$primaryClass,'subject_id'=>$id,'teacher_id'=>$generalists['valid']]);
            verify($controller->create($write,$tenant,'teacher_assignments')->getStatusCode()===201,'Generalist assignment rejected: '.$code);
        }
    }
    $write = Illuminate\Http\Request::create('/','POST',['cohort_id'=>$primaryClass,'subject_id'=>$subjects['PRI-MATH'],'teacher_id'=>$generalists['valid'],
        'room_id'=>$room,'starts_at'=>'2026-10-02T08:00:00+01:00','ends_at'=>'2026-10-02T09:00:00+01:00']);
    verify($controller->create($write,$tenant,'sessions')->getStatusCode()===201,'Generalist math session rejected');
    $eligible = fn($testLevel,$testSubject,$teacher) => App\School\Services\TeacherEligibility::teachers($tenant,$testLevel,$testSubject)->where('id',$teacher)->exists();
    $insert('curriculum',['level_id'=>$otherLevel,'subject_id'=>$subjects['PRI-MATH'],'coefficient'=>1]);
    verify(!$eligible($otherLevel,$subjects['PRI-MATH'],$generalists['level']),'Generalist rule leaked outside primary');
    verify(!$eligible($primaryLevel,$subject,$generalists['valid']),'Generalist rule leaked outside curriculum');
    verify(!$eligible(null,$subjects['PRI-MATH'],$generalists['valid']),'Generalist rule accepted unknown level');
    verify(App\School\Services\TeacherEligibility::teachers((string)Str::uuid(),$primaryLevel,$subjects['PRI-MATH'])->count()===0,'Generalist tenant isolation failed');
    foreach ($specialists as $code) {
        $insert('teacher_qualifications',['teacher_id'=>$teachers['valid'],'level_id'=>$primaryLevel,'subject_id'=>$subjects[$code]]);
        verify($eligible($primaryLevel,$subjects[$code],$teachers['valid']),'Explicit specialist qualification rejected: '.$code);
        verify(!$eligible($primaryLevel,$subjects['PRI-MATH'],$teachers['valid']),'Specialist inherited generalist subjects');
    }
    echo "PASS: exact qualifications, primary Arabic generalists, four specialist exclusions, level/curriculum/tenant isolation, inactive/nonteacher exclusion, assignment and session validation\n";
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n"); $failed=true; }
finally { DB::rollBack(); }
if ($failed??false) exit(1);
