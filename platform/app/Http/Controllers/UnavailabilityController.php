<?php

namespace App\Http\Controllers;

use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-STF-03: the days a teacher may not teach, from one day until another, with the reason; they
 * prevail over the weekly availability. In force at once or waiting for an approver, as a
 * declaration; one in force is cancelled until its last day, never deleted (SQL 034).
 */
final class UnavailabilityController
{
    private const COLUMNS = "id, staff_id, date_from, date_to, reason, status, decided_at, decided_by, decision_reason, cancelled_at, version,
        created_at, created_by, updated_at, updated_by,
        (SELECT u.name FROM public.users u WHERE u.id = unavailability.created_by) AS declared_by_name,
        (SELECT u.name FROM public.users u WHERE u.id = unavailability.decided_by) AS decided_by_name";

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    /** The days off to come and under way, the next first; with `include_past`, those gone by too. */
    public function index(Request $request, string $staff)
    {
        $this->records->find('staff', $staff, columns: 'id');
        $data = $request->validate(Paging::RULES + ['status' => 'nullable|in:pending,approved,rejected,cancelled', 'include_past' => 'boolean']);
        $sql = 'SELECT '.self::COLUMNS.' FROM school.unavailability WHERE tenant_id = ? AND staff_id = ?';
        $bindings = [$this->school->id(), $staff];
        if (!$request->boolean('include_past')) $sql .= ' AND date_to >= current_date';
        if (isset($data['status'])) { $sql .= ' AND status = ?'; $bindings[] = $data['status']; }
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, 'date_from, created_at, id');
    }

    public function store(Request $request, string $staff)
    {
        $row = $this->records->find('staff', $staff, columns: 'id');
        DB::select('SELECT school.require_staff(?, ?)', ['staff.availability.manage', $row->id]);
        $data = $request->validate(['date_from' => 'required|date_format:Y-m-d', 'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'reason' => 'required|string|min:3|max:500',
            ...array_fill_keys(['tenant_id', 'staff_id', 'status', 'decided_at', 'decided_by', 'decision_reason', 'cancelled_at'], 'prohibited')]);
        $id = DB::table('school.unavailability')->insertGetId(['tenant_id' => $this->school->id(), 'staff_id' => $row->id, ...$data], 'id');
        return response()->json(['data' => (array) $this->find($id)], 201);
    }

    public function approve(Request $request, string $unavailability)
    {
        return $this->change($request, $unavailability, 'staff.availability.approve', 'approved', 'nullable');
    }

    public function reject(Request $request, string $unavailability)
    {
        return $this->change($request, $unavailability, 'staff.availability.approve', 'rejected', 'required');
    }

    /** One in force, until its last day: by whoever may put a change in force at once (SQL 034). */
    public function cancel(Request $request, string $unavailability)
    {
        return $this->change($request, $unavailability, 'staff.availability.manage', 'cancelled', 'prohibited');
    }

    /** A pending one withdrawn; the journal keeps its values. */
    public function destroy(Request $request, string $unavailability)
    {
        $row = $this->find($unavailability, true);
        DB::select('SELECT school.require_staff(?, ?)', ['staff.availability.manage', $row->staff_id]);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.unavailability')->where('tenant_id', $this->school->id())->where('id', $row->id)->delete();
        return response()->noContent();
    }

    private function change(Request $request, string $unavailability, string $permission, string $status, string $reason): array
    {
        $row = $this->find($unavailability, true);
        DB::select('SELECT school.require_staff(?, ?)', [$permission, $row->staff_id]);
        $data = $request->validate(['version' => 'required|integer|min:1',
            'decision_reason' => $reason === 'prohibited' ? 'prohibited' : $reason.'|string|min:3|max:500']);
        $this->records->version($row, (int) $data['version']);
        $change = ['status' => $status, 'version' => $data['version']];
        if ($reason !== 'prohibited') $change['decision_reason'] = $data['decision_reason'] ?? null;
        DB::table('school.unavailability')->where('tenant_id', $this->school->id())->where('id', $row->id)->update($change);
        return ['data' => (array) $this->find($row->id)];
    }

    private function find(string $id, bool $lock = false): object { return $this->records->find('unavailability', $id, $lock, self::COLUMNS); }
}
