<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\SessionConflicts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-PLN-02: a group's weekly course, planned once per key with its sessions on the open days of
 * the group's site (AC1: closures and closed days skipped); a blocking conflict on any day refuses
 * the whole series. Changed from a day on, it changes its sessions still planned, except those
 * changed on their own (AC2) or locked by their attendance; ended sooner, those after its new
 * last day go (SQL 062). A group's volume follow-up compares what is planned with its programme.
 */
final class SessionSeriesController
{
    private const SELECT = "SELECT s.id, s.group_id, s.subject_id, sj.code AS subject_code, sj.labels AS subject_labels, s.teacher_id,
        st.last_name_lat AS teacher_last_name, st.first_name_lat AS teacher_first_name, s.room_id, r.code AS room_code, s.weekday,
        to_char(s.start_time, 'HH24:MI') AS start_time, CASE WHEN s.end_time = '24:00' THEN '24:00' ELSE to_char(s.end_time, 'HH24:MI') END AS end_time,
        s.slot_id, s.date_from::text AS date_from, s.date_to::text AS date_to, s.version,
        (SELECT count(*) FROM school.class_session c WHERE c.tenant_id = s.tenant_id AND c.series_id = s.id AND c.status <> 'cancelled') AS sessions,
        (SELECT count(*) FROM school.class_session c WHERE c.tenant_id = s.tenant_id AND c.series_id = s.id AND c.detached) AS detached
        FROM school.session_series s
        LEFT JOIN school.subject sj ON sj.tenant_id = s.tenant_id AND sj.id = s.subject_id
        LEFT JOIN school.staff st ON st.tenant_id = s.tenant_id AND st.id = s.teacher_id
        LEFT JOIN school.room r ON r.tenant_id = s.tenant_id AND r.id = s.room_id";
    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/';

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    /** A group's series, in the week's order. */
    public function index(Request $request)
    {
        $data = $request->validate(['group_id' => 'required|uuid']);
        return ['data' => array_map(fn ($row) => $this->decoded($row), DB::select(self::SELECT.' WHERE s.tenant_id = ? AND s.group_id = ?
            ORDER BY s.weekday, s.start_time, s.date_from', [$this->school->id(), $data['group_id']]))];
    }

    /** What the series would plan, day by day: the closed days skipped, each other day's conflicts. */
    public function check(Request $request)
    {
        $data = $this->validated($request);
        [$start, $end] = $this->hours($data);
        $rows = DB::select('SELECT day::text AS day, conflict, blocking, detail FROM school.series_conflicts(?, ?, ?, ?, ?, ?::time, ?::time, ?::date, ?::date)
            ORDER BY day, blocking DESC, conflict', [$data['group_id'], $data['subject_id'], $data['teacher_id'], $data['room_id'] ?? null, $data['weekday'],
            $start, $end, $data['date_from'], $data['date_to']]);
        return ['data' => SessionConflicts::present($rows)];
    }

    /** Planned once per key; the warnings of its days, read before, come with it. */
    public function store(Request $request)
    {
        $data = $this->validated($request) + $request->validate(['idempotency_key' => 'required|uuid']);
        [$start, $end] = $this->hours($data);
        $warnings = array_values(array_filter(SessionConflicts::present(DB::select('SELECT day::text AS day, conflict, blocking, detail
            FROM school.series_conflicts(?, ?, ?, ?, ?, ?::time, ?::time, ?::date, ?::date) ORDER BY day, conflict', [$data['group_id'], $data['subject_id'],
            $data['teacher_id'], $data['room_id'] ?? null, $data['weekday'], $start, $end, $data['date_from'], $data['date_to']])), fn ($c) => !$c['blocking']));
        // A slot gives its own hours.
        $slot = $data['slot_id'] ?? null;
        $id = DB::selectOne('SELECT school.plan_series(?, ?, ?, ?, ?, ?::time, ?::time, ?, ?::date, ?::date, ?) AS id', [$data['group_id'], $data['subject_id'],
            $data['teacher_id'], $data['room_id'] ?? null, $data['weekday'], $slot ? null : $start, $slot ? null : $end, $slot, $data['date_from'],
            $data['date_to'], $data['idempotency_key']])->id;
        return response()->json(['data' => $this->find($id), 'warnings' => $warnings], 201);
    }

    /** From a day on (today or later): its teacher, room and hours, for its sessions still planned and not changed on their own. */
    public function change(Request $request, string $series)
    {
        $this->records->find('session_series', $series, columns: 'id');
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'teacher_id' => 'required|uuid', 'room_id' => 'nullable|uuid',
            'start_time' => ['required', 'string', 'regex:'.self::TIME], 'end_time' => ['required', 'string', 'regex:'.self::TIME], 'version' => 'required|integer|min:1']);
        $changed = (int) DB::selectOne('SELECT school.change_series(?, ?::date, ?, ?, ?::time, ?::time, ?) AS n', [$series, $data['from'], $data['teacher_id'],
            $data['room_id'] ?? null, $data['start_time'], $data['end_time'], $data['version']])->n;
        return ['data' => $this->find($series), 'changed' => $changed];
    }

    /** Sooner than planned: after its new last day (yesterday or later), its sessions still planned go. */
    public function end(Request $request, string $series)
    {
        $this->records->find('session_series', $series, columns: 'id');
        $data = $request->validate(['last_day' => 'required|date_format:Y-m-d', 'version' => 'required|integer|min:1']);
        $removed = (int) DB::selectOne('SELECT school.end_series(?, ?::date, ?) AS n', [$series, $data['last_day'], $data['version']])->n;
        return ['data' => $this->find($series), 'removed' => $removed];
    }

    /** FR-PLN-02: by subject, the sessions and hours planned and delivered against the programme's volume in force today. */
    public function volume(string $group)
    {
        $this->records->find('study_group', $group, columns: 'id');
        return ['data' => array_map(fn ($row) => $this->decoded($row), DB::select('SELECT v.subject_id, sj.code AS subject_code, sj.labels AS subject_labels,
            v.planned_sessions, v.planned_hours::text AS planned_hours, v.delivered_sessions, v.delivered_hours::text AS delivered_hours, v.volume_sessions,
            v.volume_hours::text AS volume_hours, v.weekly_hours::text AS weekly_hours,
            CASE WHEN v.volume_hours IS NOT NULL THEN (v.volume_hours - v.planned_hours)::text END AS hours_left,
            CASE WHEN v.volume_sessions IS NOT NULL THEN v.volume_sessions - v.planned_sessions END AS sessions_left
            FROM school.session_volume(?) v LEFT JOIN school.subject sj ON sj.tenant_id = ? AND sj.id = v.subject_id ORDER BY sj.code', [$group, $this->school->id()]))];
    }

    private function validated(Request $request): array
    {
        return $request->validate(['group_id' => 'required|uuid', 'subject_id' => 'required|uuid', 'teacher_id' => 'required|uuid', 'room_id' => 'nullable|uuid',
            'weekday' => 'required|integer|min:0|max:6', 'slot_id' => 'nullable|uuid', 'start_time' => ['required_without:slot_id', 'nullable', 'string', 'regex:'.self::TIME],
            'end_time' => ['required_without:slot_id', 'nullable', 'string', 'regex:'.self::TIME], 'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from']);
    }

    /** The hours: the slot's when one is named, still offered. */
    private function hours(array $data): array
    {
        if (!($data['slot_id'] ?? null)) return [$data['start_time'], $data['end_time']];
        $slot = DB::selectOne("SELECT to_char(start_time, 'HH24:MI') AS s, CASE WHEN end_time = '24:00' THEN '24:00' ELSE to_char(end_time, 'HH24:MI') END AS e
            FROM school.time_slot WHERE tenant_id = ? AND id = ? AND archived_at IS NULL", [$this->school->id(), $data['slot_id']]);
        if (!$slot) throw Refusal::of('time_slot_archived');
        return [$slot->s, $slot->e];
    }

    private function find(string $id): array
    {
        return $this->decoded(DB::selectOne(self::SELECT.' WHERE s.tenant_id = ? AND s.id = ?', [$this->school->id(), $id]));
    }

    private function decoded(object $row): array
    {
        $row = (array) $row;
        if (array_key_exists('subject_labels', $row)) $row['subject_labels'] = $row['subject_labels'] === null ? null : json_decode($row['subject_labels'], true);
        return $row;
    }
}
