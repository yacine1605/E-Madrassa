<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Isolated, disposable records for the browser integration test only.
$email = 'browser-integration@example.test';
if (!app()->environment('local')) throw new RuntimeException('Local test environment required');
if (!str_starts_with((string) getenv('SESSION_COOKIE'), 'madrassa-integration-')) throw new RuntimeException('Use the isolated integration runner, never the user database');
if (($argv[1] ?? '') === 'verify-binding') {
    DB::beginTransaction();
    try {
        $bound = App\School\Services\DeploymentSchool::id();
        $count = DB::table('school.institutions')->count();
        $extraUser = App\Models\User::create(['name'=>'Second administrator','email'=>'second-admin@example.test','password'=>Str::random(32)]);
        $status = Illuminate\Support\Facades\Artisan::call('school:provision', ['email'=>$extraUser->email]);
        if ($status !== 0 || DB::table('school.institutions')->count() !== $count || !DB::table('school.memberships')->where('user_id', $extraUser->id)->where('tenant_id', $bound)->exists()) throw new RuntimeException('Provisioning created a second school');
        $foreign = (string) Str::uuid();
        DB::table('school.institutions')->insert(['id'=>$foreign,'name'=>'Foreign school fixture','kind'=>'school']);
        DB::table('school.memberships')->insert(['tenant_id'=>$foreign,'user_id'=>$extraUser->id,'permissions'=>'["students.read"]']);
        $request = Illuminate\Http\Request::create('/v1/tenants/'.$foreign);
        $route = new Illuminate\Routing\Route('GET', 'v1/tenants/{tenant}', fn()=>null);
        $route->bind($request);
        $request->setRouteResolver(fn()=>$route);
        $request->setUserResolver(fn()=>$extraUser);
        try {
            (new App\School\Http\TenantContext)->handle($request, fn()=>throw new RuntimeException('Foreign school accepted'));
            throw new RuntimeException('Foreign school accepted');
        } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() !== 403) throw $e;
        }
        DB::table('school.memberships')->where('user_id',$extraUser->id)->where('tenant_id',$bound)->update(['active'=>false]);
        if ($extraUser->canAccessPanel(Filament\Facades\Filament::getPanel('admin'))) throw new RuntimeException('Foreign membership allowed panel login');
        echo json_encode(['verified'=>true]);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage()); exit(1);
    } finally { DB::rollBack(); }
    exit;
}
if (($argv[1] ?? '') === 'grant') {
    $user = DB::table('users')->where('email', $email)->first();
    if (!$user) throw new RuntimeException('Missing fixture');
    $permissions = ['catalog.read','catalog.write','students.read','students.write','enrollments.read','enrollments.write','pedagogy.read','pedagogy.write','finance.read','finance.write','hr.read','hr.write','payroll.read','payroll.write','audit.read'];
    DB::table('school.memberships')->where('user_id', $user->id)->update(['permissions'=>json_encode($permissions)]);
    echo json_encode(['granted'=>true]); exit;
}
if (($argv[1] ?? '') === 'cleanup') {
    DB::transaction(function () use ($email) {
        $user = DB::table('users')->where('email', $email)->first();
        if (!$user) return;
        $tenants = DB::table('school.memberships')->where('user_id', $user->id)->pluck('tenant_id');
        foreach ($tenants as $tenant) {
            $institution = DB::table('school.institutions')->where('id', $tenant)->first();
            if ($institution->name !== 'Browser integration fixture') throw new RuntimeException('Unexpected fixture owner');
            if (DB::table('school.memberships')->where('tenant_id', $tenant)->count() !== 1) throw new RuntimeException('Fixture is shared; refusing cleanup');
            // Only this transaction bypasses immutable audit triggers to remove the checked test fixture.
            DB::statement("SET LOCAL session_replication_role = 'replica'");
            DB::table('school.deployment')->where('institution_id', $tenant)->delete();
            foreach (['attendance','grades','sessions','assessments','lesson_versions','disbursements','expenses','payroll_runs','work_logs','contracts','refunds','payments','invoices','enrollments','cohorts','offerings','curriculum','subjects','levels','programs','periods','rooms','campuses','student_guardians','guardians','students','staff','announcements','audit_events','outbox','memberships','institutions'] as $table) {
                DB::table('school.'.$table)->where($table === 'institutions' ? 'id' : 'tenant_id', $tenant)->delete();
            }
        }
        DB::table('users')->where('id', $user->id)->delete();
    });
    echo json_encode(['cleaned'=>true]); exit;
}
if (DB::table('users')->where('email', $email)->exists()) throw new RuntimeException('Fixture exists; clean it before retrying');
if (DB::table('school.deployment')->exists()) throw new RuntimeException('Deployment already belongs to a school; refusing test setup');
$password = Str::random(32);
$fixture = DB::transaction(function () use ($email, $password) {
    $user = App\Models\User::create(['name'=>'Browser integration','email'=>$email,'password'=>$password]);
    $tenant = (string) Str::uuid();
    DB::table('school.institutions')->insert(['id'=>$tenant,'name'=>'Browser integration fixture','kind'=>'school']);
    DB::table('school.deployment')->insert(['id'=>1,'institution_id'=>$tenant]);
    DB::table('school.memberships')->insert(['tenant_id'=>$tenant,'user_id'=>$user->id,'permissions'=>json_encode(['students.read','students.write','catalog.read'])]);
    return ['email'=>$email,'password'=>$password,'tenant'=>$tenant];
});
echo json_encode($fixture);
