<?php

namespace App\Http\Controllers;

use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-STF-04: a member's planned absences, a leave, a training, a sickness, from one day until
 * another; recorded by their manager, in force at once, cancelled until their last day. A
 * teacher's leave makes them unavailable on its days without saying why (FR-STF-03); lot 4 will
 * list the sessions it touches (AC2). Overlaps and the member's state are the database's (SQL 035).
 */
final class LeaveController
{
    // With its days, calendar days, and where it stands today.
    private const COLUMNS = "id, staff_id, leave_type, date_from, date_to, observation, status, cancelled_at, version, created_at, updated_at,
        date_to - date_from + 1 AS days,
        CASE WHEN status = 'cancelled' THEN 'cancelled' WHEN date_from > current_date THEN 'upcoming' WHEN date_to < current_date THEN 'past'
             ELSE 'under_way' END AS state,
        (SELECT u.name FROM public.users u WHERE u.id = staff_leave.created_by) AS recorded_by_name";
    public const TYPES = ['annual', 'sick', 'training', 'maternity', 'unpaid', 'exceptional', 'other'];

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    /** Those under way and to come, the next first; with `include_past`, those gone by too. */
    public function index(Request $request, string $staff)
    {
        $this->records->find('staff', $staff, columns: 'id');
        $data = $request->validate(Paging::RULES + ['leave_type' => 'nullable|in:'.implode(',', self::TYPES), 'include_past' => 'boolean',
            'include_cancelled' => 'boolean']);
        $sql = 'SELECT '.self::COLUMNS.' FROM school.staff_leave WHERE tenant_id = ? AND staff_id = ?';
        $bindings = [$this->school->id(), $staff];
        if (!$request->boolean('include_past')) $sql .= ' AND date_to >= current_date';
        if (!$request->boolean('include_cancelled')) $sql .= " AND status = 'planned'";
        if (isset($data['leave_type'])) { $sql .= ' AND leave_type = ?'; $bindings[] = $data['leave_type']; }
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, 'date_from, created_at, id');
    }

    public function store(Request $request, string $staff)
    {
        $row = $this->records->find('staff', $staff, columns: 'id');
        DB::select('SELECT school.require_staff(?, ?)', ['staff.leave.manage', $row->id]);
        $data = $request->validate(['leave_type' => 'required|in:'.implode(',', self::TYPES), 'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from', 'observation' => 'nullable|string|max:1000',
            ...array_fill_keys(['tenant_id', 'staff_id', 'status', 'cancelled_at'], 'prohibited')]);
        $id = DB::table('school.staff_leave')->insertGetId(['tenant_id' => $this->school->id(), 'staff_id' => $row->id, ...$data], 'id');
        return response()->json(['data' => (array) $this->find($id)], 201);
    }

    /** Until its last day: the teacher is available again on its days; the journal keeps it. */
    public function cancel(Request $request, string $leave)
    {
        $row = $this->find($leave, true);
        DB::select('SELECT school.require_staff(?, ?)', ['staff.leave.manage', $row->staff_id]);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.staff_leave')->where('tenant_id', $this->school->id())->where('id', $row->id)
            ->update(['status' => 'cancelled', 'version' => $data['version']]);
        return ['data' => (array) $this->find($row->id)];
    }

    private function find(string $id, bool $lock = false): object { return $this->records->find('staff_leave', $id, $lock, self::COLUMNS); }
}
