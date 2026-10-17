<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-PTG-03: the hours delivered from one day until another (a month at most), by the validated
 * attendance as it stands: by teacher, the sessions held and their hours, those held as a
 * substitute; a teacher's own, session by session. The month's hours are the sum of its validated
 * sessions (AC1), which its pays count (SQL 067). Whoever does not read the whole school's hours
 * reads their own only.
 */
final class DeliveredHoursController
{
    public function __construct(private CurrentSchool $school, private Permissions $permissions) {}

    public function index(Request $request)
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'staff_id' => 'nullable|uuid']);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) >= 31) throw Refusal::of('attendance_range_too_long');
        $staff = $data['staff_id'] ?? null;
        if (!$this->permissions->everywhere('hours.validated.read') && !$this->permissions->everywhere('payroll.run.generate')) {
            $own = DB::selectOne('SELECT id FROM school.staff WHERE tenant_id = ? AND user_id = school.actor()', [$this->school->id()])?->id;
            if ($own === null || ($staff !== null && $staff !== $own)) throw Refusal::permissionDenied();
            $staff = $own;
        }
        $rows = DB::select("SELECT d.staff_id, s.staff_number, s.last_name_lat, s.first_name_lat, s.last_name_ar, s.first_name_ar,
            count(*)::int AS sessions, sum(d.hours)::numeric(10, 2)::text AS hours, count(*) FILTER (WHERE d.replaced)::int AS replaced_sessions,
            count(*) FILTER (WHERE d.corrected)::int AS corrected_sessions
            FROM school.delivered_sessions(?, ?::date, ?::date) d LEFT JOIN school.staff s ON s.tenant_id = ? AND s.id = d.staff_id
            GROUP BY d.staff_id, s.staff_number, s.last_name_lat, s.first_name_lat, s.last_name_ar, s.first_name_ar ORDER BY s.last_name_lat, s.first_name_lat, d.staff_id",
            [$staff, $data['from'], $data['to'], $this->school->id()]);
        $sessions = $staff === null ? null : DB::select("SELECT d.session_id, d.day::text AS day, to_char(d.starts_at AT TIME ZONE 'Africa/Algiers', 'HH24:MI') AS start_time,
            g.code AS group_code, d.hours::text AS hours, d.replaced, d.corrected FROM school.delivered_sessions(?, ?::date, ?::date) d
            LEFT JOIN school.study_group g ON g.tenant_id = ? AND g.id = d.group_id ORDER BY d.starts_at, d.session_id",
            [$staff, $data['from'], $data['to'], $this->school->id()]);
        return ['data' => array_map(fn (object $row) => ['id' => $row->staff_id, ...((array) $row)], $rows), 'sessions' => $sessions];
    }
}
