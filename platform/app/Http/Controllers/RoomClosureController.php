<?php

namespace App\Http\Controllers;

use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-SLL-02: a room's dated closures (works): from one day until another, with the reason, never
 * two at a time; cancelled until their last day, never deleted. Lot 4's sessions take none of
 * those days (AC2, `school.room_available`, SQL 037).
 */
final class RoomClosureController
{
    private const COLUMNS = "id, room_id, date_from, date_to, reason, status, cancelled_at, version, created_at, updated_at, date_to - date_from + 1 AS days,
        (SELECT u.name FROM public.users u WHERE u.id = room_closure.created_by) AS recorded_by_name";

    public function __construct(private CurrentSchool $school, private AcademicRecords $records, private RoomController $rooms) {}

    /** Those to come and under way, the next first; with `include_past` and `include_cancelled`, the others too. */
    public function index(Request $request, string $room)
    {
        $this->records->find('room', $room, columns: 'id');
        $request->validate(Paging::RULES + ['include_past' => 'boolean', 'include_cancelled' => 'boolean']);
        $sql = 'SELECT '.self::COLUMNS.' FROM school.room_closure WHERE tenant_id = ? AND room_id = ?';
        if (!$request->boolean('include_past')) $sql .= ' AND date_to >= current_date';
        if (!$request->boolean('include_cancelled')) $sql .= " AND status = 'planned'";
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, [$this->school->id(), $room], 'date_from, created_at, id');
    }

    public function store(Request $request, string $room)
    {
        $row = $this->records->find('room', $room, columns: 'id, site_id');
        $this->rooms->inScope($row->site_id);
        $data = $request->validate(['date_from' => 'required|date_format:Y-m-d', 'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'reason' => 'required|string|min:3|max:500', ...array_fill_keys(['tenant_id', 'room_id', 'status', 'cancelled_at'], 'prohibited')]);
        $id = DB::table('school.room_closure')->insertGetId(['tenant_id' => $this->school->id(), 'room_id' => $row->id, ...$data], 'id');
        return response()->json(['data' => (array) $this->find($id)], 201);
    }

    /** Until its last day: the room takes sessions again on its days; the journal keeps it. */
    public function cancel(Request $request, string $closure)
    {
        // The scope first: a closure of another site is not locked by whoever may not change it (its policy).
        $room = $this->find($closure)->room_id;
        $this->rooms->inScope(DB::selectOne('SELECT site_id FROM school.room WHERE tenant_id = ? AND id = ?', [$this->school->id(), $room])->site_id);
        $row = $this->find($closure, true);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.room_closure')->where('tenant_id', $this->school->id())->where('id', $row->id)
            ->update(['status' => 'cancelled', 'version' => $data['version']]);
        return ['data' => (array) $this->find($row->id)];
    }

    private function find(string $id, bool $lock = false): object { return $this->records->find('room_closure', $id, $lock, self::COLUMNS); }
}
