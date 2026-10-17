<?php

namespace App\Http\Controllers;

use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use App\Support\SessionConflicts;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-PLN-05: the school's timetable over a period, prepared as a version (a copy of its sessions,
 * with its reason), validated, then published by `schedule.timetable.publish`. The database
 * refuses a validation or a publication while a session is in a blocking conflict (AC1) or has
 * changed since the copy; publishing tells the teachers and pupils it changes, and no session's
 * change is told before its day is published (AC2). Two versions, or a version and the sessions
 * as they are, are compared (SQL 065).
 */
final class TimetableVersionController
{
    private const SELECT = "SELECT v.id, v.number, v.date_from::text AS date_from, v.date_to::text AS date_to, v.reason, v.status, v.copied_at,
        v.validated_at, v.published_at, v.created_at, (SELECT u.name FROM public.users u WHERE u.id = v.created_by) AS prepared_by_name,
        (SELECT u.name FROM public.users u WHERE u.id = v.validated_by) AS validated_by_name, (SELECT u.name FROM public.users u WHERE u.id = v.published_by) AS published_by_name,
        (SELECT count(*) FROM school.timetable_version_session s WHERE s.tenant_id = v.tenant_id AND s.version_id = v.id)::int AS sessions, v.version
        FROM school.timetable_version v";

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    /** The newest first; of a state with `status`. */
    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['status' => 'nullable|in:draft,validated,published,superseded,discarded']);
        $sql = self::SELECT.' WHERE v.tenant_id = ?';
        $bindings = [$this->school->id()];
        if (isset($data['status'])) { $sql .= ' AND v.status = ?'; $bindings[] = $data['status']; }
        return Paging::select($request, $sql, $bindings, 'v.number DESC, v.id', fn (object $row) => (array) $row);
    }

    public function show(string $version)
    {
        return ['data' => $this->find($version)];
    }

    /** A version of the sessions from one day until another, with its reason; one at a time open over the same days. */
    public function store(Request $request)
    {
        $data = $request->validate(['date_from' => 'required|date_format:Y-m-d', 'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'reason' => 'required|string|min:3|max:1000']);
        $id = DB::selectOne('SELECT school.prepare_timetable(?::date, ?::date, ?) AS id', [$data['date_from'], $data['date_to'], $data['reason']])->id;
        return response()->json(['data' => $this->find($id)], 201);
    }

    /** Copied again from the sessions as they are: a draft to validate anew. */
    public function refresh(Request $request, string $version)
    {
        return $this->step($request, $version, 'SELECT school.refresh_timetable(?, ?)');
    }

    /** AC1: refused while a session is in a blocking conflict or changed since the copy. */
    public function validateVersion(Request $request, string $version)
    {
        return $this->step($request, $version, 'SELECT school.validate_timetable(?, ?)');
    }

    /** A validated version published, the ones it supersedes set aside; `told` how many people were told. */
    public function publish(Request $request, string $version)
    {
        $row = (object) $this->find($version);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        $told = DB::selectOne('SELECT school.publish_timetable(?, ?) AS n', [$row->id, $data['version']])->n;
        return ['data' => $this->find($row->id), 'told' => (int) $told];
    }

    public function discard(Request $request, string $version)
    {
        return $this->step($request, $version, 'SELECT school.discard_timetable(?, ?)');
    }

    /** AC1: its sessions in a blocking conflict, each with its reason. */
    public function conflicts(string $version)
    {
        $row = (object) $this->find($version);
        $rows = DB::select("SELECT x.session_id, x.conflict, true AS blocking, x.detail, g.code AS group_code, school.local_day(s.starts_at)::text AS day,
            to_char(s.starts_at AT TIME ZONE 'Africa/Algiers', 'HH24:MI') AS start_time FROM school.timetable_conflicts(?) x
            JOIN school.timetable_version_session s ON s.tenant_id = ? AND s.version_id = ? AND s.session_id = x.session_id
            LEFT JOIN school.study_group g ON g.tenant_id = s.tenant_id AND g.id = s.group_id", [$row->id, $this->school->id(), $row->id]);
        $presented = SessionConflicts::present($rows);
        return ['data' => array_map(fn (object $source, array $conflict) => $conflict + ['session_id' => $source->session_id, 'group_code' => $source->group_code,
            'day' => $source->day, 'start_time' => $source->start_time], $rows, $presented)];
    }

    /** Its differences with another version (`with`), or with the sessions as they are now: each session added, removed or changed. */
    public function compare(Request $request, string $version)
    {
        $row = (object) $this->find($version);
        $data = $request->validate(['with' => 'nullable|uuid']);
        if (isset($data['with'])) $this->find($data['with']);
        $rows = DB::select('SELECT session_id, change, before, after FROM school.timetable_diff(?, ?)', [$row->id, $data['with'] ?? null]);
        $sides = array_map(fn (object $diff) => [json_decode((string) $diff->before, true), json_decode((string) $diff->after, true)], $rows);
        $names = $this->names(array_merge(...array_map(fn (array $pair) => array_filter($pair), $sides)));
        $listed = array_map(fn (object $diff, array $pair) => ['session_id' => $diff->session_id, 'change' => $diff->change,
            'before' => $this->described($pair[0], $names), 'after' => $this->described($pair[1], $names)], $rows, $sides);
        usort($listed, fn (array $a, array $b) => [($a['before'] ?? $a['after'])['starts_at'], $a['session_id']] <=> [($b['before'] ?? $b['after'])['starts_at'], $b['session_id']]);
        return ['data' => $listed];
    }

    private function step(Request $request, string $version, string $sql)
    {
        $row = (object) $this->find($version);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::select($sql, [$row->id, $data['version']]);
        return ['data' => $this->find($row->id)];
    }

    /** The codes and names of the groups, subjects, teachers and rooms the sessions name, as the reader may read them. */
    private function names(array $sessions): array
    {
        $ids = fn (string $key) => array_values(array_unique(array_filter(array_column($sessions, $key))));
        $lookup = fn (string $table, string $column, array $values) => $values === [] ? [] : collect(DB::table("school.$table")
            ->where('tenant_id', $this->school->id())->whereIn('id', $values)->get(['id', ...$column === 'code' ? ['code'] : ['last_name_lat', 'first_name_lat', 'last_name_ar', 'first_name_ar']]))
            ->mapWithKeys(fn (object $r) => [$r->id => $column === 'code' ? $r->code : $this->teacher($r)])->all();
        return ['group' => $lookup('study_group', 'code', $ids('group_id')), 'subject' => $lookup('subject', 'code', $ids('subject_id')),
            'teacher' => $lookup('staff', 'name', $ids('teacher_id')), 'room' => $lookup('room', 'code', $ids('room_id'))];
    }

    private function described(?array $session, array $names): ?array
    {
        if ($session === null) return null;
        $start = Carbon::parse($session['starts_at'])->timezone('Africa/Algiers');
        $end = Carbon::parse($session['ends_at'])->timezone('Africa/Algiers');
        return ['starts_at' => $start->toIso8601String(), 'day' => $start->toDateString(), 'start_time' => $start->format('H:i'),
            'end_time' => $end->toDateString() > $start->toDateString() ? '24:00' : $end->format('H:i'), 'status' => $session['status'],
            'group_id' => $session['group_id'], 'group_code' => $names['group'][$session['group_id']] ?? null,
            'subject_code' => $names['subject'][$session['subject_id']] ?? null, 'teacher_id' => $session['teacher_id'],
            'teacher' => $names['teacher'][$session['teacher_id']] ?? null, 'room_code' => $session['room_id'] ? ($names['room'][$session['room_id']] ?? null) : null];
    }

    private function teacher(object $row): ?string
    {
        $latin = trim(($row->last_name_lat ?? '').' '.($row->first_name_lat ?? ''));
        $arabic = trim(($row->last_name_ar ?? '').' '.($row->first_name_ar ?? ''));
        return (app()->getLocale() === 'ar' ? ($arabic ?: $latin) : ($latin ?: $arabic)) ?: null;
    }

    private function find(string $id): array
    {
        $this->records->find('timetable_version', $id, columns: 'id');
        return (array) DB::selectOne(self::SELECT.' WHERE v.tenant_id = ? AND v.id = ?', [$this->school->id(), $id]);
    }
}
