<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\School\Http\SchoolController as C;
use App\School\Http\TenantContext;

// Same-origin session authentication. CSRF is enabled on every mutation.
Route::middleware('web')->prefix('school-api')->group(function () {
    Route::get('csrf', fn () => ['csrf_token'=>csrf_token()]);
    Route::get('school', function (Request $r) {
        abort_unless($r->user(), 401);
        return DB::transaction(function () use ($r) {
            $member = \App\School\Services\DeploymentSchool::membership($r->user()->id);
            abort_unless($member, 403);
            DB::select("select set_config('app.tenant_id', ?, true)", [$member->tenant_id]);
            $institution = DB::table('school.institutions')->where('id', $member->tenant_id)->first(['id','name','kind']);
            abort_unless($institution, 403);
            $modules = app(\App\School\Modules\ModuleRegistry::class);
            return ['data'=>[...(array) $institution,
                'permissions'=>$modules->effectivePermissions(json_decode($member->permissions, true)),
                'modules'=>$modules->enabledNames(), 'profile'=>config('school_modules.profile')]];
        });
    })->middleware('throttle:school-api');
    Route::post('login', function (Request $r) {
        $v = $r->validate(['email'=>'required|email','password'=>'required|string']);
        abort_unless(Auth::attempt($v), 401, 'Identifiants incorrects.');
        $allowed = DB::transaction(fn () => \App\School\Services\DeploymentSchool::membership(Auth::id()));
        if (!$allowed) {
            Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();
            abort(403, 'Ce compte ne peut pas accéder à cette école.');
        }
        $r->session()->regenerate();
        return ['authenticated'=>true];
    })->middleware('throttle:school-login');
    Route::post('logout', function (Request $r) {
        Auth::logout(); $r->session()->invalidate(); $r->session()->regenerateToken();
        return response()->noContent();
    });
    Route::prefix('v1/tenants/{tenant}')->middleware([TenantContext::class, 'throttle:school-api'])->group(function () {
        Route::get('finance/summary', [C::class,'summary']);
        Route::get('dashboard/overview', [C::class,'overview']);
        Route::post('enrollments', [C::class,'enroll']);
        Route::post('payments', [C::class,'payment']);
        Route::post('refunds', [C::class,'refund']);
        Route::post('disbursements', [C::class,'disbursement']);
        Route::put('attendance', [C::class,'attendance']);
        Route::put('grades', [C::class,'grade']);
        Route::post('payroll', [C::class,'payroll']);
        Route::post('students/import', [C::class,'importStudents']);
        Route::post('{resource}/{id}/transition', [C::class,'transition']);
        Route::put('{resource}/{id}', [C::class,'update']);
        Route::delete('{resource}/{id}', [C::class,'delete']);
        Route::get('{resource}', [C::class,'index']);
        Route::post('{resource}', [C::class,'create']);
    });
});
