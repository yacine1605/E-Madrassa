<?php

namespace App\Http\Controllers;

use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-STF-03: a teacher's weekly availability, declared from a day on with the hours a day and a
 * week it bounds; a later declaration replaces it from its own first day. In force at once, or
 * waiting for an approver when the school asks for it. The slots, the states and who may decide
 * are the database's (SQL 034).
 */
final class AvailabilityController
{
    // With the hours a week its slots offer, where it stands today, and who declared and decided it.
    private const COLUMNS = "id, staff_id, valid_from, slots, min_weekly_hours, max_daily_hours, max_weekly_hours, status, decided_at, decided_by,
        decision_reason, version, created_at, created_by, updated_at, school.availability_hours(slots) AS weekly_hours,
        CASE WHEN status <> 'approved' THEN status WHEN valid_from > current_date THEN 'upcoming'
             WHEN id = (SELECT c.id FROM school.availability_on(staff_id) c) THEN 'in_force' ELSE 'replaced' END AS state,
        (SELECT u.name FROM public.users u WHERE u.id = availability.created_by) AS declared_by_name,
        (SELECT u.name FROM public.users u WHERE u.id = availability.decided_by) AS decided_by_name";
    private const TIME = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    /** The latest first: the declarations to come and the one in force, then those they replaced. */
    public function index(Request $request, string $staff)
    {
        $this->records->find('staff', $staff, columns: 'id');
        $data = $request->validate(Paging::RULES + ['status' => 'nullable|in:pending,approved,rejected']);
        $sql = 'SELECT '.self::COLUMNS.' FROM school.availability WHERE tenant_id = ? AND staff_id = ?';
        $bindings = [$this->school->id(), $staff];
        if (isset($data['status'])) { $sql .= ' AND status = ?'; $bindings[] = $data['status']; }
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, 'valid_from DESC, created_at DESC, id', fn ($row) => $this->present($row));
    }

    /** From `valid_from` (today when absent) on; `slots` may be empty: available at no time. */
    public function store(Request $request, string $staff)
    {
        $row = $this->records->find('staff', $staff, columns: 'id');
        DB::select('SELECT school.require_staff(?, ?)', ['staff.availability.manage', $row->id]);
        $weekly = $request->filled('max_weekly_hours') ? ['lte:max_weekly_hours'] : [];
        $data = $request->validate([
            'valid_from' => 'sometimes|nullable|date_format:Y-m-d',
            'slots' => 'present|array|max:70', 'slots.*' => 'array:weekday,start_time,end_time',
            'slots.*.weekday' => 'required|integer|between:0,6',
            'slots.*.start_time' => ['required', 'string', 'regex:'.self::TIME],
            // `24:00` ends a day.
            'slots.*.end_time' => ['required', 'string', 'regex:/^(([01][0-9]|2[0-3]):[0-5][0-9]|24:00)$/'],
            'min_weekly_hours' => ['nullable', 'decimal:0,2', 'min:0', 'max:168', ...$weekly],
            'max_daily_hours' => ['nullable', 'decimal:0,2', 'gt:0', 'max:24', ...$weekly],
            'max_weekly_hours' => 'nullable|decimal:0,2|gt:0|max:168',
            ...array_fill_keys(['tenant_id', 'staff_id', 'status', 'decided_at', 'decided_by', 'decision_reason'], 'prohibited')]);
        $data['slots'] = json_encode(array_map(fn (array $slot) => ['weekday' => (int) $slot['weekday'],
            'start_time' => $slot['start_time'], 'end_time' => $slot['end_time']], $data['slots']), JSON_THROW_ON_ERROR);
        $id = DB::table('school.availability')->insertGetId(['tenant_id' => $this->school->id(), 'staff_id' => $row->id, ...$data], 'id');
        return response()->json(['data' => $this->present($this->find($id))], 201);
    }

    /** In force from its first day, or from today when that day has passed; a comment is optional. */
    public function approve(Request $request, string $declaration)
    {
        return $this->decide($request, $declaration, 'approved', 'nullable');
    }

    /** Refused with its reason; the day it named is free again. */
    public function reject(Request $request, string $declaration)
    {
        return $this->decide($request, $declaration, 'rejected', 'required');
    }

    /** A pending declaration withdrawn; the journal keeps its values. */
    public function destroy(Request $request, string $declaration)
    {
        // Not locked: whoever withdraws may not decide, and the deletion waits for a decision under way.
        $row = $this->find($declaration);
        DB::select('SELECT school.require_staff(?, ?)', ['staff.availability.manage', $row->staff_id]);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.availability')->where('tenant_id', $this->school->id())->where('id', $row->id)->delete();
        return response()->noContent();
    }

    private function decide(Request $request, string $declaration, string $status, string $reason): array
    {
        $row = $this->find($declaration, true);
        DB::select('SELECT school.require_staff(?, ?)', ['staff.availability.approve', $row->staff_id]);
        $data = $request->validate(['version' => 'required|integer|min:1', 'decision_reason' => $reason.'|string|min:3|max:500']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.availability')->where('tenant_id', $this->school->id())->where('id', $row->id)
            ->update(['status' => $status, 'decision_reason' => $data['decision_reason'] ?? null, 'version' => $data['version']]);
        return ['data' => $this->present($this->find($row->id))];
    }

    private function find(string $id, bool $lock = false): object { return $this->records->find('availability', $id, $lock, self::COLUMNS); }

    private function present(object $row): array
    {
        $data = (array) $row;
        // In the order a week reads, whatever order the database keeps the keys in.
        $data['slots'] = array_map(fn (array $slot) => ['weekday' => $slot['weekday'], 'start_time' => $slot['start_time'], 'end_time' => $slot['end_time']],
            json_decode($data['slots'], true, 4, JSON_THROW_ON_ERROR));
        return $data;
    }
}
