<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use App\Support\DashboardCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-TDB-02: the operational dashboard, its figures counted from the rows when read (SQL 070), for
 * the reader's groups: a group's fill (members over capacity, as its composition list counts them:
 * AC1); over a period, its sessions held, their teacher's attendance entered and validated, those
 * entered by no one, their roll calls and the pupils' absences; for the whole school, enrolments
 * waiting and prospects converted. A group's sessions behind its figures are listed as the reader
 * may read them (FR-TDB-03); its pupils' absences, and week by week the absences of the reader's
 * groups or of one of them, the trend (SQL 072). The figures are kept for their reader until anything
 * changes in the school (DashboardCache); `fresh` computes them again.
 */
final class OperationsDashboardController
{
    private const GROUP = ['capacity', 'members', 'sessions_held', 'teacher_entered', 'teacher_validated', 'teacher_missing', 'roll_taken',
        'roll_missing', 'marks', 'absent', 'late', 'excused'];

    public function __construct(private CurrentSchool $school) {}

    /** The reader's groups with their figures over the period (400 days at most); the school's figures for whoever reads them. */
    public function index(Request $request)
    {
        [$from, $to] = $this->period($request);
        return $this->figures($from, $to, $request->boolean('fresh'));
    }

    /** The figures over a period, as the screen and its exports show them (FR-TDB-03). */
    public function figures(string $from, string $to, bool $fresh = false): array
    {
        return DashboardCache::remember('operations', [$from, $to], fn () => $this->computed($from, $to), $fresh);
    }

    private function computed(string $from, string $to): array
    {
        $groups = [];
        $school = [];
        foreach (DB::select('SELECT group_id, indicator, value FROM school.operations_dashboard(?::date, ?::date)', [$from, $to]) as $row) {
            if ($row->group_id === null) { $school[$row->indicator] = $row->value; continue; }
            $groups[$row->group_id][$row->indicator] = $row->value;
        }
        $names = $groups === [] ? [] : collect(DB::select('SELECT g.id, g.code, g.labels, g.site_id, p.code AS program_code FROM school.study_group g
            LEFT JOIN school.program p ON p.tenant_id = g.tenant_id AND p.id = g.program_id WHERE g.tenant_id = ? AND g.id = ANY (?::uuid[])',
            [$this->school->id(), '{'.implode(',', array_keys($groups)).'}']))->keyBy('id');
        $rows = [];
        foreach ($groups as $id => $figures) {
            $name = $names[$id] ?? null;
            $row = ['id' => $id, 'group_id' => $id, 'code' => $name?->code, 'labels' => $name ? json_decode($name->labels, true) : null,
                'program_code' => $name?->program_code] + array_fill_keys(self::GROUP, null);
            foreach ($figures as $key => $value) $row[$key] = $value;
            $row['fill_rate'] = $this->rate($row['members'], $row['capacity']);
            $row['teacher_validated_rate'] = $this->rate($row['teacher_validated'], $row['sessions_held']);
            $row['absence_rate'] = $this->rate($row['absent'], $row['marks']);
            $rows[] = $row;
        }
        usort($rows, fn (array $a, array $b) => [$a['code'] === null, $a['code']] <=> [$b['code'] === null, $b['code']]);
        $figures = $school === [] ? null : $school;
        if (isset($school['leads_created'])) $figures['conversion_rate'] = $this->rate($school['leads_registered'], $school['leads_created']);
        return ['data' => $rows, 'meta' => ['school' => $figures, 'from' => $from, 'to' => $to, 'computed_at' => now()->toIso8601String()]];
    }

    /** FR-TDB-03: a group's sessions held in the period, with their teacher's attendance and roll call, those the reader may read. */
    public function sessions(Request $request)
    {
        [$from, $to] = $this->period($request);
        $group = $request->validate(['group' => 'required|uuid'])['group'];
        return ['data' => array_map(fn (object $row) => (array) $row, DB::select("SELECT x.session_id AS id, school.local_day(c.starts_at)::text AS day,
            to_char(c.starts_at AT TIME ZONE 'Africa/Algiers', 'HH24:MI') AS start_time, sj.code AS subject_code,
            trim(coalesce(st.last_name_lat, '') || ' ' || coalesce(st.first_name_lat, '')) AS teacher, x.teacher_status, x.roll_marks, x.absent
            FROM school.operations_sessions(?::uuid, ?::date, ?::date) x
            JOIN school.class_session c ON c.tenant_id = ? AND c.id = x.session_id
            LEFT JOIN school.subject sj ON sj.tenant_id = c.tenant_id AND sj.id = c.subject_id
            LEFT JOIN school.staff st ON st.tenant_id = c.tenant_id AND st.id = c.teacher_id
            ORDER BY c.starts_at, c.id", [$group, $from, $to, $this->school->id()]))];
    }

    /** A group's pupils marked in the period, the most absent first; named where the reader reads their record (029). */
    public function pupils(Request $request)
    {
        [$from, $to] = $this->period($request);
        $group = $request->validate(['group' => 'required|uuid'])['group'];
        return ['data' => array_map(function (object $row) {
            $row = (array) $row;
            $row['absence_rate'] = $this->rate($row['absent'], $row['marks']);
            return $row;
        }, DB::select('SELECT x.student_id AS id, s.id IS NOT NULL AS readable, s.matricule, s.last_name_lat, s.first_name_lat, s.last_name_ar, s.first_name_ar,
            x.marks, x.absent, x.late, x.excused, x.last_absence::text AS last_absence
            FROM school.operations_pupils(?::uuid, ?::date, ?::date) x LEFT JOIN school.student s ON s.tenant_id = ? AND s.id = x.student_id
            ORDER BY x.absent DESC, x.late DESC, s.last_name_lat, s.first_name_lat, s.last_name_ar, x.student_id', [$group, $from, $to, $this->school->id()]))];
    }

    /** The trend: week by week, the absences of the reader's groups or of one of them, with the change from the week before in points. */
    public function weeks(Request $request)
    {
        [$from, $to] = $this->period($request);
        $group = $request->validate(['group' => 'nullable|uuid'])['group'] ?? null;
        $rows = [];
        $previous = null;
        foreach (DB::select('SELECT week::text AS week, sessions_held, marks, absent, late, excused FROM school.operations_weeks(?::uuid, ?::date, ?::date)',
            [$group, $from, $to]) as $row) {
            $row = (array) $row;
            $row['absence_rate'] = $this->rate($row['absent'], $row['marks']);
            $row['change'] = $row['absence_rate'] === null || $previous === null ? null : number_format(round((float) $row['absence_rate'] - (float) $previous, 1) + 0.0, 1, '.', '');
            if ($row['absence_rate'] !== null) $previous = $row['absence_rate'];
            $rows[] = $row;
        }
        return ['data' => $rows, 'meta' => ['from' => $from, 'to' => $to, 'group' => $group]];
    }

    private function period(Request $request): array
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'fresh' => 'boolean']);
        return [$data['from'], $data['to']];
    }

    /** A share in percent to one decimal; none without a whole. */
    private function rate(?int $part, ?int $whole): ?string
    {
        return $part === null || !$whole ? null : number_format($part * 100 / $whole, 1, '.', '');
    }
}
