<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RedirectTeacher
{
    public function handle(Request $request, Closure $next)
    {
        if (config('school.backend') && $request->user()) {
            $isTeacher = DB::transaction(function () use ($request) {
                $member = \App\School\Services\DeploymentSchool::membership($request->user()->id);
                return $member && json_decode($member->permissions, true) === []
                    && TeacherPortal::teacher($request->user()->id) !== null;
            });
            if ($isTeacher) return redirect()->route('teacher.home');
        }
        return $next($request);
    }
}
