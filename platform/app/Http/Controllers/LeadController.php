<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\CurrentSchool;
use App\Support\Paging;
use App\Support\PupilRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-CRM-01, FR-CRM-02: the school's prospects, from the first contact to the pupil's enrolment.
 * A prospect belongs to a site, whose grants of `crm.lead.*` read and follow it (SQL 038); its
 * children aim at a level of the referential; its interactions never change; its reminders notify
 * the assignee once due. The conversion makes a child's pupil, their guardian and enrolment in
 * the request's one transaction, once (AC1).
 */
final class LeadController
{
    use HandlesLabels;

    // With its children still on it, the eldest first, its assignee's name and its next reminder.
    private const SELECT = "SELECT id, site_id, last_name, first_name, phone, email, relationship, source, status, lost_reason, closed_at,
        assigned_to, (SELECT u.name FROM public.users u WHERE u.id = lead.assigned_to) AS assigned_name, custom, version, created_at, updated_at,
        (SELECT coalesce(jsonb_agg(jsonb_build_object('id', c.id, 'last_name', c.last_name, 'first_name', c.first_name, 'birth_date', c.birth_date,
           'level_id', c.level_id, 'student_id', c.student_id, 'enrollment_id', c.enrollment_id, 'converted_at', c.converted_at, 'version', c.version)
           ORDER BY c.birth_date NULLS LAST, c.first_name, c.id), '[]') FROM school.lead_child c WHERE c.tenant_id = lead.tenant_id AND c.lead_id = lead.id AND c.archived_at IS NULL) AS children,
        (SELECT min(r.due_at) FROM school.lead_reminder r WHERE r.tenant_id = lead.tenant_id AND r.lead_id = lead.id AND r.status = 'planned') AS next_reminder
        FROM school.lead";
    // FR-ELV-04 for prospects: found by the contact's name, phone or e-mail, or a child's name.
    private const SEARCHED = ['last_name', 'first_name', 'phone', 'email', "(SELECT string_agg(concat_ws(' ', c.last_name, c.first_name), ' ')
        FROM school.lead_child c WHERE c.tenant_id = lead.tenant_id AND c.lead_id = lead.id)"];
    private const INTERACTIONS = 'SELECT id, kind, occurred_at, body, created_by, (SELECT u.name FROM public.users u WHERE u.id = lead_interaction.created_by) AS author,
        created_at FROM school.lead_interaction';
    private const REMINDERS = 'SELECT id, due_at, body, status, closed_at, version, created_by,
        (SELECT u.name FROM public.users u WHERE u.id = lead_reminder.created_by) AS author, created_at FROM school.lead_reminder';
    private const RELATIONSHIPS = 'father,mother,guardian,self,other';

