<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-PRE-01: the absence alerts. A pupil absent without excuse as often as the school's threshold
 * within its window raises one, once the mark that reaches it is saved (SQL 073); the staff who
 * follow the group are notified. The alerts the reader may read, the latest first: whoever corrects
 * the group's rolls for the whole school or on its site, and its lead teacher. A pupil is named
 * where the reader reads their record; otherwise the alert stays, unnamed.
 */
final class AbsenceAlertController
{
    public function __construct(private CurrentSchool $school) {}

    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['group_id' => 'nullable|uuid']);
        $sql = "SELECT a.id, a.student_id, s.id IS NOT NULL AS readable, s.matricule, s.last_name_lat, s.first_name_lat, s.last_name_ar, s.first_name_ar,
            a.group_id, g.code AS group_code, g.labels AS group_labels, a.absences, a.threshold, a.from_day::text AS from_day, a.to_day::text AS to_day, a.created_at
            FROM school.absence_alert a
            LEFT JOIN school.student s ON s.tenant_id = a.tenant_id AND s.id = a.student_id
            LEFT JOIN school.study_group g ON g.tenant_id = a.tenant_id AND g.id = a.group_id
            WHERE a.tenant_id = ?".(isset($data['group_id']) ? ' AND a.group_id = ?' : '');
        return Paging::select($request, $sql, array_values(array_filter([$this->school->id(), $data['group_id'] ?? null])), 'a.created_at DESC, a.id',
            fn (object $row) => [...((array) $row), 'group_labels' => json_decode((string) $row->group_labels, true)]);
    }

    /** The school's threshold and window, as they apply now. */
    public function rule()
    {
        $row = DB::selectOne("SELECT (school.setting_value('attendance.absence_threshold') #>> '{}')::int AS threshold,
            (school.setting_value('attendance.absence_window_days') #>> '{}')::int AS window_days");
        return ['data' => ['threshold' => $row->threshold, 'window_days' => $row->window_days]];
    }
}
