<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

class TeacherPortal
{
    public static function teacher(int $userId): ?object
    {
        $member = \App\School\Services\DeploymentSchool::membership($userId);
        if (!$member) return null;
        DB::select("select set_config('app.tenant_id', ?, true)", [$member->tenant_id]);
        return DB::table('school.staff')->where('tenant_id', $member->tenant_id)
            ->where('user_id', $userId)->where('is_teacher', true)->where('active', true)->first();
    }

    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('school.backend'), 404);
        if (!$request->user()) return redirect()->route('teacher.login');
        return DB::transaction(function () use ($request, $next) {
            \App\School\Http\TenantContext::traceRequest($request);
            $teacher = self::teacher($request->user()->id);
            abort_unless($teacher, 403, 'Ce compte ne correspond pas à un enseignant actif de cette école.');
            $request->attributes->set('teacher', $teacher);
            View::share('teacher', $teacher);
            View::share('schoolName', DB::table('school.institutions')->where('id', $teacher->tenant_id)->value('name'));
            return $next($request);
        });
    }
}
