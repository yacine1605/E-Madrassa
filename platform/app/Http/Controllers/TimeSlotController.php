<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-PLN-01: the grid of time slots the sessions are planned on, the whole school's: a code, a
 * start and an end to the minute (until 24:00). A slot keeps its code and times; it is archived
 * when no longer offered, never deleted; never two in use with the same times (SQL 061).
 */
final class TimeSlotController
{
    private const SELECT = "SELECT id, code, to_char(start_time, 'HH24:MI') AS start_time,
        CASE WHEN end_time = '24:00' THEN '24:00' ELSE to_char(end_time, 'HH24:MI') END AS end_time,
        (extract(epoch FROM end_time - start_time) / 60)::int AS minutes, version, archived_at FROM school.time_slot";
    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/';

    public function __construct(private CurrentSchool $school) {}

    /** In the day's order; with `include_archived`, those no longer offered too. */
    public function index(Request $request)
    {
        $request->validate(['include_archived' => 'boolean']);
        $sql = self::SELECT.' WHERE tenant_id = ?'.($request->boolean('include_archived') ? '' : ' AND archived_at IS NULL');
        return ['data' => array_map(fn ($row) => (array) $row, DB::select($sql.' ORDER BY start_time, end_time, code', [$this->school->id()]))];
    }

    public function store(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,20}$/'],
            'start_time' => ['required', 'string', 'regex:'.self::TIME], 'end_time' => ['required', 'string', 'regex:'.self::TIME]]);
        $id = DB::selectOne('INSERT INTO school.time_slot (tenant_id, code, start_time, end_time) VALUES (?, ?, ?::time, ?::time) RETURNING id',
            [$this->school->id(), $data['code'], $data['start_time'], $data['end_time']])->id;
        return response()->json(['data' => $this->find($id)], 201);
    }

    /** No longer offered for new sessions; those planned on it keep their times. */
    public function archive(Request $request, string $slot)
    {
        abort_unless(Str::isUuid($slot), 404);
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        $row = DB::selectOne('UPDATE school.time_slot SET archived_at = now() WHERE tenant_id = ? AND id = ? AND archived_at IS NULL RETURNING id',
            [$this->school->id(), $slot]);
        abort_unless($row, 404);
        return ['data' => $this->find($row->id)];
    }

    private function find(string $id): array
    {
        return (array) DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?', [$this->school->id(), $id]);
    }
}
