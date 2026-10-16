<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-SLL-02: a site's rooms: code unique on the site, name, capacity, type and equipment among the
 * school's lists, active or unavailable, archived when no longer used. Read with the referential,
 * as the sites; written within the scope of `room.room.manage` (the room's site, or the whole
 * school). The lists, the groups that use a room and the site's state are the database's (SQL 037).
 */
final class RoomController
{
    use HandlesLabels;

    // With its site's code, and its next closure still to come or under way.
    private const COLUMNS = "id, site_id, code, labels, capacity, room_type, to_json(equipment) AS equipment, status, version, archived_at, created_at, updated_at,
        (SELECT s.code FROM school.site s WHERE s.tenant_id = room.tenant_id AND s.id = room.site_id) AS site_code,
        (SELECT jsonb_build_object('date_from', c.date_from, 'date_to', c.date_to, 'reason', c.reason) FROM school.room_closure c
         WHERE c.tenant_id = room.tenant_id AND c.room_id = room.id AND c.status = 'planned' AND c.date_to >= current_date ORDER BY c.date_from LIMIT 1) AS next_closure";
    private const JSON = ['labels', 'equipment', 'next_closure'];
    private const CODE = '/^[A-Z0-9_]{1,20}$/';

    public function __construct(private CurrentSchool $school, private AcademicRecords $records, private Permissions $permissions) {}

    /** By site and code; `min_capacity` keeps those seating that many, `equipment` those that have it. */
    public function index(Request $request)
    {
        $wanted = $request->validate(['min_capacity' => 'nullable|integer|min:1|max:10000', 'equipment' => ['nullable', 'string', 'regex:'.self::CODE]]);
        $conditions = $bindings = [];
        if (isset($wanted['min_capacity'])) { $conditions[] = 'capacity >= ?'; $bindings[] = (int) $wanted['min_capacity']; }
        if (isset($wanted['equipment'])) { $conditions[] = '? = ANY (equipment)'; $bindings[] = $wanted['equipment']; }
        return $this->records->listing($request, 'SELECT '.self::COLUMNS.' FROM school.room',
            ['site_id' => 'nullable|uuid', 'room_type' => ['nullable', 'string', 'regex:'.self::CODE], 'status' => 'nullable|in:active,unavailable'],
            ['code', "labels->>'fr'", "labels->>'ar'", "labels->>'en'"],
            '(SELECT s.code FROM school.site s WHERE s.tenant_id = room.tenant_id AND s.id = room.site_id), code, id',
            fn ($row) => $this->decoded($row, self::JSON), $conditions ? [implode(' AND ', $conditions), $bindings] : null);
    }

    public function show(string $room) { return ['data' => $this->decoded($this->find($room), self::JSON)]; }

    public function store(Request $request)
    {
        $this->records->somewhere('room.room.manage');
        $data = $request->validate($this->labelRules() + $this->rules() + ['site_id' => 'required|uuid',
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,20}$/'], 'room_type' => ['required', 'string', 'regex:'.self::CODE]]);
        $this->records->find('site', $data['site_id'], columns: 'id');
        $this->inScope($data['site_id']);
        $id = DB::selectOne('INSERT INTO school.room (tenant_id, site_id, code, labels, capacity, room_type, equipment, status)
            VALUES (?, ?, ?, ?::jsonb, ?, ?, ARRAY(SELECT jsonb_array_elements_text(?::jsonb)), ?) RETURNING id',
            [$this->school->id(), $data['site_id'], $data['code'], $this->labels($data['labels']), $data['capacity'], $data['room_type'],
                json_encode($data['equipment'] ?? [], JSON_THROW_ON_ERROR), $data['status'] ?? 'active'])->id;
        return response()->json($this->show($id), 201);
    }

    /** Its name, capacity, type, equipment and state; its site and code stay. */
    public function update(Request $request, string $room)
    {
        // The scope first: a room of another site is not locked by whoever may not change it (its policy).
        $this->inScope($this->find($room)->site_id);
        $row = $this->find($room, true);
        $data = $request->validate(($request->exists('labels') ? $this->labelRules() : []) + $this->rules() + [
            'capacity' => 'sometimes|integer|min:1|max:10000', 'room_type' => ['sometimes', 'string', 'regex:'.self::CODE],
            'version' => 'required|integer|min:1', ...array_fill_keys(['site_id', 'code'], 'prohibited')]);
        $this->records->version($row, (int) $data['version']);
        $sets = ['version = ?'];
        $bindings = [$data['version']];
        foreach (['capacity', 'room_type', 'status'] as $column) {
            if (array_key_exists($column, $data)) { $sets[] = "$column = ?"; $bindings[] = $data[$column]; }
        }
        if (isset($data['labels'])) { $sets[] = 'labels = ?::jsonb'; $bindings[] = $this->labels($data['labels']); }
        if (array_key_exists('equipment', $data)) {
            $sets[] = 'equipment = ARRAY(SELECT jsonb_array_elements_text(?::jsonb))';
            $bindings[] = json_encode($data['equipment'] ?? [], JSON_THROW_ON_ERROR);
        }
        DB::update('UPDATE school.room SET '.implode(', ', $sets).' WHERE tenant_id = ? AND id = ?', [...$bindings, $this->school->id(), $row->id]);
        return $this->show($row->id);
    }

    /** No longer used: archived, never deleted (BR-02), with the reason in the journal. */
    public function archive(Request $request, string $room)
    {
        // The scope first: a room of another site is not locked by whoever may not change it (its policy).
        $this->inScope($this->find($room)->site_id);
        $row = $this->find($room, true);
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000', 'version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        $this->records->reason($data['reason']);
        DB::update('UPDATE school.room SET archived_at = now(), version = ? WHERE tenant_id = ? AND id = ? AND archived_at IS NULL',
            [$data['version'], $this->school->id(), $row->id]);
        return $this->show($row->id);
    }

    /** Within the scope of `room.room.manage`: the whole school, or this site (FR-IAM-04); the policies decide again. */
    public function inScope(string $site): void
    {
        $sites = $this->permissions->sites('room.room.manage');
        if ($sites !== null && !in_array($site, $sites, true)) throw Refusal::permissionDenied();
    }

    private function rules(): array
    {
        return ['capacity' => 'required|integer|min:1|max:10000', 'equipment' => 'sometimes|nullable|array|max:50',
            'equipment.*' => ['string', 'regex:'.self::CODE], 'status' => 'sometimes|in:active,unavailable',
            ...array_fill_keys(['tenant_id', 'archived_at'], 'prohibited')];
    }

    private function find(string $id, bool $lock = false): object { return $this->records->find('room', $id, $lock, self::COLUMNS); }
}
