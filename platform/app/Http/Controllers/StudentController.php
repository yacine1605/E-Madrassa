<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\CurrentSchool;
use App\Support\PupilRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StudentController
{
    use HandlesLabels;

    private const SELECT = 'SELECT id, matricule, last_name_ar, first_name_ar, last_name_lat, first_name_lat,
        birth_date, birth_place, gender, phone, email, address, status, status_reason, origin_school, custom, site_id, left_on, anonymised_at,
        photo_file_id IS NOT NULL AS has_photo, version, archived_at, created_at, updated_at FROM school.student';

    public function __construct(private CurrentSchool $school, private PupilRecords $records) {}

    public function index(Request $request) { return $this->records->listing($request, self::SELECT, 'student'); }

    public function show(string $student) { return ['data' => $this->records->present($this->find($student), 'student')]; }

    private function rules(bool $create): array
    {
        return $this->records->rules() + ['birth_date' => ($create ? 'required' : 'sometimes').'|date_format:Y-m-d|before_or_equal:today',
            'birth_place' => 'nullable|string|max:120', 'gender' => 'nullable|in:m,f', 'address' => 'nullable|string|max:300',
            'origin_school' => 'nullable|string|max:200', 'status' => 'prohibited', 'status_reason' => 'prohibited',
            'matricule' => $create ? ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,30}$/'] : 'prohibited'];
    }

    /**
     * FR-ELV-01: the pupil's history, newest first: their record's own entries (SQL 047), and their
     * enrolments' states, groups, guardians and year-end decisions as the reader may see them.
     */
    public function history(string $student)
    {
        $pupil = $this->find($student);
        $t = $this->school->id();
        $events = [];
        foreach (DB::select('SELECT occurred_at, action, to_json(fields) AS fields, status, reason, actor_id FROM school.student_history(?)', [$pupil->id]) as $row) {
            $fields = json_decode($row->fields, true);
            $kind = match (true) {
                $row->action === 'INSERT' => 'created',
                $row->action === 'DELETE' => 'deleted',
                in_array('anonymised_at', $fields, true) => 'anonymised',
                in_array('archived_at', $fields, true) => 'archived',
                // A photo added, replaced or removed (FR-ELV-01): its files, never shown as such.
                $fields !== [] && array_diff($fields, ['photo_file_id', 'photo_thumb_file_id']) === [] => 'photo',
                $row->status !== null => 'status',
                default => 'updated',
            };
            $events[] = ['at' => $row->occurred_at, 'kind' => $kind, 'status' => $row->status,
                'fields' => $kind === 'updated' ? array_values(array_diff($fields, ['photo_file_id', 'photo_thumb_file_id'])) : [],
                'reason' => $row->reason, 'actor_id' => $row->actor_id];
        }
        // Each enrolment's states, with its programme, as the reader may see it.
        foreach (DB::select("SELECT h.changed_at, h.from_status, h.to_status, h.reason, h.changed_by, e.terms_snapshot ->> 'code' AS program
            FROM school.enrollment_status_history h JOIN school.enrollment e ON e.tenant_id = h.tenant_id AND e.id = h.enrollment_id
            WHERE h.tenant_id = ? AND e.student_id = ?", [$t, $pupil->id]) as $row) {
            $events[] = ['at' => $row->changed_at, 'kind' => 'enrollment', 'status' => $row->to_status, 'program' => $row->program,
                'reason' => $row->reason, 'actor_id' => $row->changed_by];
        }
        // The groups joined and left, when it was recorded (a class of next year joined today, from its first day).
        foreach (DB::select('SELECT m.created_at, m.updated_at, m.valid_to, m.created_by, m.updated_by, g.code FROM school.group_membership m
            JOIN school.enrollment e ON e.tenant_id = m.tenant_id AND e.id = m.enrollment_id
            JOIN school.study_group g ON g.tenant_id = m.tenant_id AND g.id = m.group_id WHERE m.tenant_id = ? AND e.student_id = ?', [$t, $pupil->id]) as $row) {
            $events[] = ['at' => $row->created_at, 'kind' => 'joined', 'group' => $row->code, 'actor_id' => $row->created_by];
            if ($row->valid_to !== null) $events[] = ['at' => $row->updated_at, 'kind' => 'left', 'group' => $row->code, 'actor_id' => $row->updated_by];
        }
        // The guardians linked, and unlinked.
        foreach (DB::select('SELECT l.created_at, l.archived_at, l.relationship, l.created_by, g.last_name_ar, g.first_name_ar, g.last_name_lat, g.first_name_lat
            FROM school.student_guardian l JOIN school.guardian g ON g.tenant_id = l.tenant_id AND g.id = l.guardian_id WHERE l.tenant_id = ? AND l.student_id = ?',
            [$t, $pupil->id]) as $row) {
            $person = array_intersect_key((array) $row, array_flip(PupilRecords::NAMES));
            $events[] = ['at' => $row->created_at, 'kind' => 'guardian_linked', 'guardian' => $person, 'relationship' => $row->relationship, 'actor_id' => $row->created_by];
            if ($row->archived_at !== null) $events[] = ['at' => $row->archived_at, 'kind' => 'guardian_unlinked', 'guardian' => $person, 'relationship' => $row->relationship, 'actor_id' => null];
        }
        // The year-end decisions (FR-INS-05).
        foreach (DB::select("SELECT p.created_at, p.decision, p.reason, p.created_by, e.terms_snapshot ->> 'code' AS program FROM school.progression p
            JOIN school.enrollment e ON e.tenant_id = p.tenant_id AND e.id = p.enrollment_id WHERE p.tenant_id = ? AND e.student_id = ?", [$t, $pupil->id]) as $row) {
            $events[] = ['at' => $row->created_at, 'kind' => 'decision', 'decision' => $row->decision, 'program' => $row->program, 'reason' => $row->reason,
                'actor_id' => $row->created_by];
        }
        usort($events, fn (array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));
        $events = array_slice($events, 0, 200);
        $names = collect(DB::select('SELECT id, name FROM public.users WHERE id = ANY (?::bigint[])',
            ['{'.implode(',', array_unique(array_filter(array_column($events, 'actor_id')))).'}']))->pluck('name', 'id');
        return ['data' => array_map(fn (array $event) => [...$event, 'actor' => $event['actor_id'] === null ? null : ($names[$event['actor_id']] ?? null)], $events)];
    }

    public function duplicates(Request $request)
    {
        $data = $request->validate($this->rules(true));
        $this->records->name($data);
        return ['data' => $this->records->duplicates($data)];
    }

    public function store(Request $request)
    {
        $id = $this->admit($request->all());
        return response()->json($this->show($id), 201);
    }

    /**
     * A new pupil from the given fields, as the API records one: the warning of a probable
     * duplicate, unless a reason is given (FR-ELV-04). Also the conversion of a prospect's child
     * (FR-CRM-02), whose fields are named `$prefix`.
     */
    public function admit(array $input, string $prefix = ''): string
    {
        $this->records->createPermission('student.record.create');
        $data = $this->records->validate($input, $this->rules(true) + ['duplicate_reason' => 'nullable|string|min:3|max:500'], $prefix);
        unset($data['tenant_id'], $data['user_id'], $data['photo_file_id'], $data['status'], $data['status_reason']);
        $this->records->name($data, $prefix);
        $this->records->site($data);
        // Serialize the warning with the write: simultaneous admissions also see the first dossier.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 31))', [$this->school->id()]);
        $matches = $this->records->duplicates($data);
        if ($matches && empty($data['duplicate_reason'])) throw Refusal::of('student_duplicate', extra: ['duplicates' => $matches]);
        if ($matches) DB::select("SELECT set_config('app.reason', ?, true)", [$data['duplicate_reason']]);
        unset($data['duplicate_reason']);
        $data['custom'] = $this->records->custom('student', $data['custom'] ?? []);
        return DB::table('school.student')->insertGetId(['tenant_id' => $this->school->id(), ...$data], 'id');
    }

    public function update(Request $request, string $student)
    {
        $current = $this->find($student, true);
        $this->records->requireAction('student.record.update', 'student', $current->id);
        $data = $request->validate($this->rules(false) + ['version' => 'required|integer|min:1']);
        unset($data['tenant_id'], $data['user_id'], $data['photo_file_id'], $data['status'], $data['status_reason'], $data['matricule']);
        $this->records->name(array_replace((array) $current, $data));
        $this->records->site($data);
        if (array_key_exists('custom', $data)) $data['custom'] = $this->records->custom('student', $data['custom'], $current);
        DB::table('school.student')->where('tenant_id', $this->school->id())->where('id', $current->id)->update($data);
        return $this->show($current->id);
    }

    public function status(Request $request, string $student)
    {
        $current = $this->find($student, true);
        $this->records->requireAction('student.record.update', 'student', $current->id);
        $data = $request->validate(['status' => 'required|in:active,former,blocked', 'reason' => 'required|string|min:3|max:500',
            'version' => 'required|integer|min:1', 'confirm_enrollments' => 'boolean']);
        if ((int) $data['version'] !== (int) $current->version) throw Refusal::of('stale_version');
        if ($data['status'] === 'blocked' && $current->status !== 'blocked') {
            $count = DB::selectOne('SELECT school.student_enrollment_count(?) AS n', [$current->id])->n;
            if ($count > 0 && !$request->boolean('confirm_enrollments'))
                throw Refusal::of('student_active_enrollments', extra: ['active_enrollments' => $count]);
        }
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        DB::table('school.student')->where('tenant_id', $this->school->id())->where('id', $current->id)->update([
            'status' => $data['status'], 'status_reason' => $data['status'] === 'blocked' ? $data['reason'] : null, 'version' => $data['version']]);
        return $this->show($current->id);
    }

    public function archive(Request $request, string $student)
    {
        $current = $this->find($student, true);
        $this->records->requireAction('student.record.archive', 'student', $current->id);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500', 'version' => 'required|integer|min:1']);
        if ($current->status !== 'former') throw Refusal::of('student_archive_former');
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        DB::table('school.student')->where('tenant_id', $this->school->id())->where('id', $current->id)
            ->update(['archived_at' => now(), 'version' => $data['version']]);
        return $this->show($current->id);
    }

    private function find(string $id, bool $lock = false): object
    {
        $row = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?'.($lock ? ' FOR UPDATE' : ''), [$this->school->id(), $this->uuid($id)]);
        abort_unless($row, 404);
        return $row;
    }
}
