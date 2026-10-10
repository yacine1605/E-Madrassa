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
            'ids'=>'sometimes|array|max:100','ids.*'=>'required|uuid','is_teacher'=>'sometimes|boolean',
            'level_id'=>'sometimes|uuid','cohort_id'=>'sometimes|uuid','subject_id'=>'sometimes|uuid','include_archived'=>'sometimes|boolean']);
        // Stable keyset pagination; the filtered total drives the record count in the UI.
        $q = $this->school->query($resource)->orderBy('id');
        if (isset($data['ids'])) $q->whereIn('id', $data['ids']);
        // Archived records leave lists and choices, but still resolve by id for history.
        elseif (in_array($resource, config('school_backend.archivable'), true) && !$request->boolean('include_archived')) $q->whereNull('archived_at');
        if (in_array($resource, ['subjects', 'curriculum'], true) && (isset($data['level_id']) || isset($data['cohort_id']))) {
            $level = $data['level_id'] ?? \App\School\Services\Curriculum::levelForCohort($this->school->tenant(), $data['cohort_id']);
            if ($resource === 'curriculum') $q->where('level_id', $level);
            else $q->whereIn('id', $this->school->query('curriculum')->where('level_id', $level)->select('subject_id'));
        }
        if ($resource === 'staff' && isset($data['is_teacher'])) $q->where('is_teacher', $request->boolean('is_teacher'));
        if ($resource === 'staff' && (isset($data['cohort_id']) || isset($data['subject_id']))) {
            $scope = $request->validate(['cohort_id'=>'required|uuid','subject_id'=>'required|uuid']);
            $level = \App\School\Services\Curriculum::levelForCohort($this->school->tenant(), $scope['cohort_id']);
            $q->whereIn('id', \App\School\Services\TeacherEligibility::teachers($this->school->tenant(), $level, $scope['subject_id'])->select('id'));
        }
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
        $limit = $data['limit'] ?? 25;
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
        if ($resource === 'payments') {
            $reversed = $this->school->query('payment_reversals')->whereIn('payment_id', $rows->pluck('id'))->pluck('payment_id')->flip();
            $rows = $rows->map(fn ($row) => [...(array) $row, 'status'=>isset($reversed[$row->id]) ? 'reversed' : 'validated']);
        }
        // The pay rate belongs to payroll, not to the HR record (FR-STF-04).
        if ($resource === 'contracts' && !in_array('payroll.read', $request->attributes->get('school.permissions', []), true)) {
            $rows = $rows->map(fn ($row) => array_diff_key((array) $row, ['rate_minor'=>true]));
        }
        return response()->json(['data'=>$rows, 'next'=>$next, 'total'=>$total]);
    }

    public function overview(Request $request)
    {
        $permissions = $request->attributes->get('school.permissions', []);
        $can = fn ($permission) => in_array($permission, $permissions, true);
        $counts = [];
        if ($can('students.read')) $counts['students'] = $this->school->query('students')->whereNull('archived_at')->count();
        if ($can('catalog.read')) $counts['cohorts'] = $this->school->query('cohorts')->whereNull('archived_at')->count();
        if ($can('enrollments.read')) $counts['enrollments'] = $this->school->query('enrollments')->count();
        if ($can('hr.read')) {
            $counts['teachers'] = $this->school->query('staff')->where('is_teacher', true)->where('active', true)->whereNull('archived_at')->count();
            $counts['staff'] = $this->school->query('staff')->where('is_teacher', false)->where('active', true)->whereNull('archived_at')->count();
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
        // A contract carries the pay rate, which only payroll may set (FR-STF-04).
        if ($resource === 'contracts') $this->school->authorize('payroll.write');
        $data = $request->validate($entry['rules']);
        if ($resource === 'teacher_qualifications' && !$this->school->query('staff')->where('id', $data['teacher_id'])->where('is_teacher', true)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['teacher_id'=>'Choisissez un enseignant actif.']);
        }
        if (in_array($resource, ['sessions', 'assessments', 'teacher_assignments'], true)) {
            \App\School\Services\Curriculum::validateSubject($this->school->tenant(), $data['cohort_id'], $data['subject_id']);
        }
        if (in_array($resource, ['sessions', 'teacher_assignments'], true)) {
            \App\School\Services\TeacherEligibility::validate($this->school->tenant(), $data);
        }
        return response()->json(['data'=>$this->school->insert($resource, $data)], 201);
    }

    public function update(Request $request, string $tenant, string $resource, string $id)
    {
        $entry = $this->catalog($resource);
        abort_unless(in_array($resource, config('school_backend.mutable_catalog'), true), 404);
        $this->school->authorize($entry['permission'].'.write');
        $row = $this->school->row($resource, $id);
        abort_if($row->archived_at ?? null, 409, 'Cet enregistrement est archivé.');
        $data = $request->validate($entry['rules']);
        // Codes and registration numbers are permanent (BR-18).
        if ($code = config('school_backend.permanent_codes')[$resource] ?? null) {
            if (array_key_exists($code, $data) && (string) $data[$code] !== (string) $row->$code)
                throw ValidationException::withMessages([$code=>'Ce code est définitif : il ne peut pas être modifié.']);
            unset($data[$code]);
        }
        if ($resource === 'teacher_qualifications' && !$this->school->query('staff')->where('id', $data['teacher_id'])->where('is_teacher', true)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['teacher_id'=>'Choisissez un enseignant actif.']);
        }
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
        $row = $this->school->row($resource, $id);
        // A qualification is a setting, not a business record: it is removed.
        if ($resource === 'teacher_qualifications') {
            $this->school->query($resource)->where('id', $id)->delete();
            return response()->noContent();
        }
        // Business records are archived, never deleted (BR-02): they leave the lists
        // but keep their history. Archiving waits until nothing current depends on them.
        if ($row->archived_at) return response()->noContent();
        $unarchived = fn ($q) => $q->whereNull('archived_at');
        $open = fn ($q) => $q->where('status', '<>', 'cancelled');
        $upcoming = fn ($q) => $q->where('status', '<>', 'cancelled')->where('ends_at', '>', now());
        $current = fn ($q) => $q->where(fn ($c) => $c->whereNull('ends_on')->orWhere('ends_on', '>=', now()->toDateString()));
        $dependents = [
            'campuses'=>[['rooms','campus_id',$unarchived],['offerings','campus_id',$unarchived]],
            'periods'=>[['offerings','period_id',$unarchived]],
            'programs'=>[['levels','program_id',$unarchived]],
            'levels'=>[['curriculum','level_id',$unarchived],['offerings','level_id',$unarchived]],
            'subjects'=>[['curriculum','subject_id',$unarchived]],
            'offerings'=>[['cohorts','offering_id',$unarchived],['enrollments','offering_id',$open]],
            'cohorts'=>[['enrollments','cohort_id',$open],['sessions','cohort_id',$upcoming]],
            'students'=>[['enrollments','student_id',$open]],
            'guardians'=>[['student_guardians','guardian_id',$unarchived]],
            'staff'=>[['sessions','teacher_id',$upcoming],['contracts','staff_id',$current],['teacher_assignments','teacher_id',fn ($q) => $q]],
            'rooms'=>[['sessions','room_id',$upcoming]],
        ];
        foreach ($dependents[$resource] ?? [] as [$table, $foreignKey, $active]) {
            abort_if($active($this->school->query($table)->where($foreignKey, $id))->exists(), 409,
                'Archivage impossible : des données en cours en dépendent. Archivez-les, clôturez-les ou annulez-les d’abord.');
        }
        $this->school->query($resource)->where('id', $id)->update(['archived_at'=>now()]);
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
            'paid_on'=>'required|date_format:Y-m-d','idempotency_key'=>'required|uuid',
            'method'=>'sometimes|in:cash,bank,ccp,card','reference'=>'nullable|string|max:200']);
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
            'lesson_versions'=>['pedagogy.write','published_at'], 'announcements'=>['pedagogy.write','published_at'],
            'payroll_runs'=>['payroll.write','status'], 'payments'=>['finance.write','status']];
        $statuses = ['enrollments'=>'validated,suspended,cancelled', 'sessions'=>'published,cancelled',
            'payroll_runs'=>'validated,discarded', 'payments'=>'reversed'];
        abort_unless(isset($map[$resource]), 404);
        [$permission, $field] = $map[$resource];
        $this->school->authorize($permission);
        $row = $this->school->row($resource, $id);
        $rules = ['reason'=>'required|string|min:3|max:1000'];
        if ($field === 'status') $rules['status'] = 'required|in:'.$statuses[$resource];
        $data = $request->validate($rules);
        // The audit trail records this reason against every change in the transaction.
        DB::select("select set_config('app.reason', ?, true)", [$data['reason']]);
        if ($resource === 'payments') return ['data'=>$this->school->reversePayment($id, $data['reason'])];
        if ($resource === 'payroll_runs') {
            if ($data['status'] === 'discarded') { $this->school->discardPayroll($id); return ['data'=>null]; }
            return ['data'=>$this->school->validatePayroll($id)];
        }
        if ($resource === 'enrollments') abort_if($row->status === 'cancelled', 409, 'Une inscription annulée ne peut pas être réactivée.');
        if ($resource === 'work_logs') {
            abort_if($row->validated_at || $row->rejected_at, 409, 'Ces heures ont déjà été examinées.');
            abort_if((string) ($row->entered_by ?? '') === (string) $request->user()->id, 409,
                'Séparation des tâches : la personne qui a saisi ces heures ne peut pas les valider.');
            $this->school->query($resource)->where('id', $id)->update(['validated_at'=>now(), 'reviewed_by'=>$request->user()->id, 'review_reason'=>$data['reason']]);
        }
        else $this->school->query($resource)->where('id', $id)->update([$field=>$field === 'status' ? $data['status'] : now()]);
        $this->school->insert('outbox', ['topic'=>$resource.'.transition', 'payload'=>json_encode(['id'=>$id,'actor_id'=>$request->user()->id,'reason'=>$data['reason']], JSON_THROW_ON_ERROR)]);
        return ['data'=>$this->school->row($resource, $id)];
    }

    public function summary()
    {
        $this->school->authorize('finance.read');
        // A reversed payment was an error, not money received (FR-FIN-03).
        $reversed = $this->school->query('payments')->whereIn('id', $this->school->query('payment_reversals')->select('payment_id'))->sum('amount_minor');
        return ['data'=>[
            'invoiced_minor'=>$this->school->query('invoices')->sum('amount_minor'),
            'received_minor'=>$this->school->query('payments')->sum('amount_minor') - $reversed,
            'reversed_minor'=>$reversed,
            'refunded_minor'=>$this->school->query('refunds')->sum('amount_minor'),
            'outstanding_minor'=>$this->school->query('invoice_balances')->sum('balance_minor'),
            'committed_expenses_minor'=>$this->school->query('expenses')->sum('amount_minor'),
            'disbursed_minor'=>$this->school->query('disbursements')->sum('amount_minor'),
        ]];
    }
}
