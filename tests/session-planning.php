<?php
// Runs against PostgreSQL inside an outer transaction; all fixtures roll back.
require '/var/www/html/vendor/autoload.php';
require '/workspace/madrassa-backend/app/School/Http/TenantContext.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
DB::beginTransaction();
try {
    $tenant = App\School\Services\DeploymentSchool::id();
    $tag = (string) Str::uuid();
    $user = App\Models\User::create(['name'=>'Session regression','email'=>$tag.'@example.test','password'=>Str::random(32)]);
    DB::table('school.memberships')->insert(['tenant_id'=>$tenant,'user_id'=>$user->id,'permissions'=>'["pedagogy.write"]']);
    DB::select("select set_config('app.user_id', ?, true), set_config('app.tenant_id', ?, true)", [(string)$user->id,$tenant]);
    $insert = function ($table, $data) use ($tenant) {
        $id = (string) Str::uuid();
        DB::table('school.'.$table)->insert(['id'=>$id,'tenant_id'=>$tenant,...$data]);
        return $id;
    };
    $campus = $insert('campuses',['name'=>$tag]);
    $other = $insert('campuses',['name'=>$tag.' other']);
    $program = $insert('programs',['code'=>$tag,'name'=>$tag,'kind'=>'school']);
    $level = $insert('levels',['program_id'=>$program,'code'=>$tag,'name'=>$tag]);
    $period = $insert('periods',['name'=>$tag,'starts_on'=>'2026-01-01','ends_on'=>'2026-12-31']);
    $offering = $insert('offerings',['name'=>$tag,'level_id'=>$level,'period_id'=>$period,'campus_id'=>$campus,'price_minor'=>0]);
    $cohort = $insert('cohorts',['name'=>$tag,'offering_id'=>$offering,'capacity'=>25]);
    $subject = $insert('subjects',['code'=>$tag,'name'=>$tag]);
    $teacher = $insert('staff',['code'=>$tag,'name'=>$tag,'is_teacher'=>true]);
    $small = $insert('rooms',['campus_id'=>$campus,'name'=>'Small','capacity'=>24]);
    $foreign = $insert('rooms',['campus_id'=>$other,'name'=>'Other','capacity'=>30]);
    $valid = $insert('rooms',['campus_id'=>$campus,'name'=>'Valid','capacity'=>25]);
    $request = Illuminate\Http\Request::create('/school-api/v1/tenants/'.$tenant.'/sessions','POST');
    $request->headers->set('Accept','application/json');
    $route = new Illuminate\Routing\Route('POST','school-api/v1/tenants/{tenant}/sessions', fn()=>null);
    $route->bind($request);
    $request->setRouteResolver(fn()=>$route);
    $app->instance('request',$request);
    $request->setUserResolver(fn()=>$user);
    $run = function ($room) use ($app,$request,$insert,$cohort,$subject,$teacher,$tag) {
        return (new Illuminate\Routing\Pipeline($app))->send($request)
            ->through([App\School\Http\TenantContext::class])->then(function () use ($insert,$cohort,$subject,$teacher,$room,$tag) {
                $insert('students',['matricule'=>$tag,'first_name'=>'Rollback','last_name'=>'Probe']);
                $insert('sessions',['cohort_id'=>$cohort,'subject_id'=>$subject,'teacher_id'=>$teacher,'room_id'=>$room,
                    'starts_at'=>'2026-10-01T08:00:00+01:00','ends_at'=>'2026-10-01T09:00:00+01:00']);
                return response()->json(['ok'=>true],201);
            });
    };
    foreach ([$small,$foreign] as $room) {
        $response = $run($room);
        check($response->getStatusCode() === 409, 'Invalid room must return 409; got '.$response->getStatusCode().' '.($response->exception?->getMessage() ?? $response->getContent()));
        check($response->getData(true)['code'] === 'invalid_room', 'Expected safe room error');
        check(!str_contains($response->getContent(),'SQL'), 'SQL leaked');
        check(!DB::table('school.students')->where('matricule',$tag)->exists(), 'Partial write was not rolled back');
    }
    check($run($valid)->getStatusCode() === 201, 'Compatible room must succeed');
    check(DB::table('school.sessions')->where('cohort_id',$cohort)->count() === 1, 'Expected exactly one session');
    echo "PASS: capacity, campus, HTTP conflict, rollback, valid session\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    $failed = true;
} finally {
    DB::rollBack();
}
if ($failed ?? false) exit(1);
