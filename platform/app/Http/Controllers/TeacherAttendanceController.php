<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\DatabaseErrors;
use App\Support\Paging;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-PTG-01, FR-PTG-02: the teachers' attendance, session by session. The sessions begun over a
 * stretch of days are listed with their entry, or none: a session not entered is « not entered »,
 * never an absence (AC1). The supervisor enters present, late, absent or replaced; its author
 * corrects it until it is decided; another person validates or rejects it, never its author
 * (TST-05), one by one or all those of the period at once. The database holds each rule (SQL 066).
 */
final class TeacherAttendanceController
{
    private const SELECT = "SELECT c.id AS session_id, c.group_id, g.code AS group_code, g.site_id, sj.code AS subject_code, sj.labels AS subject_labels,
        c.teacher_id, st.last_name_lat AS teacher_last_name, st.first_name_lat AS teacher_first_name, st.last_name_ar AS teacher_last_name_ar,
        st.first_name_ar AS teacher_first_name_ar, r.code AS room_code, school.local_day(c.starts_at)::text AS day,
        to_char(c.starts_at AT TIME ZONE 'Africa/Algiers', 'HH24:MI') AS start_time,
        CASE WHEN school.local_day(c.ends_at) > school.local_day(c.starts_at) THEN '24:00' ELSE to_char(c.ends_at AT TIME ZONE 'Africa/Algiers', 'HH24:MI') END AS end_time,
        c.status AS session_status, a.id AS attendance_id, a.status, a.late_minutes, to_char(a.actual_start, 'HH24:MI') AS actual_start,
        to_char(a.actual_end, 'HH24:MI') AS actual_end, a.replacement_teacher_id, rt.last_name_lat AS replacement_last_name, rt.first_name_lat AS replacement_first_name,
        rt.last_name_ar AS replacement_last_name_ar, rt.first_name_ar AS replacement_first_name_ar, a.note, a.validation_status, a.reject_reason,
        a.created_by AS entered_by, (SELECT u.name FROM public.users u WHERE u.id = a.created_by) AS entered_by_name, a.created_at AS entered_at,
        (SELECT u.name FROM public.users u WHERE u.id = a.decided_by) AS decided_by_name, a.decided_at, a.version
        FROM school.class_session c
        LEFT JOIN school.teacher_attendance a ON a.tenant_id = c.tenant_id AND a.session_id = c.id
        LEFT JOIN school.study_group g ON g.tenant_id = c.tenant_id AND g.id = c.group_id
        LEFT JOIN school.subject sj ON sj.tenant_id = c.tenant_id AND sj.id = c.subject_id
        LEFT JOIN school.staff st ON st.tenant_id = c.tenant_id AND st.id = c.teacher_id
        LEFT JOIN school.staff rt ON rt.tenant_id = a.tenant_id AND rt.id = a.replacement_teacher_id
        LEFT JOIN school.room r ON r.tenant_id = c.tenant_id AND r.id = c.room_id";
    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';
    /** The longest stretch of days listed at once: a month. */
    private const MAX_DAYS = 31;

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    /**
     * The sessions begun from one day until another (31 at most), not cancelled, with their entry;
     * `state` not_entered, submitted, validated or rejected; by group or teacher.
     */
    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'state' => 'nullable|in:not_entered,submitted,validated,rejected', 'group_id' => 'nullable|uuid', 'teacher_id' => 'nullable|uuid']);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) >= self::MAX_DAYS) throw Refusal::of('attendance_range_too_long');
        $sql = self::SELECT." WHERE c.tenant_id = ? AND c.status <> 'cancelled' AND c.starts_at <= now()
            AND c.starts_at >= school.local_at(?::date, '00:00') AND c.starts_at < school.local_at(?::date + 1, '00:00')";
        $bindings = [$this->school->id(), $data['from'], $data['to']];
        if (isset($data['group_id'])) { $sql .= ' AND c.group_id = ?'; $bindings[] = $data['group_id']; }
        if (isset($data['teacher_id'])) { $sql .= ' AND c.teacher_id = ?'; $bindings[] = $data['teacher_id']; }
        if (isset($data['state'])) {
            if ($data['state'] === 'not_entered') $sql .= ' AND a.id IS NULL';
            else { $sql .= ' AND a.validation_status = ?'; $bindings[] = $data['state']; }
        }
        return Paging::select($request, $sql, $bindings, 'c.starts_at, g.code, c.id', fn (object $row) => $this->decoded($row));
    }

    /** FR-PTG-01: a session's entry; AC2, a session to come refused. */
    public function store(Request $request)
    {
        $data = $request->validate($this->rules() + ['session_id' => 'required|uuid']);
        $this->records->find('class_session', $data['session_id'], columns: 'id');
        $id = DB::selectOne('INSERT INTO school.teacher_attendance (tenant_id, session_id, status, late_minutes, actual_start, actual_end, replacement_teacher_id, note)
            VALUES (?, ?, ?, ?, ?::time, ?::time, ?, ?) RETURNING id', [$this->school->id(), $data['session_id'], ...$this->values($data)])->id;
        return response()->json(['data' => $this->find($id)], 201);
    }

    /** Corrected by its author until decided; submitted again. */
    public function update(Request $request, string $attendance)
    {
        $row = (object) $this->find($attendance);
        $data = $request->validate($this->rules() + ['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::update('UPDATE school.teacher_attendance SET status = ?, late_minutes = ?, actual_start = ?::time, actual_end = ?::time, replacement_teacher_id = ?,
            note = ?, version = ? WHERE tenant_id = ? AND id = ?', [...$this->values($data), $data['version'], $this->school->id(), $row->attendance_id]);
        return ['data' => $this->find($row->attendance_id)];
    }

    /** FR-PTG-02: validated by another person than its author (TST-05). */
    public function approve(Request $request, string $attendance)
    {
        $row = (object) $this->find($attendance);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::update("UPDATE school.teacher_attendance SET validation_status = 'validated', version = ? WHERE tenant_id = ? AND id = ?",
            [$data['version'], $this->school->id(), $row->attendance_id]);
        return ['data' => $this->find($row->attendance_id)];
    }

    /** Rejected with its reason, back to its author, who is told. */
    public function reject(Request $request, string $attendance)
    {
        $row = (object) $this->find($attendance);
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000', 'version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::update("UPDATE school.teacher_attendance SET validation_status = 'rejected', reject_reason = ?, version = ? WHERE tenant_id = ? AND id = ?",
            [$data['reason'], $data['version'], $this->school->id(), $row->attendance_id]);
        return ['data' => $this->find($row->attendance_id)];
    }

    /**
     * FR-PTG-02: the entries submitted from one day until another validated at once, those of others
     * only (the reader's own are left, BR-08); each refused one said with its reason.
     */
    public function approvePeriod(Request $request)
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'group_id' => 'nullable|uuid']);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) >= self::MAX_DAYS) throw Refusal::of('attendance_range_too_long');
        $sql = "SELECT a.id, a.version FROM school.teacher_attendance a JOIN school.class_session c ON c.tenant_id = a.tenant_id AND c.id = a.session_id
            WHERE a.tenant_id = ? AND a.validation_status = 'submitted' AND a.created_by IS DISTINCT FROM school.actor()
            AND c.starts_at >= school.local_at(?::date, '00:00') AND c.starts_at < school.local_at(?::date + 1, '00:00')";
        $bindings = [$this->school->id(), $data['from'], $data['to']];
        if (isset($data['group_id'])) { $sql .= ' AND a.group_id = ?'; $bindings[] = $data['group_id']; }
        $validated = 0;
        $refused = [];
        foreach (DB::select($sql.' ORDER BY c.starts_at, a.id', $bindings) as $entry) {
            try {
                DB::transaction(fn () => DB::update("UPDATE school.teacher_attendance SET validation_status = 'validated', version = ? WHERE tenant_id = ? AND id = ?",
                    [$entry->version, $this->school->id(), $entry->id]));
                $validated++;
            } catch (QueryException $e) {
                $known = DatabaseErrors::classify($e);
                if ($known === null) throw $e;
                $refused[] = ['id' => $entry->id, 'code' => $known[1], 'message' => $known[2]];
            }
        }
        $own = DB::selectOne("SELECT count(*) AS n FROM school.teacher_attendance a JOIN school.class_session c ON c.tenant_id = a.tenant_id AND c.id = a.session_id
            WHERE a.tenant_id = ? AND a.validation_status = 'submitted' AND a.created_by = school.actor()
            AND c.starts_at >= school.local_at(?::date, '00:00') AND c.starts_at < school.local_at(?::date + 1, '00:00')", array_slice($bindings, 0, 3))->n;
        return ['data' => ['validated' => $validated, 'own_left' => (int) $own, 'refused' => $refused]];
    }

    private function rules(): array
    {
        return ['status' => 'required|in:present,late,absent,replaced', 'late_minutes' => 'nullable|integer|min:1|max:600',
            'actual_start' => ['nullable', 'string', 'regex:'.self::TIME], 'actual_end' => ['nullable', 'string', 'regex:'.self::TIME],
            'replacement_teacher_id' => 'nullable|uuid', 'note' => 'nullable|string|max:1000'];
    }

    /** The entry's values, those that do not belong to its state left out. */
    private function values(array $data): array
    {
        return [$data['status'], $data['status'] === 'late' ? ($data['late_minutes'] ?? null) : null,
            $data['status'] === 'absent' ? null : ($data['actual_start'] ?? null), $data['status'] === 'absent' ? null : ($data['actual_end'] ?? null),
            $data['status'] === 'replaced' ? ($data['replacement_teacher_id'] ?? null) : null, $data['note'] ?? null];
    }

    private function find(string $id): array
    {
        $this->records->find('teacher_attendance', $id, columns: 'id');
        return $this->decoded(DB::selectOne(self::SELECT.' WHERE c.tenant_id = ? AND a.id = ?', [$this->school->id(), $id]));
    }

    private function decoded(object $row): array
    {
        $row = (array) $row;
        $row['id'] = $row['session_id'];
        $row['subject_labels'] = $row['subject_labels'] === null ? null : json_decode($row['subject_labels'], true);
        return $row;
    }
}
