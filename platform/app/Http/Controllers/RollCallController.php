<?php

namespace App\Http\Controllers;

use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-PRE-01: the pupils' roll call, session by session. The sessions begun on a day that the reader
 * calls: a teacher their own, the supervisor those of the school or their sites. A session's roll
 * lists the pupils its group has that day (AC1), each with their mark or none: a roll not called
 * is no absence. Marks are saved together; a change keeps the former value in the history (AC2).
 * The database holds each rule (SQL 068).
 */
final class RollCallController
{
    private const SESSIONS = "SELECT c.id, c.group_id, g.code AS group_code, g.site_id, sj.code AS subject_code, sj.labels AS subject_labels, c.teacher_id,
        st.last_name_lat AS teacher_last_name, st.first_name_lat AS teacher_first_name, st.last_name_ar AS teacher_last_name_ar, st.first_name_ar AS teacher_first_name_ar,
        school.local_day(c.starts_at)::text AS day, to_char(c.starts_at AT TIME ZONE 'Africa/Algiers', 'HH24:MI') AS start_time,
        CASE WHEN school.local_day(c.ends_at) > school.local_day(c.starts_at) THEN '24:00' ELSE to_char(c.ends_at AT TIME ZONE 'Africa/Algiers', 'HH24:MI') END AS end_time,
        (SELECT count(*) FROM school.roll_pupils(c.id))::int AS pupils,
        (SELECT count(*) FROM school.student_attendance x WHERE x.tenant_id = c.tenant_id AND x.session_id = c.id)::int AS marked,
        (SELECT count(*) FROM school.student_attendance x WHERE x.tenant_id = c.tenant_id AND x.session_id = c.id AND x.status = 'absent')::int AS absent
        FROM school.class_session c
        LEFT JOIN school.study_group g ON g.tenant_id = c.tenant_id AND g.id = c.group_id
        LEFT JOIN school.subject sj ON sj.tenant_id = c.tenant_id AND sj.id = c.subject_id
        LEFT JOIN school.staff st ON st.tenant_id = c.tenant_id AND st.id = c.teacher_id";

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    /** The sessions begun on a day, not cancelled, that the reader calls. */
    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['day' => 'required|date_format:Y-m-d']);
        $sql = self::SESSIONS." WHERE c.tenant_id = ? AND c.status <> 'cancelled' AND c.starts_at <= now()
            AND c.starts_at >= school.local_at(?::date, '00:00') AND c.starts_at < school.local_at(?::date + 1, '00:00')
            AND (school.can_everywhere('attendance.student.entry.create')
              OR c.group_id = ANY (school.site_scope_ids('attendance.student.entry.create', 'study_group')) OR school.is_own_staff(c.teacher_id))";
        return Paging::select($request, $sql, [$this->school->id(), $data['day'], $data['day']], 'c.starts_at, g.code, c.id', fn (object $row) => $this->decoded($row));
    }

    /** AC1: the pupils the session's group has that day, each with their mark or none. */
    public function show(string $session)
    {
        $this->records->find('class_session', $session, columns: 'id');
        return ['data' => $this->roll($session)];
    }

    /**
     * The marks of a roll saved together: a pupil marked for the first time, or changed; one left
     * out keeps their mark or none. `entries`: [{student_id, status, late_minutes?, note?}].
     */
    public function store(Request $request, string $session)
    {
        $this->records->find('class_session', $session, columns: 'id');
        $data = $request->validate(['entries' => 'required|array|min:1|max:200', 'entries.*.student_id' => 'required|uuid',
            'entries.*.status' => 'required|in:present,absent,late,excused', 'entries.*.late_minutes' => 'nullable|integer|min:1|max:600',
            'entries.*.note' => 'nullable|string|max:500']);
        DB::transaction(function () use ($data, $session) {
            foreach ($data['entries'] as $entry) {
                $late = $entry['status'] === 'late' ? ($entry['late_minutes'] ?? null) : null;
                $changed = DB::update('UPDATE school.student_attendance SET status = ?, late_minutes = ?, note = ?
                    WHERE tenant_id = ? AND session_id = ? AND student_id = ? AND (status, late_minutes, note) IS DISTINCT FROM (?, ?::integer, ?)',
                    [$entry['status'], $late, $entry['note'] ?? null, $this->school->id(), $session, $entry['student_id'], $entry['status'], $late, $entry['note'] ?? null]);
                if ($changed === 0 && !DB::selectOne('SELECT 1 AS x FROM school.student_attendance WHERE tenant_id = ? AND session_id = ? AND student_id = ?',
                    [$this->school->id(), $session, $entry['student_id']])) {
                    DB::insert('INSERT INTO school.student_attendance (tenant_id, session_id, student_id, status, late_minutes, note) VALUES (?, ?, ?, ?, ?, ?)',
                        [$this->school->id(), $session, $entry['student_id'], $entry['status'], $late, $entry['note'] ?? null]);
                }
            }
        });
        return ['data' => $this->roll($session)];
    }

    /** AC2: the changes of the session's marks, newest first, with the former values. */
    public function history(string $session)
    {
        $this->records->find('class_session', $session, columns: 'id');
        return ['data' => array_map(fn (object $row) => (array) $row, DB::select("SELECT h.id, h.student_id, s.matricule, s.last_name_lat, s.first_name_lat,
            s.last_name_ar, s.first_name_ar, h.previous_status, h.previous_late_minutes, h.previous_note, h.status, h.late_minutes, h.note, h.changed_at,
            (SELECT u.name FROM public.users u WHERE u.id = h.changed_by) AS changed_by_name
            FROM school.student_attendance_change h LEFT JOIN school.student s ON s.tenant_id = h.tenant_id AND s.id = h.student_id
            WHERE h.tenant_id = ? AND h.session_id = ? ORDER BY h.changed_at DESC, h.id", [$this->school->id(), $session]))];
    }

    private function roll(string $session): array
    {
        return array_map(fn (object $row) => ['id' => $row->student_id, ...((array) $row)], DB::select("SELECT p.student_id, s.matricule, s.last_name_lat, s.first_name_lat,
            s.last_name_ar, s.first_name_ar, a.id AS attendance_id, a.status, a.late_minutes, a.note, a.updated_at
            FROM school.roll_pupils(?) p LEFT JOIN school.student s ON s.tenant_id = ? AND s.id = p.student_id
            LEFT JOIN school.student_attendance a ON a.tenant_id = ? AND a.session_id = ? AND a.student_id = p.student_id
            ORDER BY s.last_name_lat, s.first_name_lat, s.last_name_ar, p.student_id", [$session, $this->school->id(), $this->school->id(), $session]));
    }

    private function decoded(object $row): array
    {
        $row = (array) $row;
        $row['subject_labels'] = $row['subject_labels'] === null ? null : json_decode($row['subject_labels'], true);
        return $row;
    }
}
