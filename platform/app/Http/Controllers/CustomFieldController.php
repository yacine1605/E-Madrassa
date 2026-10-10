<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Custom fields of students, guardians, staff and leads (FR-REF-04). Key and type are
 * permanent; select options are added, never removed; 20 active per entity at most.
 */
final class CustomFieldController
{
    use HandlesLabels;

    private const SELECT = 'SELECT id, entity, key, field_type, options, required, is_sensitive, labels, sort_order, version, archived_at FROM school.custom_field';
    private const ENTITIES = ['student', 'guardian', 'staff', 'lead'];

    public function __construct(private CurrentSchool $school) {}

    public function index(Request $request)
    {
        $data = $request->validate(['entity' => ['nullable', Rule::in(self::ENTITIES)], 'include_archived' => 'boolean']);
        $sql = self::SELECT.' WHERE tenant_id = ?';
        $bindings = [$this->school->id()];
        if (isset($data['entity'])) { $sql .= ' AND entity = ?'; $bindings[] = $data['entity']; }
        if (!$request->boolean('include_archived')) $sql .= ' AND archived_at IS NULL';
        return ['data' => array_map(fn ($row) => $this->decoded($row, ['labels', 'options']), DB::select($sql.' ORDER BY entity, sort_order, key', $bindings))];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->fieldRules() + [
            'entity' => ['required', Rule::in(self::ENTITIES)],
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/'],
            'field_type' => ['required', Rule::in(['text', 'number', 'date', 'boolean', 'select'])],
        ]);
        $id = DB::selectOne('INSERT INTO school.custom_field (tenant_id, entity, key, field_type, options, required, is_sensitive, labels, sort_order)
            VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?::jsonb, ?) RETURNING id', [$this->school->id(), $data['entity'], $data['key'], $data['field_type'],
            $this->options($data), $data['required'] ?? false, $data['is_sensitive'] ?? false, $this->labels($data['labels']), $data['sort_order'] ?? 0])->id;
        return response()->json(['data' => $this->find($id)], 201);
    }

    public function update(Request $request, string $field)
    {
        $data = $request->validate($this->fieldRules() + ['version' => 'required|integer|min:1']);
        $row = DB::selectOne('UPDATE school.custom_field SET options = ?::jsonb, required = ?, is_sensitive = ?, labels = ?::jsonb, sort_order = ?, version = ?
            WHERE tenant_id = ? AND id = ? RETURNING id', [$this->options($data), $data['required'] ?? false, $data['is_sensitive'] ?? false,
            $this->labels($data['labels']), $data['sort_order'] ?? 0, $data['version'], $this->school->id(), $this->uuid($field)]);
        abort_unless($row, 404);
        return ['data' => $this->find($row->id)];
    }

    /** The values already entered stay with their records. */
    public function archive(Request $request, string $field)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        $row = DB::selectOne('UPDATE school.custom_field SET archived_at = now() WHERE tenant_id = ? AND id = ? AND archived_at IS NULL RETURNING id',
            [$this->school->id(), $this->uuid($field)]);
        abort_unless($row, 404);
        return ['data' => $this->find($row->id)];
    }

    private function fieldRules(): array
    {
        return $this->labelRules() + $this->labelRules('options.*.labels') + [
            'options' => 'nullable|array|min:1|max:100',
            'options.*.code' => ['required', 'string', 'regex:/^[A-Z0-9_]{1,20}$/', 'distinct'],
            'required' => 'boolean',
            'is_sensitive' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:1000',
        ];
    }

    private function options(array $data): ?string
    {
        if (empty($data['options'])) return null;
        return json_encode(array_map(fn ($option) => ['code' => $option['code'], 'labels' => json_decode($this->labels($option['labels']), true)],
            $data['options']), JSON_THROW_ON_ERROR);
    }

    private function find(string $id): array
    {
        return $this->decoded(DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?', [$this->school->id(), $id]), ['labels', 'options']);
    }
}