    public function __construct(private CurrentSchool $school, private PupilRecords $records) {}

    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['q' => 'nullable|string|max:200', 'status' => 'nullable|string|max:30', 'source' => 'nullable|string|max:30',
            'site_id' => 'nullable|uuid', 'assigned_to' => 'nullable|integer', 'mine' => 'boolean', 'open' => 'boolean']);
        $sql = self::SELECT.' WHERE tenant_id = ?';
        $bindings = [$this->school->id()];
        if (isset($data['q']) && $data['q'] !== '') { $sql .= ' AND '.Paging::search(self::SEARCHED); $bindings[] = $data['q']; }
        foreach (['status', 'source', 'site_id', 'assigned_to'] as $field) {
            if (isset($data[$field])) { $sql .= ' AND '.$field.' = ?'; $bindings[] = $data[$field]; }
        }
        if ($request->boolean('mine')) { $sql .= ' AND assigned_to = ?'; $bindings[] = auth()->id(); }
        if ($request->boolean('open')) $sql .= ' AND closed_at IS NULL';
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, 'closed_at IS NOT NULL, created_at DESC, id', fn ($row) => $this->present($row));
    }

    public function show(string $lead) { return ['data' => $this->present($this->find($lead))]; }

    /**
     * FR-CRM-01: who already has this phone, as the reader may see them: prospects, pupils and
     * guardians (a family that comes back, a brother's father). A warning, never a merge.
     */
    public function duplicates(Request $request)
    {
        $data = $request->validate(['phone' => 'required|string|max:40']);
        return ['data' => $this->samePhone($data['phone'])];
    }

    public function store(Request $request)
    {
        $this->records->createPermission('crm.lead.create');
        $data = $request->validate($this->rules(true) + ['duplicate_reason' => 'nullable|string|min:3|max:500', 'children' => 'sometimes|array|max:10',
            ...$this->childRules('children.*.')]);
        $this->records->site($data);
        // The warning and the write together, as for pupils: two calls of the same family at once see each other.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 38))', [$this->school->id()]);
        $matches = isset($data['phone']) ? $this->samePhone($data['phone']) : [];
        if ($matches && empty($data['duplicate_reason'])) throw Refusal::of('lead_duplicate', extra: ['duplicates' => $matches]);
        if ($matches) DB::select("SELECT set_config('app.reason', ?, true)", [$data['duplicate_reason']]);
        $children = $data['children'] ?? [];
        unset($data['duplicate_reason'], $data['children']);
        $data['custom'] = $this->records->custom('lead', $data['custom'] ?? []);
        $id = DB::table('school.lead')->insertGetId(['tenant_id' => $this->school->id(), ...$data], 'id');
        foreach ($children as $child) $this->insertChild($id, $child);
        return response()->json($this->show($id), 201);
    }

    public function update(Request $request, string $lead)
    {
        $current = $this->find($lead, true);
        $data = $request->validate($this->rules(false) + ['version' => 'required|integer|min:1']);
        $this->records->site($data);
        if (array_key_exists('custom', $data)) $data['custom'] = $this->records->custom('lead', $data['custom'], $current);
        DB::table('school.lead')->where('tenant_id', $this->school->id())->where('id', $current->id)->update($data);
        return $this->show($current->id);
    }

    /** FR-CRM-01, step 3: to another step, or lost with its reason (AC2); a closed prospect reopens with a reason. */
    public function status(Request $request, string $lead)
    {
        $current = $this->find($lead, true);
        $data = $request->validate(['status' => 'required|string|max:30', 'lost_reason' => 'nullable|string|max:500',
            'reason' => 'nullable|string|min:3|max:500', 'version' => 'required|integer|min:1']);
        if (!empty($data['reason'])) DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        DB::table('school.lead')->where('tenant_id', $this->school->id())->where('id', $current->id)
            ->update(['status' => $data['status'], 'lost_reason' => $data['lost_reason'] ?? null, 'version' => $data['version']]);
        return $this->show($current->id);
    }

    /** The members a prospect of this site may be assigned to: those who read its site's prospects. */
    public function assignees(Request $request)
    {
        $data = $request->validate(['site_id' => 'nullable|uuid']);
        return ['data' => array_map(fn ($row) => (array) $row, DB::select('SELECT u.id, u.name FROM school.lead_assignees(?) a(user_id)
            JOIN public.users u ON u.id = a.user_id ORDER BY u.name, u.id', [$data['site_id'] ?? null]))];
    }

    public function addChild(Request $request, string $lead)
    {
        $current = $this->find($lead);
        $data = $request->validate($this->childRules());
        $id = $this->insertChild($current->id, $data);
        return response()->json(['data' => $this->child($id)], 201);
    }

    public function updateChild(Request $request, string $child)
    {
        $current = $this->child($child);
        $data = $request->validate($this->childRules() + ['version' => 'required|integer|min:1']);
        DB::table('school.lead_child')->where('tenant_id', $this->school->id())->where('id', $current['id'])->update($data);
        return ['data' => $this->child($current['id'])];
    }

    /** A child recorded by mistake leaves the prospect, unless converted; the journal keeps it. */
    public function archiveChild(Request $request, string $child)
    {
        $current = $this->child($child);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500', 'version' => 'required|integer|min:1']);
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        DB::table('school.lead_child')->where('tenant_id', $this->school->id())->where('id', $current['id'])
            ->update(['archived_at' => now(), 'version' => $data['version']]);
        return response()->noContent();
    }

    public function interactions(Request $request, string $lead)
    {
        $this->find($lead);
        $request->validate(Paging::RULES);
        $request->merge(['paginate' => true]);
        return Paging::select($request, self::INTERACTIONS.' WHERE tenant_id = ? AND lead_id = ?', [$this->school->id(), $lead], 'occurred_at DESC, id');
    }

    public function addInteraction(Request $request, string $lead)
    {
        $current = $this->find($lead);
        $data = $request->validate(['kind' => 'required|in:call,visit,meeting,message,note', 'occurred_at' => 'nullable|date', 'body' => 'required|string|max:2000']);
        $id = DB::table('school.lead_interaction')->insertGetId(['tenant_id' => $this->school->id(), 'lead_id' => $current->id,
            'kind' => $data['kind'], 'occurred_at' => $data['occurred_at'] ?? null, 'body' => $data['body']], 'id');
        return response()->json(['data' => (array) DB::selectOne(self::INTERACTIONS.' WHERE tenant_id = ? AND id = ?', [$this->school->id(), $id])], 201);
    }

    public function reminders(Request $request, string $lead)
    {
        $this->find($lead);
        $request->validate(Paging::RULES + ['planned' => 'boolean']);
        $request->merge(['paginate' => true]);
        return Paging::select($request, self::REMINDERS.' WHERE tenant_id = ? AND lead_id = ?'.($request->boolean('planned') ? " AND status = 'planned'" : ''),
            [$this->school->id(), $lead], "status <> 'planned', due_at, id");
    }

    public function addReminder(Request $request, string $lead)
    {
        $current = $this->find($lead);
        $data = $request->validate(['due_at' => 'required|date', 'body' => 'required|string|max:500']);
        $id = DB::table('school.lead_reminder')->insertGetId(['tenant_id' => $this->school->id(), 'lead_id' => $current->id,
            'due_at' => $data['due_at'], 'body' => $data['body']], 'id');
        return response()->json(['data' => $this->reminder($id)], 201);
    }

    public function completeReminder(Request $request, string $reminder) { return $this->closeReminder($request, $reminder, 'done'); }
    public function cancelReminder(Request $request, string $reminder) { return $this->closeReminder($request, $reminder, 'cancelled'); }

    /**
     * FR-CRM-02: one child of the prospect becomes a pupil on its site, with their guardian (one
     * recorded already, or the contact, new) and their enrolment in a programme, together or not
     * at all; the prospect is registered and keeps the link. The same key again answers the same
     * conversion; another key for a child converted already is refused (AC1).
     */
    public function convert(Request $request, string $child)
    {
        $data = $request->validate(['idempotency_key' => 'required|uuid', 'program_id' => 'required|uuid', 'student' => 'required|array',
            'guardian_id' => 'nullable|uuid', 'guardian' => 'nullable|array', 'relationship' => 'required_with:guardian_id,guardian|nullable|in:father,mother,guardian,other',
            'is_primary_contact' => 'boolean', 'is_payer' => 'boolean', 'emergency_contact' => 'boolean']);
        // The child first, locked: a second click waits for the first, then finds the pupil it made.
        $row = DB::selectOne('SELECT c.id, c.lead_id, c.student_id, c.enrollment_id, c.conversion_key, c.archived_at, c.version, l.site_id
            FROM school.lead_child c JOIN school.lead l ON l.tenant_id = c.tenant_id AND l.id = c.lead_id
            WHERE c.tenant_id = ? AND c.id = ? FOR UPDATE OF c', [$this->school->id(), $this->uuid($child)]);
        abort_unless($row, 404);
        if ($row->student_id !== null) {
            if ($row->conversion_key !== strtolower($data['idempotency_key'])) throw Refusal::of('lead_already_converted');
            return ['data' => $this->converted($row->lead_id, $row->student_id, $row->enrollment_id)];
        }
        if ($row->archived_at !== null) throw Refusal::of('lead_child_archived');
        $this->records->createPermission('enrollment.enrollment.create');
        if (isset($data['guardian_id']) || isset($data['guardian'])) $this->records->createPermission('guardian.record.create');
        $student = app(StudentController::class)->admit([...$data['student'], 'site_id' => $row->site_id], 'student.');
        if (isset($data['guardian_id'])) {
            abort_unless(DB::selectOne('SELECT 1 FROM school.guardian WHERE tenant_id = ? AND id = ? AND archived_at IS NULL', [$this->school->id(), $data['guardian_id']]), 404);
            $guardian = $data['guardian_id'];
        } elseif (isset($data['guardian'])) {
            $guardian = app(GuardianController::class)->admit([...$data['guardian'], 'site_id' => $row->site_id], 'guardian.');
        }
        // A minor's enrolment needs their primary contact (FR-ELV-02): the guardian is linked first.
        if (isset($guardian)) {
            DB::table('school.student_guardian')->insert(['tenant_id' => $this->school->id(), 'student_id' => $student, 'guardian_id' => $guardian,
                'relationship' => $data['relationship'], 'is_primary_contact' => $data['is_primary_contact'] ?? true, 'is_payer' => $data['is_payer'] ?? true,
                'emergency_contact' => $data['emergency_contact'] ?? false]);
        }
        abort_unless(DB::selectOne('SELECT 1 FROM school.program WHERE tenant_id = ? AND id = ?', [$this->school->id(), $data['program_id']]), 404);
        $enrollment = DB::selectOne('SELECT school.enroll(?, ?, ?) AS id', [$student, $data['program_id'], $data['idempotency_key']])->id;
        DB::table('school.lead_child')->where('tenant_id', $this->school->id())->where('id', $row->id)
            ->update(['student_id' => $student, 'enrollment_id' => $enrollment, 'conversion_key' => $data['idempotency_key'], 'version' => $row->version]);
        // Registered, whatever step it was at; its version is the conversion's, not a form's.
        DB::update("UPDATE school.lead SET status = 'registered', version = version WHERE tenant_id = ? AND id = ? AND status <> 'registered'",
            [$this->school->id(), $row->lead_id]);
        return response()->json(['data' => $this->converted($row->lead_id, $student, $enrollment)], 201);
    }

    /** FR-CRM-02 AC2: the prospect a pupil came from, with its interactions, on the pupil's sheet. */
    public function forStudent(string $student)
    {
        $lead = DB::selectOne('SELECT lead_id FROM school.lead_child WHERE tenant_id = ? AND student_id = ?', [$this->school->id(), $this->uuid($student)]);
        if (!$lead) return ['data' => null, 'interactions' => []];
        $row = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?', [$this->school->id(), $lead->lead_id]);
        if (!$row) return ['data' => null, 'interactions' => []];
        return ['data' => $this->present($row), 'interactions' => array_map(fn ($row) => (array) $row,
            DB::select(self::INTERACTIONS.' WHERE tenant_id = ? AND lead_id = ? ORDER BY occurred_at DESC, id LIMIT 100', [$this->school->id(), $row->id]))];
    }

    private function rules(bool $create): array
    {
        $sometimes = $create ? [] : ['sometimes'];
        return [
            'last_name' => [...$sometimes, 'nullable', 'string', 'max:80', ...($create ? ['required_without:first_name'] : [])],
            'first_name' => [...$sometimes, 'nullable', 'string', 'max:80'],
            'phone' => [...$sometimes, 'nullable', 'string', 'max:40', ...($create ? ['required_without:email'] : [])],
            'email' => [...$sometimes, 'nullable', 'email', 'max:200'],
            'relationship' => [...$sometimes, 'nullable', 'in:'.self::RELATIONSHIPS],
            'source' => [...($create ? ['required'] : ['sometimes']), 'string', 'max:30'],
            'status' => $create ? 'nullable|string|max:30' : 'prohibited',
            'assigned_to' => [...$sometimes, 'nullable', 'integer'],
            'site_id' => 'sometimes|uuid', 'custom' => 'sometimes|array|max:20',
            ...array_fill_keys(['tenant_id', 'lost_reason', 'closed_at'], 'prohibited'),
        ];
    }

    private function childRules(string $prefix = ''): array
    {
        return [$prefix.'first_name' => 'required|string|max:80', $prefix.'last_name' => 'nullable|string|max:80',
            $prefix.'birth_date' => 'nullable|date_format:Y-m-d|before_or_equal:today', $prefix.'level_id' => 'nullable|uuid'];
    }

    private function insertChild(string $lead, array $child): string
    {
        if (isset($child['level_id']) && !DB::selectOne('SELECT 1 FROM school.ref_node WHERE tenant_id = ? AND id = ?', [$this->school->id(), $child['level_id']])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['level_id' => __('errors.level_unknown')]);
        }
        return DB::table('school.lead_child')->insertGetId(['tenant_id' => $this->school->id(), 'lead_id' => $lead,
            ...array_intersect_key($child, array_flip(['first_name', 'last_name', 'birth_date', 'level_id']))], 'id');
    }

    /** Pupils, guardians and prospects of this phone, as the reader may see them, at most ten. */
    private function samePhone(string $phone): array
    {
        $digits = "regexp_replace(school.search_key(%s), '[^0-9+]', '', 'g')";
        $key = DB::selectOne('SELECT nullif('.sprintf($digits, '?').", '') AS k", [$phone])->k;
        if ($key === null) return [];
        $t = $this->school->id();
        return array_map(fn ($row) => (array) $row, DB::select("SELECT * FROM (
            SELECT 'lead' AS kind, id, concat_ws(' ', last_name, first_name) AS name, status FROM school.lead WHERE tenant_id = ? AND ".sprintf($digits, 'phone')." = ?
            UNION ALL SELECT 'student', id, concat_ws(' · ', nullif(concat_ws(' ', last_name_lat, first_name_lat), ''), nullif(concat_ws(' ', last_name_ar, first_name_ar), '')), status
              FROM school.student WHERE tenant_id = ? AND archived_at IS NULL AND ".sprintf($digits, 'phone')." = ?
            UNION ALL SELECT 'guardian', id, concat_ws(' · ', nullif(concat_ws(' ', last_name_lat, first_name_lat), ''), nullif(concat_ws(' ', last_name_ar, first_name_ar), '')), NULL
              FROM school.guardian WHERE tenant_id = ? AND archived_at IS NULL AND ".sprintf($digits, 'phone')." = ?
            ) found ORDER BY kind, name, id LIMIT 10", [$t, $key, $t, $key, $t, $key]));
    }

    private function closeReminder(Request $request, string $reminder, string $status): array
    {
        $current = $this->reminder($reminder);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        DB::table('school.lead_reminder')->where('tenant_id', $this->school->id())->where('id', $current['id'])
            ->update(['status' => $status, 'version' => $data['version']]);
        return ['data' => $this->reminder($current['id'])];
    }

    private function converted(string $lead, string $student, string $enrollment): array
    {
        return ['lead' => $this->present($this->find($lead)), 'student_id' => $student, 'enrollment_id' => $enrollment];
    }

    private function present(object $row): array
    {
        $data = $this->records->present($row, 'lead');
        $data['children'] = json_decode($data['children'], true, 8, JSON_THROW_ON_ERROR);
        return $data;
    }

    private function find(string $id, bool $lock = false): object
    {
        $row = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?'.($lock ? ' FOR UPDATE' : ''), [$this->school->id(), $this->uuid($id)]);
        abort_unless($row, 404);
        return $row;
    }

    private function child(string $id): array
    {
        $row = DB::selectOne('SELECT id, lead_id, last_name, first_name, birth_date, level_id, student_id, enrollment_id, converted_at, version, archived_at
            FROM school.lead_child WHERE tenant_id = ? AND id = ?', [$this->school->id(), $this->uuid($id)]);
        abort_unless($row, 404);
        return (array) $row;
    }

    private function reminder(string $id): array
    {
        $row = DB::selectOne(self::REMINDERS.' WHERE tenant_id = ? AND id = ?', [$this->school->id(), $this->uuid($id)]);
        abort_unless($row, 404);
        return (array) $row;
    }
}
