<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use App\Support\SessionConflicts;
use App\Support\SessionLength;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-PLN-02, FR-PLN-03: the dated sessions, in Algiers' time. One is planned on its own or by its
 * series (SessionSeriesController); changed on its own, it leaves its series (AC2); cancelled with
 * its reason. Before writing, the database refuses any blocking conflict (a teacher, a room, a
 * group or a pupil taken, a teacher not qualified or unavailable, a room closed, elsewhere or too
 * small, a day closed) and the answer tells the warnings (SQL 062). Its teacher is replaced by a
 * qualified, available and free one, with the reason, or given back (FR-PLN-06, SQL 064); each
 * cancellation and replacement is kept in the history of the group's sessions.
 */
final class ClassSessionController
{
    private const SELECT = "SELECT c.id, c.group_id, g.code AS group_code, g.labels AS group_labels, g.site_id, c.subject_id, sj.code AS subject_code,
        sj.labels AS subject_labels, c.teacher_id, st.staff_number AS teacher_number, st.last_name_lat AS teacher_last_name, st.first_name_lat AS teacher_first_name,
        st.last_name_ar AS teacher_last_name_ar, st.first_name_ar AS teacher_first_name_ar, c.room_id, r.code AS room_code, c.series_id,
        school.local_day(c.starts_at)::text AS day, to_char(c.starts_at AT TIME ZONE 'Africa/Algiers', 'HH24:MI') AS start_time,
        CASE WHEN school.local_day(c.ends_at) > school.local_day(c.starts_at) THEN '24:00' ELSE to_char(c.ends_at AT TIME ZONE 'Africa/Algiers', 'HH24:MI') END AS end_time,
        (extract(epoch FROM c.ends_at - c.starts_at) / 60)::int AS minutes, c.status, c.detached, c.locked, c.cancel_reason, c.cancelled_at,
        c.replaced_teacher_id, rt.last_name_lat AS replaced_last_name, rt.first_name_lat AS replaced_first_name, rt.last_name_ar AS replaced_last_name_ar,
        rt.first_name_ar AS replaced_first_name_ar, c.replace_reason, c.replaced_at, c.version
        FROM school.class_session c
        LEFT JOIN school.study_group g ON g.tenant_id = c.tenant_id AND g.id = c.group_id
        LEFT JOIN school.subject sj ON sj.tenant_id = c.tenant_id AND sj.id = c.subject_id
        LEFT JOIN school.staff st ON st.tenant_id = c.tenant_id AND st.id = c.teacher_id
        LEFT JOIN school.room r ON r.tenant_id = c.tenant_id AND r.id = c.room_id
        LEFT JOIN school.staff rt ON rt.tenant_id = c.tenant_id AND rt.id = c.replaced_teacher_id";
    private const CHANGES = "SELECT h.id, h.kind, h.reason, h.changed_at, h.session_id, h.group_id, g.code AS group_code, g.labels AS group_labels,
        sj.code AS subject_code, sj.labels AS subject_labels, school.local_day(c.starts_at)::text AS day,
        to_char(c.starts_at AT TIME ZONE 'Africa/Algiers', 'HH24:MI') AS start_time,
        CASE WHEN school.local_day(c.ends_at) > school.local_day(c.starts_at) THEN '24:00' ELSE to_char(c.ends_at AT TIME ZONE 'Africa/Algiers', 'HH24:MI') END AS end_time,
        h.teacher_id, b.last_name_lat AS teacher_last_name, b.first_name_lat AS teacher_first_name, b.last_name_ar AS teacher_last_name_ar,
        b.first_name_ar AS teacher_first_name_ar, h.new_teacher_id, a.last_name_lat AS new_last_name, a.first_name_lat AS new_first_name,
        a.last_name_ar AS new_last_name_ar, a.first_name_ar AS new_first_name_ar, (SELECT u.name FROM public.users u WHERE u.id = h.changed_by) AS changed_by_name
        FROM school.session_change h
        JOIN school.class_session c ON c.tenant_id = h.tenant_id AND c.id = h.session_id
        LEFT JOIN school.study_group g ON g.tenant_id = h.tenant_id AND g.id = h.group_id
        LEFT JOIN school.subject sj ON sj.tenant_id = c.tenant_id AND sj.id = c.subject_id
        LEFT JOIN school.staff b ON b.tenant_id = h.tenant_id AND b.id = h.teacher_id
        LEFT JOIN school.staff a ON a.tenant_id = h.tenant_id AND a.id = h.new_teacher_id";
    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/';
    /** The longest stretch of days listed at once. */
    private const MAX_DAYS = 62;

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    /** From one day until another (62 days at most), by group, teacher, room or pupil (FR-PLN-04); the cancelled ones with `include_cancelled`. */
    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'group_id' => 'nullable|uuid', 'teacher_id' => 'nullable|uuid', 'room_id' => 'nullable|uuid', 'student_id' => 'nullable|uuid', 'include_cancelled' => 'boolean']);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) >= self::MAX_DAYS) throw Refusal::of('session_range_too_long');
        $sql = self::SELECT." WHERE c.tenant_id = ? AND c.starts_at >= school.local_at(?::date, '00:00') AND c.starts_at < school.local_at(?::date + 1, '00:00')";
        $bindings = [$this->school->id(), $data['from'], $data['to']];
        foreach (['group_id', 'room_id'] as $column) {
            if (isset($data[$column])) { $sql .= " AND c.$column = ?"; $bindings[] = $data[$column]; }
        }
        // A teacher's sessions, and those they were replaced on.
        if (isset($data['teacher_id'])) { $sql .= ' AND (c.teacher_id = ? OR c.replaced_teacher_id = ?)'; array_push($bindings, $data['teacher_id'], $data['teacher_id']); }
        // A pupil the reader may not read is not found.
        if (isset($data['student_id'])) {
            $this->records->find('student', $data['student_id'], columns: 'id');
            $sql .= ' AND '.TimetableController::PUPIL;
            $bindings[] = $data['student_id'];
        }
        if (!$request->boolean('include_cancelled')) $sql .= " AND c.status <> 'cancelled'";
        return Paging::select($request, $sql, $bindings, 'c.starts_at, g.code, c.id', fn ($row) => $this->decoded($row));
    }

    public function show(string $session)
    {
        return ['data' => $this->find($session)];
    }

    /** What a session would conflict with, before planning or changing it. */
    public function check(Request $request)
    {
        $data = $this->timed($request->validate($this->rules() + ['group_id' => 'required|uuid', 'subject_id' => 'required|uuid', 'session_id' => 'nullable|uuid']));
        return ['data' => SessionConflicts::of($data['group_id'], $data['subject_id'], $data['teacher_id'], $data['room_id'] ?? null, $data['day'],
            $data['start_time'], $data['end_time'], $data['session_id'] ?? null)];
    }

    /** One session on its own; the warnings of its hours come with it. */
    public function store(Request $request)
    {
        $data = $this->timed($request->validate($this->rules() + ['group_id' => 'required|uuid', 'subject_id' => 'required|uuid']));
        $warnings = $this->warnings($data);
        $id = DB::selectOne("INSERT INTO school.class_session (tenant_id, group_id, subject_id, teacher_id, room_id, starts_at, ends_at)
            VALUES (?, ?, ?, ?, ?, school.local_at(?::date, ?::time), school.local_at(?::date, ?::time)) RETURNING id",
            [$this->school->id(), $data['group_id'], $data['subject_id'], $data['teacher_id'], $data['room_id'] ?? null, $data['day'], $data['start_time'],
             $data['day'], $data['end_time']])->id;
        return response()->json(['data' => $this->find($id), 'warnings' => $warnings], 201);
    }

    /** Its teacher, room, day or hours: a session of a series leaves it (AC2). */
    public function update(Request $request, string $session)
    {
        $row = (object) $this->find($session);
        $data = $this->timed($request->validate($this->rules() + ['version' => 'required|integer|min:1']), $row);
        $this->records->version($row, (int) $data['version']);
        $warnings = $this->warnings([...$data, 'group_id' => $row->group_id, 'subject_id' => $row->subject_id], $row->id);
        $changed = DB::selectOne("UPDATE school.class_session SET teacher_id = ?, room_id = ?, starts_at = school.local_at(?::date, ?::time),
            ends_at = school.local_at(?::date, ?::time), version = ? WHERE tenant_id = ? AND id = ? RETURNING id",
            [$data['teacher_id'], $data['room_id'] ?? null, $data['day'], $data['start_time'], $data['day'], $data['end_time'], $data['version'],
             $this->school->id(), $row->id]);
        abort_unless($changed, 404);
        return ['data' => $this->find($row->id), 'warnings' => $warnings];
    }

    /** With its reason; its hours are free again, the journal keeps it. */
    public function cancel(Request $request, string $session)
    {
        $row = (object) $this->find($session);
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000', 'version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        $changed = DB::selectOne("UPDATE school.class_session SET status = 'cancelled', cancel_reason = ?, version = ? WHERE tenant_id = ? AND id = ? RETURNING id",
            [$data['reason'], $data['version'], $this->school->id(), $row->id]);
        abort_unless($changed, 404);
        return ['data' => $this->find($row->id)];
    }

    /** FR-PLN-06: the teachers who could replace its teacher: qualified, available and free at its hours; the teacher planned when it is replaced. */
    public function substitutes(string $session)
    {
        $row = (object) $this->find($session);
        return ['data' => array_map(fn (object $teacher) => (array) $teacher, DB::select('SELECT s.id, s.staff_number, s.last_name_lat, s.first_name_lat,
            s.last_name_ar, s.first_name_ar, s.id IS NOT DISTINCT FROM ?::uuid AS planned FROM school.substitutes(?) x JOIN school.staff s ON s.tenant_id = ? AND s.id = x
            ORDER BY s.last_name_lat, s.first_name_lat, s.id', [$row->replaced_teacher_id, $row->id, $this->school->id()]))];
    }

    /**
     * FR-PLN-06: another teacher holds it, with the reason; their conflicts are checked again (AC1). The teacher planned
     * gives it back to them. Its teachers and pupils are told; the warnings of the new teacher's hours come with it.
     */
    public function replace(Request $request, string $session)
    {
        $row = (object) $this->find($session);
        $data = $request->validate(['teacher_id' => 'required|uuid', 'reason' => 'required|string|min:3|max:1000', 'version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        $warnings = $this->warnings(['group_id' => $row->group_id, 'subject_id' => $row->subject_id, 'teacher_id' => $data['teacher_id'], 'room_id' => $row->room_id,
            'day' => $row->day, 'start_time' => $row->start_time, 'end_time' => $row->end_time], $row->id);
        $changed = DB::selectOne("UPDATE school.class_session SET teacher_id = ?, status = 'replaced', replace_reason = ?, version = ? WHERE tenant_id = ? AND id = ? RETURNING id",
            [$data['teacher_id'], $data['reason'], $data['version'], $this->school->id(), $row->id]);
        abort_unless($changed, 404);
        return ['data' => $this->find($row->id), 'warnings' => $warnings];
    }

    /** AC2: the changes of the sessions from one day until another (62 days at most), of a group or all: cancelled, replaced, given back. */
    public function changes(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'group_id' => 'nullable|uuid']);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) >= self::MAX_DAYS) throw Refusal::of('session_range_too_long');
        $sql = self::CHANGES." WHERE h.tenant_id = ? AND c.starts_at >= school.local_at(?::date, '00:00') AND c.starts_at < school.local_at(?::date + 1, '00:00')";
        $bindings = [$this->school->id(), $data['from'], $data['to']];
        if (isset($data['group_id'])) { $sql .= ' AND h.group_id = ?'; $bindings[] = $data['group_id']; }
        return Paging::select($request, $sql, $bindings, 'c.starts_at, g.code, h.changed_at, h.id', fn (object $row) => $this->decoded($row));
    }

    private function rules(): array
    {
        return ['teacher_id' => 'required|uuid', 'room_id' => 'nullable|uuid', 'day' => 'required|date_format:Y-m-d',
            'start_time' => ['required', 'string', 'regex:'.self::TIME], 'end_time' => ['nullable', 'string', 'regex:'.self::TIME]];
    }

    /** Without its end, the length of a session of its subject (FR-PLN-01). */
    private function timed(array $data, ?object $row = null): array
    {
        $data['end_time'] ??= SessionLength::end($data['start_time'], $row->group_id ?? $data['group_id'], $row->subject_id ?? $data['subject_id'], $data['day']);
        return $data;
    }

    /** The warnings only: a blocking conflict is the database's to refuse on writing. */
    private function warnings(array $data, ?string $except = null): array
    {
        return array_values(array_filter(SessionConflicts::of($data['group_id'], $data['subject_id'], $data['teacher_id'], $data['room_id'] ?? null,
            $data['day'], $data['start_time'], $data['end_time'], $except), fn (array $conflict) => !$conflict['blocking']));
    }

    private function find(string $id): array
    {
        $this->records->find('class_session', $id, columns: 'id');
        return $this->decoded(DB::selectOne(self::SELECT.' WHERE c.tenant_id = ? AND c.id = ?', [$this->school->id(), $id]));
    }

    private function decoded(object $row): array
    {
        $row = (array) $row;
        foreach (['group_labels', 'subject_labels'] as $key) $row[$key] = $row[$key] === null ? null : json_decode($row[$key], true);
        return $row;
    }
}
