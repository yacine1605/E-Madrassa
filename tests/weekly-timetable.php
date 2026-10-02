<?php
require '/var/www/html/vendor/autoload.php';
if ($source=getenv('SCHOOL_SOURCE')) {
    foreach (['WeeklyTeachingPlan','WeeklyTimetable'] as $service) require_once $source.'/app/School/Services/'.$service.'.php';
}
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($source=getenv('SCHOOL_SOURCE')) config(['algerian_curriculum'=>require $source.'/config/algerian_curriculum.php']);
use App\School\Services\WeeklyTeachingPlan;
use App\School\Services\WeeklyTimetable;
use Illuminate\Support\Facades\DB;
function checkWeekly($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$plan=json_decode(file_get_contents('/tmp/private_timetable.json'),true,512,JSON_THROW_ON_ERROR);
WeeklyTimetable::validate($plan);
$reference=config('algerian_curriculum.weekly_reference');
checkWeekly(array_map('array_sum',$reference['primary'])===[1=>1260,2=>1260,3=>1260,4=>1350,5=>1395],'Incorrect detailed totals');
checkWeekly($reference['reported_totals_minutes'][4]!==array_sum($reference['primary'][4]),'Grade 4 discrepancy must remain explicit');
foreach (range(1,5) as $grade) {
    $school=WeeklyTeachingPlan::minutes('PRIMAIRE',(string)$grade);
    $support=WeeklyTeachingPlan::supportMinutes('PRIMAIRE',(string)$grade);
    checkWeekly(array_sum($school)===1800 && $school['PRI-ES']===60,'School hours/Spanish mismatch');
    checkWeekly($support['PRI-EN']===120 && !isset($school['PRI-ATELIER']),'Primary support distribution mismatch');
    foreach ($reference['primary'][$grade] as $code=>$minutes) checkWeekly($school[$code]-($support[$code]??0)===$minutes,'Core volume overwritten');
}
checkWeekly(WeeklyTeachingPlan::supportMinutes('PRESCOLAIRE','1')===['PRI-EN'=>120,'PRI-FR'=>60],'Preschool support distribution mismatch');
foreach (['overlap','rounding','lunch','tuesday_late','recess','recess_total','wrong_teacher','support_volume','support_activity'] as $case) {
    $bad=$plan;
    if ($case==='overlap') $bad['sessions'][]=$bad['sessions'][0];
    if ($case==='rounding') $bad['sessions'][0]['minutes']+=15;
    if ($case==='lunch') { $bad['sessions'][0]['day']=0; $bad['sessions'][0]['start']='12:00'; $bad['sessions'][0]['end']='12:30'; $bad['sessions'][0]['minutes']=30; }
    if ($case==='tuesday_late') { $bad['sessions'][0]['day']=2; $bad['sessions'][0]['start']='12:30'; $bad['sessions'][0]['end']='13:00'; $bad['sessions'][0]['minutes']=30; }
    if ($case==='recess') $bad['recess']['minutes']=30;
    if ($case==='recess_total') $bad['weekly_minutes']-=5*30;
    if ($case==='wrong_teacher') $bad['lessons'][0]['teacher']='invalid';
    if ($case==='support_volume') $bad['lessons'][0]['support_minutes']=120;
    if ($case==='support_activity') $bad['sessions'][0]['activity']='unknown';
    $rejected=false;
    try { WeeklyTimetable::validate($bad); } catch (RuntimeException $e) { $rejected=true; }
    checkWeekly($rejected,'Invalid timetable accepted: '.$case);
}
foreach ($plan['classes'] as $class) {
    foreach (range(0,4) as $day) {
        $morningEnd = $day===2?'12:30':'12:00';
        $morning = array_filter($plan['sessions'], fn($s) => $s['class_code']===$class && $s['day']===$day && $s['start']<$morningEnd);
        checkWeekly(array_sum(array_column($morning,'minutes'))===($day===2?240:210),'Incorrect morning coverage');
        checkWeekly(max(array_column($morning,'end'))===$morningEnd,'Incorrect end of morning');
        checkWeekly(count(array_filter($morning,fn($s)=>$s['start']==='10:30'))===1,'Classes must resume at the zero-minute marker');
    }
    $support=array_filter($plan['sessions'],fn($s)=>$s['class_code']===$class && $s['activity']==='support');
    checkWeekly(array_sum(array_column(array_filter($support,fn($s)=>$s['subject']==='PRI-EN'),'minutes'))===120,'Every class needs exactly two English support hours');
    foreach ($support as $s) {
        $adjacent=array_filter($plan['sessions'],fn($c)=>$c['activity']==='course' && $c['class_code']===$class && $c['subject']===$s['subject'] && $c['teacher']===$s['teacher'] && $c['day']===$s['day'] && ($c['end']===$s['start'] || $c['start']===$s['end']));
        checkWeekly(count($adjacent)>0,'Language support is not next to its course');
    }
}
DB::beginTransaction();
try {
    $tenant=App\School\Services\DeploymentSchool::id();
    $actor=DB::table('school.memberships')->where('tenant_id',$tenant)->where('active',true)->get()
        ->first(fn($m)=>in_array('catalog.write',json_decode($m->permissions,true),true));
    DB::select("select set_config('app.user_id',?,true),set_config('app.tenant_id',?,true)",[(string)$actor->user_id,$tenant]);
    $sync=new App\School\Services\AlgerianCurriculum;
    $sync->sync($tenant);
    checkWeekly(array_sum($sync->sync($tenant))===0,'Sync is not idempotent');
    foreach (['1AP'=>['PRI-ISLAM'=>1.5,'PRI-ART'=>1.5,'PRI-FR'=>4,'PRI-EN'=>4],
              '3AP'=>['PRI-HIST'=>.5,'PRI-AR-LECT'=>7.5,'PRI-FR'=>6,'PRI-EN'=>4],
              '4AP'=>['PRI-AR-LECT'=>6.5,'PRI-FR'=>6.5,'PRI-EN'=>4],
              '5AP'=>['PRI-CIV'=>.75,'PRI-ART'=>.75,'PRI-SPORT'=>1.75,'PRI-EN'=>3.5,'PRI-FR'=>6.75]] as $grade=>$subjects) {
        foreach ($subjects as $code=>$expected) {
            $value=DB::table('school.curriculum as c')->join('school.levels as l','l.id','=','c.level_id')
                ->join('school.subjects as s','s.id','=','c.subject_id')->where('c.tenant_id',$tenant)->where('l.code',$grade)->where('s.code',$code)->value('c.hours');
            checkWeekly($value!==null && (float)$value===(float)$expected,'Wrong stored hours: '.$grade.' '.$code);
        }
    }
    echo "PASS: base volumes preserved; 2h English support per class and remaining support in French; all workshops next to same-language courses; PostgreSQL totals; zero-minute recess; Tuesday until 12:30; collision and invalid time rejection\n";
} finally { DB::rollBack(); }
