<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The school's tree (FR-REF-01, FR-REF-02): types from its profile, renamed or turned off;
 * nodes added, renamed, reordered and archived. The database checks parents, unique codes and
 * labels, and archiving.
 */
final class ReferentialController
{
    use HandlesLabels;

    private const TYPE = 'SELECT code, parent_code, labels, level_order, is_required, active, version FROM school.node_type';
    // Each node with its parent's code and labels: a page of nodes names its parents without loading them all.
    private const NODE = 'SELECT n.id, n.type_code, n.parent_id, p.code AS parent_code, p.labels AS parent_labels, n.code, n.labels, n.sort_order,
            n.platform_code, n.version, n.archived_at
        FROM school.ref_node n JOIN school.node_type t ON t.tenant_id = n.tenant_id AND t.code = n.type_code
        LEFT JOIN school.ref_node p ON p.tenant_id = n.tenant_id AND p.id = n.parent_id';

    public function __construct(private CurrentSchool $school) {}

    public function types()
    {
        return ['data' => array_map(fn ($row) => $this->decoded($row), DB::select(self::TYPE.' WHERE tenant_id = ? ORDER BY level_order', [$this->school->id()]))];
    }

    /** Renames a type, or turns an optional one on or off; its code never changes. */
    public function updateType(Request $request, string $type)
    {
        $data = $request->validate($this->labelRules() + ['active' => 'required|boolean', 'version' => 'required|integer|min:1']);
        $row = DB::selectOne('UPDATE school.node_type SET labels = ?::jsonb, active = ?, version = ? WHERE tenant_id = ? AND code = ? RETURNING code',
            [$this->labels($data['labels']), $data['active'], $data['version'], $this->school->id(), $type]);
        abort_unless($row, 404);
        return ['data' => $this->decoded(DB::selectOne(self::TYPE.' WHERE tenant_id = ? AND code = ?', [$this->school->id(), $type]))];
    }

    /** Nodes, by type or under a parent; archived ones only on request (they stay in the history). */
    public function nodes(Request $request)
    {
        $data = $request->validate(['type' => 'nullable|string|max:40', 'parent_id' => 'nullable|uuid', 'include_archived' => 'boolean',
            'archived' => 'boolean', 'q' => 'nullable|string|max:100'] + Paging::RULES);
        $sql = self::NODE.' WHERE n.tenant_id = ?';
        $bindings = [$this->school->id()];
        if (isset($data['type'])) { $sql .= ' AND n.type_code = ?'; $bindings[] = $data['type']; }
        if (isset($data['parent_id'])) { $sql .= ' AND n.parent_id = ?'; $bindings[] = $data['parent_id']; }
        // Active only by default; `archived` for the archived only; `include_archived` for both.
        if ($request->boolean('archived')) $sql .= ' AND n.archived_at IS NOT NULL';
        elseif (!$request->boolean('include_archived')) $sql .= ' AND n.archived_at IS NULL';
        if (isset($data['q'])) {
            $sql .= ' AND '.Paging::search(['n.code', "n.labels ->> 'fr'", "n.labels ->> 'ar'", "n.labels ->> 'en'"]);
            $bindings[] = $data['q'];
        }
        return Paging::select($request, $sql, $bindings, 't.level_order, n.sort_order, n.code', fn ($row) => $this->decoded($row, ['labels', 'parent_labels']));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->labelRules() + [
            'type' => 'required|string|max:40',
            'parent_id' => 'nullable|uuid',
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_]{1,20}$/'],
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);
        $id = DB::selectOne('INSERT INTO school.ref_node (tenant_id, type_code, parent_id, code, labels, sort_order) VALUES (?, ?, ?, ?, ?::jsonb, ?) RETURNING id',
            [$this->school->id(), $data['type'], $data['parent_id'] ?? null, $data['code'], $this->labels($data['labels']), $data['sort_order'] ?? 0])->id;
        return response()->json(['data' => $this->node($id)], 201);
    }

    public function update(Request $request, string $node)
    {
        $data = $request->validate($this->labelRules() + ['sort_order' => 'required|integer|min:0|max:100000', 'version' => 'required|integer|min:1']);
        $row = DB::selectOne('UPDATE school.ref_node SET labels = ?::jsonb, sort_order = ?, version = ? WHERE tenant_id = ? AND id = ? RETURNING id',
            [$this->labels($data['labels']), $data['sort_order'], $data['version'], $this->school->id(), $this->uuid($node)]);
        abort_unless($row, 404);
        return ['data' => $this->node($row->id)];
    }

    /** BR-02: archived, never deleted; refused while it has active children. */
    public function archive(Request $request, string $node)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        $row = DB::selectOne('UPDATE school.ref_node SET archived_at = now() WHERE tenant_id = ? AND id = ? AND archived_at IS NULL RETURNING id',
            [$this->school->id(), $this->uuid($node)]);
        abort_unless($row, 404);
        return ['data' => $this->node($row->id)];
    }

    private function node(string $id): array
    {
        return $this->decoded(DB::selectOne(self::NODE.' WHERE n.tenant_id = ? AND n.id = ?', [$this->school->id(), $id]), ['labels', 'parent_labels']);
    }
}
