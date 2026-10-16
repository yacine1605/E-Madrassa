<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-SLL-02: the school's lists of room types and equipment (« paramétrables »), given with the
 * publisher's defaults; read with the referential, changed by `room.room.manage` for the whole
 * school. A code never changes; an entry is archived, never deleted, and the rooms keep it (SQL 037).
 */
final class RoomOptionController
{
    use HandlesLabels;

    private const SELECT = 'SELECT id, kind, code, labels, sort_order, version, archived_at,
        (SELECT count(*) FROM school.room r WHERE r.tenant_id = room_option.tenant_id AND r.archived_at IS NULL
           AND (r.room_type = room_option.code AND room_option.kind = \'room_type\' OR room_option.code = ANY (r.equipment) AND room_option.kind = \'equipment\')) AS rooms
        FROM school.room_option';
    public const KINDS = ['room_type', 'equipment'];

    public function __construct(private CurrentSchool $school) {}

    public function index(Request $request)
    {
        $data = $request->validate(['kind' => 'nullable|in:'.implode(',', self::KINDS), 'include_archived' => 'boolean']);
        $sql = self::SELECT.' WHERE tenant_id = ?'.($request->boolean('include_archived') ? '' : ' AND archived_at IS NULL');
        $bindings = [$this->school->id()];
        if (isset($data['kind'])) { $sql .= ' AND kind = ?'; $bindings[] = $data['kind']; }
        return ['data' => array_map(fn ($row) => $this->decoded($row), DB::select($sql.' ORDER BY kind DESC, sort_order, code', $bindings))];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->labelRules() + ['kind' => 'required|in:'.implode(',', self::KINDS),
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_]{1,20}$/'], 'sort_order' => 'nullable|integer|min:0|max:100000']);
        $id = DB::selectOne('INSERT INTO school.room_option (tenant_id, kind, code, labels, sort_order) VALUES (?, ?, ?, ?::jsonb, ?) RETURNING id',
            [$this->school->id(), $data['kind'], $data['code'], $this->labels($data['labels']), $data['sort_order'] ?? 0])->id;
        return response()->json(['data' => $this->find($id)], 201);
    }

    /**
     * The publisher's entries the school lacks, given back: a school not in use when it received the
     * module had none (SQL 037). One it archived stays archived.
     */
    public function defaults()
    {
        $added = DB::affectingStatement('INSERT INTO school.room_option (tenant_id, kind, code, labels, sort_order)
            SELECT ?, d.kind, d.code, d.labels, d.sort_order FROM platform.room_option_default d
            WHERE NOT EXISTS (SELECT 1 FROM school.room_option x WHERE x.tenant_id = ? AND x.kind = d.kind AND x.code = d.code)
            ORDER BY d.kind, d.sort_order', [$this->school->id(), $this->school->id()]);
        return ['data' => ['added' => $added]];
    }

    /** How many of the publisher's entries the school lacks. */
    public function missingDefaults(): int
    {
        return (int) DB::selectOne('SELECT count(*) AS n FROM platform.room_option_default d
            WHERE NOT EXISTS (SELECT 1 FROM school.room_option x WHERE x.tenant_id = ? AND x.kind = d.kind AND x.code = d.code)', [$this->school->id()])->n;
    }

    public function update(Request $request, string $option)
    {
        $data = $request->validate($this->labelRules() + ['sort_order' => 'required|integer|min:0|max:100000', 'version' => 'required|integer|min:1']);
        $row = DB::selectOne('UPDATE school.room_option SET labels = ?::jsonb, sort_order = ?, version = ? WHERE tenant_id = ? AND id = ? RETURNING id',
            [$this->labels($data['labels']), $data['sort_order'], $data['version'], $this->school->id(), $this->uuid($option)]);
        abort_unless($row, 404);
        return ['data' => $this->find($row->id)];
    }

    /** No longer offered for new rooms; the rooms that have it keep it. */
    public function archive(Request $request, string $option)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        $row = DB::selectOne('UPDATE school.room_option SET archived_at = now() WHERE tenant_id = ? AND id = ? AND archived_at IS NULL RETURNING id',
            [$this->school->id(), $this->uuid($option)]);
        abort_unless($row, 404);
        return ['data' => $this->find($row->id)];
    }

    private function find(string $id): array
    {
        return $this->decoded(DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?', [$this->school->id(), $id]));
    }
}
