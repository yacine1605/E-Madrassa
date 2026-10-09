<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherGradesController
{
    private function assignments(Request $request)
    {
        $teacher = $request->attributes->get('teacher');
        return DB::table('school.teacher_assignments as a')->where('a.tenant_id', $teacher->tenant_id)
            ->where('a.teacher_id', $teacher->id);
    }

    private function assessments(Request $request)
    {
        $teacher = $request->attributes->get('teacher');
        return DB::table('school.assessments as e')->where('e.tenant_id', $teacher->tenant_id)
            ->whereExists(function ($query) use ($teacher) {
                $query->selectRaw('1')->from('school.teacher_assignments as a')
                    ->whereColumn('a.tenant_id', 'e.tenant_id')->whereColumn('a.cohort_id', 'e.cohort_id')
                    ->whereColumn('a.subject_id', 'e.subject_id')->where('a.teacher_id', $teacher->id);
            });
    }

    public function index(Request $request)
    {
        $data = $request->validate(['classe'=>'nullable|uuid']);
        $cohort = $data['classe'] ?? null;
        if ($cohort) abort_unless($this->assignments($request)->where('a.cohort_id', $cohort)->exists(), 404);
        $assignments = $this->assignments($request)
            ->join('school.cohorts as c', 'c.id', '=', 'a.cohort_id')
            ->join('school.subjects as s', 's.id', '=', 'a.subject_id')
            ->when($cohort, fn ($q) => $q->where('a.cohort_id', $cohort))
            ->orderBy('c.name')->orderBy('s.name')->get(['a.id', 'c.name as classroom', 's.name as subject']);
        $assessments = $this->assessments($request)
            ->join('school.cohorts as c', 'c.id', '=', 'e.cohort_id')
            ->join('school.subjects as s', 's.id', '=', 'e.subject_id')
            ->when($cohort, fn ($q) => $q->where('e.cohort_id', $cohort))
            ->orderByDesc('e.held_on')->orderByDesc('e.id')
            ->select('e.*', 'c.name as classroom', 's.name as subject')->paginate(20)->withQueryString();
        return view('teacher.grades.index', compact('assignments', 'assessments', 'cohort'));
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'assignment_id'=>'required|uuid', 'kind'=>'required|in:continu,devoir,examen',
            'title'=>'required|string|max:200', 'held_on'=>'required|date_format:Y-m-d',
            'max_score'=>'required|numeric|between:1,1000|decimal:0,2',
        ]);
        $assignment = $this->assignments($request)->where('a.id', $data['assignment_id'])->first();
        abort_unless($assignment, 404);
        \App\School\Services\Curriculum::validateSubject($assignment->tenant_id, $assignment->cohort_id, $assignment->subject_id);
        $id = (string) Str::uuid();
        DB::table('school.assessments')->insert([
            'id'=>$id, 'tenant_id'=>$assignment->tenant_id, 'cohort_id'=>$assignment->cohort_id,
            'subject_id'=>$assignment->subject_id, 'kind'=>$data['kind'], 'title'=>$data['title'],
            'held_on'=>$data['held_on'], 'max_score'=>$data['max_score'], 'coefficient'=>1,
        ]);
        return redirect()->route('teacher.grades.show', $id)->with('success', 'Évaluation créée. Vous pouvez saisir les notes.');
    }

    private function assessment(Request $request, string $id, bool $lock = false): object
    {
        abort_unless(Str::isUuid($id), 404);
        $query = $this->assessments($request)->where('e.id', $id);
        if ($lock) $query->lockForUpdate();
        $assessment = $query->first();
        abort_unless($assessment, 404);
        return $assessment;
    }

    private function roster(object $assessment)
    {
        return DB::table('school.enrollments as en')->where('en.tenant_id', $assessment->tenant_id)
            ->where('en.cohort_id', $assessment->cohort_id)->where('en.status', '<>', 'cancelled');
    }

    private function revision(string $assessment, string $enrollment, ?object $grade): string
    {
        return hash_hmac('sha256', json_encode([$assessment, $enrollment, $grade?->id,
            $grade?->status, $grade?->score, $grade?->reason], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function show(Request $request, string $assessment)
    {
        $assessment = $this->assessment($request, $assessment);
        $class = DB::table('school.cohorts')->where('tenant_id', $assessment->tenant_id)->where('id', $assessment->cohort_id)->first();
        $subject = DB::table('school.subjects')->where('tenant_id', $assessment->tenant_id)->where('id', $assessment->subject_id)->first();
        $students = $this->roster($assessment)->join('school.students as s', 's.id', '=', 'en.student_id')
            ->orderBy('s.last_name')->orderBy('s.first_name')->orderBy('en.id')
            ->select('en.id as enrollment_id', 's.matricule', 's.first_name', 's.last_name')->paginate(25);
        $grades = DB::table('school.grades')->where('tenant_id', $assessment->tenant_id)
            ->where('assessment_id', $assessment->id)->whereIn('enrollment_id', $students->pluck('enrollment_id'))->get()->keyBy('enrollment_id');
        $versions = [];
        foreach ($students as $student) $versions[$student->enrollment_id] = $this->revision($assessment->id, $student->enrollment_id, $grades->get($student->enrollment_id));
        return view('teacher.grades.show', compact('assessment', 'class', 'subject', 'students', 'grades', 'versions'));
    }

    public function save(Request $request, string $assessment)
    {
        // Serialize saves and publication on this evaluation, including empty grade rows.
        $assessment = $this->assessment($request, $assessment, true);
        if ($assessment->published_at) throw ValidationException::withMessages(['grades'=>'Les notes de cette évaluation sont publiées et ne peuvent plus être modifiées.']);
        $data = $request->validate([
            'grades'=>'required|array|min:1|max:25', 'grades.*'=>'required|array:enrollment_id,status,score,version',
            'grades.*.enrollment_id'=>'required|uuid|distinct',
            'grades.*.status'=>'required|in:marked,absent,exempt',
            'grades.*.score'=>'nullable|numeric|min:0|max:'.$assessment->max_score.'|decimal:0,2',
            'grades.*.version'=>'required|string|size:64',
            'reason'=>'nullable|string|max:1000', 'page'=>'nullable|integer|min:1',
        ]);
        $ids = array_column($data['grades'], 'enrollment_id');
        $enrollments = $this->roster($assessment)->whereIn('en.id', $ids)->pluck('en.id');
        abort_unless($enrollments->count() === count($ids), 404);
        $existing = DB::table('school.grades')->where('tenant_id', $assessment->tenant_id)
            ->where('assessment_id', $assessment->id)->whereIn('enrollment_id', $ids)->get()->keyBy('enrollment_id');
        $reason = trim($data['reason'] ?? '');
        $changes = [];
        foreach ($data['grades'] as $index => $row) {
            $old = $existing->get($row['enrollment_id']);
            if (!hash_equals($this->revision($assessment->id, $row['enrollment_id'], $old), $row['version'])) {
                throw ValidationException::withMessages(['grades'=>'Une note a changé depuis l’ouverture de cette page. Rechargez la page avant de reprendre la saisie.']);
            }
            $score = $row['score'] ?? null;
            if ($row['status'] !== 'marked' && $score !== null) {
                throw ValidationException::withMessages(["grades.$index.score"=>'Une absence ou une dispense ne doit pas avoir de note.']);
            }
            $status = $row['status'] === 'marked' && $score === null ? 'missing' : $row['status'];
            if ($old && $old->status === $status && ($old->score === null ? $score === null : $score !== null && (float)$old->score === (float)$score)) continue;
            if (!$old && $status === 'missing') continue;
            if ($old && mb_strlen($reason) < 3) {
                throw ValidationException::withMessages(['reason'=>'Indiquez un motif d’au moins 3 caractères pour corriger une note déjà enregistrée.']);
            }
            $changes[] = ['old'=>$old, 'enrollment_id'=>$row['enrollment_id'], 'status'=>$status, 'score'=>$score];
        }
        // Validate every submitted row before writing any of them.
        foreach ($changes as $change) {
            $values = ['status'=>$change['status'], 'score'=>$change['score'], 'reason'=>$reason ?: null];
            if ($change['old']) {
                DB::table('school.grades')->where('tenant_id', $assessment->tenant_id)->where('id', $change['old']->id)->update($values);
            } else {
                DB::table('school.grades')->insert(['id'=>(string)Str::uuid(), 'tenant_id'=>$assessment->tenant_id,
                    'assessment_id'=>$assessment->id, 'enrollment_id'=>$change['enrollment_id'], ...$values]);
            }
        }
        return redirect()->route('teacher.grades.show', ['assessment'=>$assessment->id, 'page'=>$data['page'] ?? 1])
            ->with('success', count($changes).' note(s) enregistrée(s).');
    }
}
