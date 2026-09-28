<?php
namespace App\School\Http;

use App\School\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SchoolController
{
    public function __construct(private SchoolService $school) {}

    private function catalog(string $resource): array
    {
        $entry = config('school_backend.catalog')[$resource] ?? null;
        abort_unless($entry, 404);
        return $entry;
    }

    public function index(Request $request, string $tenant, string $resource)
    {
        $entry = config('school_backend.catalog')[$resource] ?? null;
        $permission = $entry ? $entry['permission'].'.read' : (config('school_backend.read')[$resource] ?? null);
        abort_unless($permission, 404);
        $this->school->authorize($permission);
        $data = $request->validate(['limit'=>'sometimes|integer|between:1,100','after'=>'sometimes|uuid','q'=>'sometimes|string|max:100',
            'ids'=>'sometimes|array|max:100','ids.*'=>'required|uuid','is_teacher'=>'sometimes|boolean']);
        // Stable keyset pagination; the filtered total drives the record count in the UI.
        $q = $this->school->query($resource)->orderBy('id');
        if (isset($data['ids'])) $q->whereIn('id', $data['ids']);
        if ($resource === 'staff' && isset($data['is_teacher'])) $q->where('is_teacher', $request->boolean('is_teacher'));
        $search = trim($data['q'] ?? '');
        if ($search !== '') {
            $fields = $entry
                ? array_keys(array_filter($entry['rules'], fn ($rule) => str_contains($rule, 'string') ||
                    str_contains($rule, 'email') || str_contains($rule, 'in:')))
                : (config('school_backend.search_fields')[$resource] ?? []);
            $accents = 'àáâäçèéêëìíîïñòóôöùúûüÿ';
            $plain = 'aaaaceeeeiiiinoooouuuuy';
            $normalized = strtr(mb_strtolower($search, 'UTF-8'), array_combine(mb_str_split($accents), str_split($plain)));
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $normalized).'%';
            $q->where(function ($match) use ($fields, $accents, $plain, $pattern) {
                $match->whereRaw("id::text LIKE ? ESCAPE '!'", [$pattern]);
                foreach ($fields as $field) {
                    if (!preg_match('/^[a-z_]+$/', $field)) continue;
                    $match->orWhereRaw("translate(lower(\"$field\"), ?, ?) LIKE ? ESCAPE '!'",
                        [$accents, $plain, $pattern]);
                }
            });
        }
        $limit = $data['limit'] ?? 50;
        $total = (clone $q)->reorder()->count();
        if (isset($data['after'])) $q->where('id', '>', $data['after']);
        $rows = $q->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();
        $next = $hasMore ? $rows->last()->id : null;
        if ($resource === 'teacher_assignments') {
            $cohorts = $this->school->query('cohorts')->whereIn('id', $rows->pluck('cohort_id'))->pluck('name', 'id');
            $subjects = $this->school->query('subjects')->whereIn('id', $rows->pluck('subject_id'))->pluck('name', 'id');
            $teachers = $this->school->query('staff')->whereIn('id', $rows->pluck('teacher_id'))->pluck('name', 'id');
            $rows = $rows->map(function ($row) use ($cohorts, $subjects, $teachers) {
                return ['id'=>$row->id, 'classe'=>$cohorts[$row->cohort_id] ?? $row->cohort_id,
                    'matiere'=>$subjects[$row->subject_id] ?? $row->subject_id,
                    'enseignant'=>$teachers[$row->teacher_id] ?? $row->teacher_id];
            });
        }
        return response()->json(['data'=>$rows, 'next'=>$next, 'total'=>$total]);
    }

    public function overview(Request $request)
    {
        $permissions = $request->attributes->get('school.permissions', []);
        $can = fn ($permission) => in_array($permission, $permissions, true);
        $counts = [];
        foreach (['students'=>'students.read','cohorts'=>'catalog.read','enrollments'=>'enrollments.read'] as $table=>$permission) {
            if ($can($permission)) $counts[$table] = $this->school->query($table)->count();
        }
        if ($can('hr.read')) {
            $counts['teachers'] = $this->school->query('staff')->where('is_teacher', true)->where('active', true)->count();
            $counts['staff'] = $this->school->query('staff')->where('is_teacher', false)->where('active', true)->count();
        }
        if ($can('enrollments.read')) $counts['pending'] = $this->school->query('enrollments')->where('status', 'pending')->count();
        if ($can('finance.read')) $counts['balance_minor'] = $this->school->query('invoice_balances')->sum('balance_minor');
        $cycles = [];
        if ($can('catalog.read') && $can('enrollments.read')) {
            $cycles = DB::table('school.programs as p')
                ->leftJoin('school.levels as l','l.program_id','=','p.id')
                ->leftJoin('school.offerings as o','o.level_id','=','l.id')
                ->leftJoin('school.cohorts as c','c.offering_id','=','o.id')
                ->leftJoin('school.enrollments as e', fn ($join) => $join->on('e.cohort_id','=','c.id')->where('e.status','<>','cancelled'))
                ->where('p.tenant_id', $this->school->tenant())->where('p.active', true)
                ->groupBy('p.id','p.name')->orderBy('p.name')
                ->selectRaw('p.name, count(distinct c.id) as classes, count(distinct e.student_id) as students')->get();
        }
        $upcoming = [];
        if ($can('pedagogy.read') && $can('catalog.read')) {
            $upcoming = DB::table('school.sessions as s')->join('school.cohorts as c','c.id','=','s.cohort_id')
                ->join('school.subjects as sub','sub.id','=','s.subject_id')->join('school.rooms as r','r.id','=','s.room_id')
                ->where('s.tenant_id', $this->school->tenant())->where('s.status','<>','cancelled')
                ->where('s.ends_at','>=',now()->toIso8601String())->orderBy('s.starts_at')->limit(4)
                ->get(['s.id','s.starts_at','s.ends_at','c.name as classroom','sub.name as subject','r.name as room']);
        }
        return ['data'=>compact('counts','cycles','upcoming')];
    }

    public function create(Request $request, string $tenant, string $resource)
    {
        $entry = $this->catalog($resource);
        $this->school->authorize($entry['permission'].'.write');
        $data = $request->validate($entry['rules']);
        if ($resource === 'teacher_assignments') {
            $cohort = DB::table('school.cohorts as c')->where('c.tenant_id', $this->school->tenant())
                ->join('school.offerings as o', 'o.id', '=', 'c.offering_id')
                ->where('c.id', $data['cohort_id'])->first(['o.level_id']);
            if (!$cohort || !$this->school->query('curriculum')->where('level_id', $cohort->level_id)
                ->where('subject_id', $data['subject_id'])->exists()) {
                throw ValidationException::withMessages(['subject_id'=>'La matière doit appartenir au niveau de cette classe.']);
            }
            if (!$this->school->query('staff')->where('id', $data['teacher_id'])
                ->where('is_teacher', true)->where('active', true)->exists()) {
                throw ValidationException::withMessages(['teacher_id'=>'Choisissez un enseignant actif.']);
            }
        }
        return response()->json(['data'=>$this->school->insert($resource, $data)], 201);
    }

    public function update(Request $request, string $tenant, string $resource, string $id)
    {
        $entry = $this->catalog($resource);
        abort_unless(in_array($resource, config('school_backend.mutable_catalog'), true), 404);
        $this->school->authorize($entry['permission'].'.write');
        $row = $this->school->row($resource, $id);
        $data = $request->validate($entry['rules']);
        if ($resource === 'offerings' && $this->school->query('enrollments')->where('offering_id', $id)->exists()) {
            abort_if($row->level_id !== $data['level_id'] || $row->period_id !== $data['period_id'] ||
                $row->campus_id !== $data['campus_id'], 409, 'Une offre déjà utilisée ne peut pas être déplacée.');
        }
        if ($resource === 'cohorts') {
            abort_if($row->offering_id !== $data['offering_id'] &&
                $this->school->query('enrollments')->where('cohort_id', $id)->exists(), 409,
                'Une classe avec inscriptions ne peut pas changer d’offre.');
            $active = $this->school->query('enrollments')->where('cohort_id', $id)
                ->where('status', '<>', 'cancelled')->count();
            abort_if($data['capacity'] < $active, 409, 'Capacité inférieure au nombre d’inscriptions actives.');
        }
        $this->school->query($resource)->where('id', $id)->update($data);
        return ['data'=>$this->school->row($resource, $id)];
    }

    public function delete(string $tenant, string $resource, string $id)
    {
        $entry = $this->catalog($resource);
        abort_unless(in_array($resource, config('school_backend.mutable_catalog'), true), 404);
        $this->school->authorize($entry['permission'].'.write');
        $this->school->row($resource, $id);
        $dependents = [
            'campuses'=>[['rooms','campus_id'],['offerings','campus_id']],
            'periods'=>[['offerings','period_id']],
            'programs'=>[['levels','program_id']],
            'levels'=>[['curriculum','level_id'],['offerings','level_id']],
            'subjects'=>[['curriculum','subject_id'],['sessions','subject_id'],['lesson_versions','subject_id'],['assessments','subject_id']],
            'offerings'=>[['cohorts','offering_id'],['enrollments','offering_id']],
            'cohorts'=>[['enrollments','cohort_id'],['sessions','cohort_id'],['assessments','cohort_id'],['teacher_assignments','cohort_id']],
            'students'=>[['student_guardians','student_id'],['enrollments','student_id'],['documents','student_id']],
            'guardians'=>[['student_guardians','guardian_id']],
            'staff'=>[['sessions','teacher_id'],['contracts','staff_id'],['payroll_runs','staff_id'],['teacher_assignments','teacher_id']],
            'rooms'=>[['sessions','room_id']],
        ];
        foreach ($dependents[$resource] ?? [] as [$table, $foreignKey]) {
            abort_if($this->school->query($table)->where($foreignKey, $id)->exists(), 409,
                'Suppression impossible : cet enregistrement possède des données liées.');
        }
        // Foreign keys block removal of referenced records; financial and audit
        // records are deliberately outside this endpoint.
        $this->school->query($resource)->where('id', $id)->delete();
        return response()->noContent();
    }

    public function importStudents(Request $request, string $tenant)
    {
        $this->school->authorize('students.write');
        $payload = $request->validate(['rows'=>'required|array|min:1|max:500']);
        $rules = config('school_backend.catalog.students.rules');
        $validated = [];
        $errors = [];

        foreach ($payload['rows'] as $index => $row) {
            if (!is_array($row)) {
                $errors['rows.'.$index][] = 'Ligne invalide.';
                continue;
            }

            $validator = Validator::make($row, $rules);
            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $messages) {
                    foreach ($messages as $message) $errors['rows.'.$index.'.'.$field][] = 'Ligne '.($index + 2).' — '.$message;
                }
                continue;
            }

            $validated[$index] = $validator->validated();
        }

        // Validate the entire file before inserting any row. TenantContext wraps
        // this action in one database transaction, so conflicts also roll back.
        if ($errors) throw ValidationException::withMessages($errors);

        $created = array_map(fn (array $student) => $this->school->insert('students', $student), $validated);
        return response()->json(['data'=>['created'=>count($created)]], 201);
    }

    public function enroll(Request $request)
    {
        $data = $request->validate(['student_id'=>'required|uuid','offering_id'=>'required|uuid',
            'cohort_id'=>'required|uuid','due_on'=>'required|date_format:Y-m-d','idempotency_key'=>'required|uuid']);
        return response()->json(['data'=>$this->school->enroll($data)], 201);
    }

    public function payment(Request $request)
    {
        $data = $request->validate(['invoice_id'=>'required|uuid','amount_minor'=>'required|integer|between:1,100000000000',
            'method'=>'required|in:cash,bank,ccp,card','reference'=>'nullable|string|max:200','idempotency_key'=>'required|uuid']);
        $data['reference'] = $data['reference'] ?? null;
        return response()->json(['data'=>$this->school->financial('payments', $data)], 201);
    }

    public function refund(Request $request)
    {
        $data = $request->validate(['payment_id'=>'required|uuid','amount_minor'=>'required|integer|between:1,100000000000',
            'reason'=>'required|string|min:3|max:1000','idempotency_key'=>'required|uuid']);
        return response()->json(['data'=>$this->school->financial('refunds', $data)], 201);
    }

    public function disbursement(Request $request)
    {
        $data = $request->validate(['expense_id'=>'required|uuid','amount_minor'=>'required|integer|between:1,100000000000',
            'paid_on'=>'required|date_format:Y-m-d','idempotency_key'=>'required|uuid']);
        return response()->json(['data'=>$this->school->financial('disbursements', $data)], 201);
    }

    public function attendance(Request $request)
    {
        $data = $request->validate(['session_id'=>'required|uuid','enrollment_id'=>'required|uuid',
            'status'=>'required|in:present,absent,late,excused','reason'=>'nullable|string|min:3|max:1000']);
        return ['data'=>$this->school->recordResult('attendance', $data)];
    }

    public function grade(Request $request)
    {
        $data = $request->validate(['assessment_id'=>'required|uuid','enrollment_id'=>'required|uuid',
            'status'=>'required|in:marked,absent,exempt,missing','score'=>'nullable|numeric|min:0','reason'=>'nullable|string|min:3|max:1000']);
        $data['score'] = $data['score'] ?? null;
        return ['data'=>$this->school->recordResult('grades', $data)];
    }

    public function payroll(Request $request)
    {
        $data = $request->validate(['staff_id'=>'required|uuid','month'=>'required|date_format:Y-m-d|regex:/^\d{4}-\d{2}-01$/']);
        return response()->json(['data'=>$this->school->payroll($data)], 201);
    }

    public function transition(Request $request, string $tenant, string $resource, string $id)
    {
        $map = ['enrollments'=>['enrollments.write','status'], 'sessions'=>['pedagogy.write','status'],
            'assessments'=>['pedagogy.write','published_at'], 'work_logs'=>['payroll.write','validated_at'],
            'lesson_versions'=>['pedagogy.write','published_at'], 'announcements'=>['pedagogy.write','published_at']];
        abort_unless(isset($map[$resource]), 404);
        [$permission, $field] = $map[$resource];
        $this->school->authorize($permission);
        $row = $this->school->row($resource, $id);
        $rules = ['reason'=>'required|string|min:3|max:1000'];
        if ($field === 'status') $rules['status'] = 'required|in:'.($resource === 'enrollments' ? 'validated,suspended,cancelled' : 'published,cancelled');
        $data = $request->validate($rules);
        if ($resource === 'enrollments') abort_if($row->status === 'cancelled', 409, 'Une inscription annulée ne peut pas être réactivée.');
        $this->school->query($resource)->where('id', $id)->update([$field=>$field === 'status' ? $data['status'] : now()]);
        $this->school->insert('outbox', ['topic'=>$resource.'.transition', 'payload'=>json_encode(['id'=>$id,'actor_id'=>$request->user()->id,'reason'=>$data['reason']], JSON_THROW_ON_ERROR)]);
        return ['data'=>$this->school->row($resource, $id)];
    }

    public function summary()
    {
        $this->school->authorize('finance.read');
        return ['data'=>[
            'invoiced_minor'=>$this->school->query('invoices')->sum('amount_minor'),
            'received_minor'=>$this->school->query('payments')->sum('amount_minor'),
            'refunded_minor'=>$this->school->query('refunds')->sum('amount_minor'),
            'outstanding_minor'=>$this->school->query('invoice_balances')->sum('balance_minor'),
            'committed_expenses_minor'=>$this->school->query('expenses')->sum('amount_minor'),
            'disbursed_minor'=>$this->school->query('disbursements')->sum('amount_minor'),
        ]];
    }
}
