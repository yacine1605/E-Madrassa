<?php
require '/var/www/html/vendor/autoload.php';
$source = getenv('SCHOOL_SOURCE') ?: '/workspace/madrassa-backend';
foreach (['Services/Curriculum','Services/WeeklyTeachingPlan','Services/AlgerianCurriculum','Http/SchoolController','Http/TenantContext'] as $file) require $source.'/app/School/'.$file.'.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['algerian_curriculum'=>require $source.'/config/algerian_curriculum.php']);
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
function verify($condition,$message) { if (!$condition) throw new RuntimeException($message); }
DB::beginTransaction();
try {
    $tenant = App\School\Services\DeploymentSchool::id();
    $user = App\Models\User::create(['name'=>'Curriculum test','email'=>Str::uuid().'@example.test','password'=>Str::random(32)]);
    DB::table('school.memberships')->insert(['tenant_id'=>$tenant,'user_id'=>$user->id,'permissions'=>'["catalog.read","catalog.write","pedagogy.write","hr.write"]']);
    DB::select("select set_config('app.user_id', ?, true), set_config('app.tenant_id', ?, true)",[(string)$user->id,$tenant]);
    $sync = new App\School\Services\AlgerianCurriculum;
    $changes = $sync->sync($tenant);
    verify(array_sum($sync->sync($tenant)) === 0,'Sync must be idempotent');
    $query = fn($table)=>DB::table('school.'.$table)->where('tenant_id',$tenant);
    $level = fn($code)=>$query('levels')->where('code',$code)->value('id');
    $subject = fn($code)=>$query('subjects')->where('code',$code)->value('id');
    $cohortFor = fn($id)=>DB::table('school.cohorts as c')->join('school.offerings as o','o.id','=','c.offering_id')
        ->where('c.tenant_id',$tenant)->where('o.level_id',$id)->value('c.id');
    verify($cohortFor($level('1AS-ST')) !== null && $cohortFor($level('1AS-L')) !== null,'Both secondary classes must be attached to their tracks');
    $run = function($method,$resource,$data) use($app,$tenant,$user) {
        $request = Illuminate\Http\Request::create('/school-api/v1/tenants/'.$tenant.'/'.$resource,$method,$data);
        $request->headers->set('Accept','application/json');
        $route = new Illuminate\Routing\Route($method,'school-api/v1/tenants/{tenant}/{resource}',fn()=>null);
        $route->bind($request); $request->setRouteResolver(fn()=>$route);
        $app->instance('request',$request); $request->setUserResolver(fn()=>$user);
        return (new Illuminate\Routing\Pipeline($app))->send($request)->through([App\School\Http\TenantContext::class])
            ->then(function($r) use($app,$method,$tenant,$resource) {
                $controller = $app->make(App\School\Http\SchoolController::class);
                $result = $method === 'GET' ? $controller->index($r,$tenant,$resource) : $controller->create($r,$tenant,$resource);
                return $result instanceof Symfony\Component\HttpFoundation\Response ? $result : response()->json($result);
            });
    };
    foreach (['1AP'=>8,'2AP'=>8,'3AP'=>10,'4AP'=>11,'5AP'=>11,'1AS-ST'=>13,'1AS-L'=>12] as $code=>$count) {
        $response = $run('GET','subjects',['level_id'=>$level($code),'limit'=>100]);
        verify($response->getStatusCode() === 200,'Subject endpoint failed');
        $rows = $response->getData(true)['data'];
        verify(count($rows) === $count,'Wrong subject count for '.$code);
        foreach ($rows as $row) verify(!preg_match('/[A-Za-z]/u',$row['name']),'Non-Arabic subject label');
        $codes = array_column($rows,'code');
        if (str_ends_with($code,'AP')) verify(in_array('PRI-FR',$codes) && in_array('PRI-EN',$codes) && in_array('PRI-ES',$codes),'Private school languages missing');
        if ($code === '3AP') verify(in_array('PRI-HIST',$codes) && !in_array('PRI-HG',$codes),'Third-year history must be separate');
        if ($code === '1AS-L') verify(!in_array('SEC-TECH',$codes),'Technology leaked to literature');
    }
    $cohort = $cohortFor($level('1AP'));
    verify($cohort !== null,'Expected primary fixture class');
    $response = $run('GET','subjects',['cohort_id'=>$cohort]);
    verify(count($response->getData(true)['data']) === 8,'Cohort subject filter failed');
    $response = $run('GET','subjects',['cohort_id'=>(string)Str::uuid()]);
    verify(count($response->getData(true)['data']) === 0,'Unknown/foreign cohort must not expose subjects');
    $payload = ['cohort_id'=>$cohort,'subject_id'=>$subject('MOY-AR'),'title'=>'Curriculum regression','held_on'=>'2026-10-01','max_score'=>20,'coefficient'=>1];
    $before = $query('assessments')->count();
    $response = $run('POST','assessments',$payload);
    verify($response->getStatusCode() === 422,'Wrong-level assessment must return 422');
    verify($query('assessments')->count() === $before,'Invalid assessment persisted');
    $payload['subject_id'] = $subject('PRI-AR-LECT');
    verify($run('POST','assessments',$payload)->getStatusCode() === 201,'Valid assessment rejected');
    foreach (['sessions','teacher_assignments'] as $resource) {
        $data = ['cohort_id'=>$cohort,'subject_id'=>$subject('MOY-AR'),'teacher_id'=>(string)Str::uuid()];
        if ($resource === 'sessions') $data += ['room_id'=>(string)Str::uuid(),'starts_at'=>'2026-10-01T08:00:00+01:00','ends_at'=>'2026-10-01T09:00:00+01:00'];
        $response = $run('POST',$resource,$data);
        verify($response->getStatusCode() === 422 && isset($response->getData(true)['errors']['subject_id']),$resource.' did not reject wrong-level subject');
    }
    echo 'PASS: Arabic catalogue, per-year lists, two tracks, API filtering, invalid writes, valid assessment, idempotence; simulation '.json_encode($changes)."\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\n"); $failed = true;
} finally { DB::rollBack(); }
if ($failed ?? false) exit(1);
