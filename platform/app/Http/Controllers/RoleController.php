<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The school's roles (FR-IAM-03): its copy of the predefined roles and its own. The database
 * checks the rest: permission held for the whole school, no change to a role one holds, only
 * permissions of the modules the school has, archived rather than deleted, never while assigned.
 */
final class RoleController
{
    /** Permissions are shown and changed for the school's modules only; those of other modules wait, unchanged, for their module. */
    private const SELECT = "SELECT r.id, r.code, r.kind, r.labels, r.template_code, r.version, r.archived_at,
        coalesce((SELECT json_agg(rp.permission_code ORDER BY rp.permission_code) FROM school.role_permission rp
          JOIN platform.permission p ON p.code = rp.permission_code
          WHERE rp.tenant_id = r.tenant_id AND rp.role_id = r.id AND p.module_key IN (SELECT school.my_modules())), '[]') AS permissions
        FROM school.role r LEFT JOIN platform.role_template rt ON rt.code = r.template_code";

    public function __construct(private CurrentSchool $school) {}

    public function index(Request $request)
    {
        $archived = $request->boolean('include_archived');
        $rows = DB::select(self::SELECT.' WHERE r.tenant_id = ?'.($archived ? '' : ' AND r.archived_at IS NULL').' ORDER BY rt.sort_order NULLS LAST, r.code',
            [$this->school->id()]);
        return ['data' => array_map(fn ($row) => $this->present($row), $rows)];
    }

    /** A new role, empty or copied from another: a copy keeps the source's kind and its permissions of the school's modules. */
    public function store(Request $request)
    {
        $data = $request->validate($this->labelRules() + [
            'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/'],
            'copy_from' => 'nullable|uuid',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|distinct',
        ]);
        $kind = 'internal';
        $permissions = $data['permissions'] ?? null;
        if (isset($data['copy_from'])) {
            $source = DB::selectOne('SELECT id, kind FROM school.role WHERE tenant_id = ? AND id = ?', [$this->school->id(), $data['copy_from']]);
            if (!$source) throw ValidationException::withMessages(['copy_from' => __('errors.role_unknown')]);
            $kind = $source->kind;
            $permissions ??= array_column(DB::select('SELECT rp.permission_code FROM school.role_permission rp
                JOIN platform.permission p ON p.code = rp.permission_code
                WHERE rp.tenant_id = ? AND rp.role_id = ? AND p.module_key IN (SELECT school.my_modules())', [$this->school->id(), $source->id]), 'permission_code');
        }
        $this->known($permissions ?? []);
        $id = DB::selectOne('INSERT INTO school.role (tenant_id, code, kind, labels) VALUES (?, ?, ?, ?::jsonb) RETURNING id',
            [$this->school->id(), $data['code'], $kind, $this->labels($data)])->id;
        DB::insert('INSERT INTO school.role_permission (tenant_id, role_id, permission_code) SELECT ?, ?, value FROM json_array_elements_text(?::json)',
            [$this->school->id(), $id, json_encode($permissions ?? [], JSON_THROW_ON_ERROR)]);
        return response()->json(['data' => $this->find($id)], 201);
    }

    public function update(Request $request, string $role)
    {
        $data = $request->validate($this->labelRules() + ['version' => 'required|integer|min:1']);
        $row = DB::selectOne('UPDATE school.role SET labels = ?::jsonb, version = ? WHERE tenant_id = ? AND id = ? RETURNING id',
            [$this->labels($data), $data['version'], $this->school->id(), $this->uuid($role)]);
        abort_unless($row, 404);
        return ['data' => $this->find($row->id)];
    }

    /** Sets the role's permissions among the school's modules: those missing are added, those absent from the list removed. */
    public function permissions(Request $request, string $role)
    {
        $data = $request->validate(['permissions' => 'present|array', 'permissions.*' => 'string|distinct']);
        $this->known($data['permissions']);
        abort_unless($this->find($this->uuid($role)), 404);
        $wanted = json_encode($data['permissions'], JSON_THROW_ON_ERROR);
        DB::delete('DELETE FROM school.role_permission rp USING platform.permission p
            WHERE rp.tenant_id = ? AND rp.role_id = ? AND p.code = rp.permission_code AND p.module_key IN (SELECT school.my_modules())
              AND rp.permission_code NOT IN (SELECT json_array_elements_text(?::json))', [$this->school->id(), $role, $wanted]);
        DB::insert('INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
            SELECT ?, ?, value FROM json_array_elements_text(?::json)
            WHERE value NOT IN (SELECT permission_code FROM school.role_permission WHERE tenant_id = ? AND role_id = ?)',
            [$this->school->id(), $role, $wanted, $this->school->id(), $role]);
        return ['data' => $this->find($role)];
    }

    /** BR-02: archived, never deleted; refused while assigned. The reason goes to the audit journal. */
    public function archive(Request $request, string $role)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        $row = DB::selectOne('UPDATE school.role SET archived_at = now() WHERE tenant_id = ? AND id = ? AND archived_at IS NULL RETURNING id',
            [$this->school->id(), $this->uuid($role)]);
        abort_unless($row, 404);
        return ['data' => $this->find($row->id)];
    }

    private function find(string $id): ?array
    {
        $row = DB::selectOne(self::SELECT.' WHERE r.tenant_id = ? AND r.id = ?', [$this->school->id(), $id]);
        return $row ? $this->present($row) : null;
    }

    /** Codes outside annex A are a mistake of the caller (422); the database checks the module. */
    private function known(array $permissions): void
    {
        $unknown = array_column(DB::select('SELECT value FROM json_array_elements_text(?::json)
            WHERE value NOT IN (SELECT code FROM platform.permission)', [json_encode(array_values($permissions), JSON_THROW_ON_ERROR)]), 'value');
        if ($unknown) throw ValidationException::withMessages(['permissions' => __('errors.permissions_unknown', ['codes' => implode(', ', $unknown)])]);
    }

    private function labelRules(): array
    {
        return ['labels' => 'required|array', 'labels.fr' => 'required|string|max:150',
            'labels.ar' => 'nullable|string|max:150', 'labels.en' => 'nullable|string|max:150'];
    }

    private function labels(array $data): string
    {
        $labels = array_filter(array_intersect_key($data['labels'], ['fr' => 1, 'ar' => 1, 'en' => 1]), fn ($label) => $label !== null && $label !== '');
        return json_encode($labels, JSON_THROW_ON_ERROR);
    }

    private function uuid(string $id): string
    {
        abort_unless(Str::isUuid($id), 404);
        return $id;
    }

    private function present(object $row): array
    {
        return [...(array) $row,
            'labels' => json_decode($row->labels, true, 4, JSON_THROW_ON_ERROR),
            'permissions' => json_decode($row->permissions, true, 4, JSON_THROW_ON_ERROR)];
    }
}
