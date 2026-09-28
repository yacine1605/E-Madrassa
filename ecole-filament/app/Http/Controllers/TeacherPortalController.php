<?php
namespace App\Http\Controllers;

use App\Http\Middleware\TeacherPortal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeacherPortalController
{
    public function loginForm(Request $request)
    {
        abort_unless(config('school.backend'), 404);
        if ($request->user() && DB::transaction(fn () => TeacherPortal::teacher($request->user()->id))) {
            return redirect()->route('teacher.home');
        }
        return view('teacher.login');
    }

    public function login(Request $request)
    {
        abort_unless(config('school.backend'), 404);
        $data = $request->validate(['email'=>'required|email', 'password'=>'required|string']);
        if (!Auth::attempt($data)) throw ValidationException::withMessages(['email'=>'Identifiants incorrects.']);
        if (!DB::transaction(fn () => TeacherPortal::teacher(Auth::id()))) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw ValidationException::withMessages(['email'=>'Ce compte ne dispose pas d’un accès enseignant actif.']);
        }
        $request->session()->regenerate();
        return redirect()->route('teacher.home');
    }

    private function assignments(Request $request)
    {
        $teacher = $request->attributes->get('teacher');
        return DB::table('school.teacher_assignments as a')
            ->where('a.tenant_id', $teacher->tenant_id)->where('a.teacher_id', $teacher->id);
    }

    public function home(Request $request)
    {
        $classes = $this->assignments($request)
            ->join('school.cohorts as c', 'c.id', '=', 'a.cohort_id')
            ->join('school.subjects as s', 's.id', '=', 'a.subject_id')
            ->join('school.offerings as o', 'o.id', '=', 'c.offering_id')
            ->join('school.periods as p', 'p.id', '=', 'o.period_id')
            ->select('c.id', 'c.name', 's.name as subject', 'p.name as period')
            ->orderBy('c.name')->get()->groupBy('id');
        $teacher = $request->attributes->get('teacher');
        $students = DB::table('school.enrollments')->where('tenant_id', $teacher->tenant_id)
            ->whereIn('cohort_id', $classes->keys())->where('status', '<>', 'cancelled')->distinct()->count('student_id');
        return view('teacher.home', compact('classes', 'students'));
    }

    public function classroom(Request $request, string $cohort)
    {
        abort_unless(\Illuminate\Support\Str::isUuid($cohort), 404);
        $assignments = $this->assignments($request)->where('a.cohort_id', $cohort)
            ->join('school.subjects as s', 's.id', '=', 'a.subject_id')->select('s.name')->get();
        abort_if($assignments->isEmpty(), 404);
        $tenant = $request->attributes->get('teacher')->tenant_id;
        $class = DB::table('school.cohorts')->where('tenant_id', $tenant)->where('id', $cohort)->first();
        $students = DB::table('school.enrollments as e')->join('school.students as s', 's.id', '=', 'e.student_id')
            ->where('e.tenant_id', $tenant)->where('e.cohort_id', $cohort)->where('e.status', '<>', 'cancelled')
            ->orderBy('s.last_name')->orderBy('s.first_name')
            ->select('s.matricule', 's.first_name', 's.last_name')->paginate(30);
        return view('teacher.classroom', compact('class', 'assignments', 'students'));
    }

    public function schedule(Request $request)
    {
        $data = $request->validate(['date'=>'nullable|date_format:Y-m-d']);
        $start = \Carbon\CarbonImmutable::parse($data['date'] ?? now()->toDateString(), 'Africa/Algiers')->startOfDay();
        $end = $start->addDays(7);
        $teacher = $request->attributes->get('teacher');
        $sessions = DB::table('school.sessions as se')
            ->join('school.cohorts as c', 'c.id', '=', 'se.cohort_id')
            ->join('school.subjects as s', 's.id', '=', 'se.subject_id')
            ->join('school.rooms as r', 'r.id', '=', 'se.room_id')
            ->where('se.tenant_id', $teacher->tenant_id)->where('se.teacher_id', $teacher->id)
            ->where('se.status', '<>', 'cancelled')->where('se.starts_at', '>=', $start->toIso8601String())->where('se.starts_at', '<', $end->toIso8601String())
            ->orderBy('se.starts_at')->select('se.starts_at', 'se.ends_at', 'se.status', 'c.name as classroom', 's.name as subject', 'r.name as room')->get();
        return view('teacher.schedule', compact('sessions', 'start', 'end'));
    }

    public function password(Request $request)
    {
        $data = $request->validate(['current_password'=>'required|current_password:web',
            'password'=>'required|string|min:12|max:128|confirmed|different:current_password']);
        $request->user()->update(['password'=>$data['password']]);
        $request->session()->regenerate();
        return back()->with('success', 'Votre mot de passe a été modifié.');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('teacher.login');
    }
}
