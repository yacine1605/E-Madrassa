<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Who holds which role, where and when (FR-IAM-03, FR-IAM-04, BR-10). The database refuses
 * assignments to oneself, a student or parent role beyond the person's own data, a site of
 * another school, an archived role, and the loss of the school's last administrator.
 */
final class RoleAssignmentController
{
    private const SELECT = 'SELECT a.id, a.user_id, u.name AS user_name, u.email AS user_email, a.role_id, r.code AS role_code,
        r.labels AS role_labels, a.scope_type, to_json(a.scope_ids) AS scope_ids, a.valid_from, a.valid_until, a.version, a.archived_at
        FROM school.role_assignment a
        JOIN school.role r ON r.tenant_id = a.tenant_id AND r.id = a.role_id
        JOIN public.users u ON u.id = a.user_id';

    public function __construct(private CurrentSchool $school) {}

    public function index(Request $request)
    {
        $request->validate(['user_id' => 'nullable|integer']);
        $where = ' WHERE a.tenant_id = ?'.($request->boolean('include_archived') ? '' : ' AND a.archived_at IS NULL');
        $bindings = [$this->school->id()];
        if ($request->filled('user_id')) { $where .= ' AND a.user_id = ?'; $bindings[] = (int) $request->input('user_id'); }
        return ['data' => array_map(fn ($row) => $this->present($row), DB::select(self::SELECT.$where.' ORDER BY u.name, r.code, a.valid_from', $bindings))];
    }

    public function store(Request $request)
    {
        // Levels, groups and subjects arrive with the referential (W1.5); until then a role covers
        // the whole school, some sites, or the person's own data.
        $data = $request->validate([
            'user_id' => 'required|integer',
            'role_id' => 'required|uuid',
            'scope_type' => ['required', Rule::in(['tenant', 'site', 'personal'])],
            'scope_ids' => 'required_if:scope_type,site|prohibited_unless:scope_type,site|array|min:1|max:100',
            'scope_ids.*' => 'uuid|distinct',
            'valid_from' => 'nullable|date_format:Y-m-d',
            'valid_until' => 'nullable|date_format:Y-m-d|after_or_equal:'.($request->input('valid_from') ?: 'today'),
        ]);
        $tenant = $this->school->id();
        if (!DB::selectOne("SELECT 1 AS found FROM school.membership WHERE tenant_id = ? AND user_id = ? AND status IN ('invited', 'active')", [$tenant, $data['user_id']])) {
            throw ValidationException::withMessages(['user_id' => 'Cette personne n’a pas de compte actif dans cet établissement.']);
        }
        if (!DB::selectOne('SELECT 1 AS found FROM school.role WHERE tenant_id = ? AND id = ?', [$tenant, $data['role_id']])) {
            throw ValidationException::withMessages(['role_id' => 'Rôle inconnu dans cet établissement.']);
        }
        $id = DB::selectOne('INSERT INTO school.role_assignment (tenant_id, user_id, role_id, scope_type, scope_ids, valid_from, valid_until)
            VALUES (?, ?, ?, ?, (SELECT array_agg(value::uuid) FROM json_array_elements_text(?::json)), coalesce(?::date, current_date), ?::date) RETURNING id',
            [$tenant, $data['user_id'], $data['role_id'], $data['scope_type'], isset($data['scope_ids']) ? json_encode($data['scope_ids'], JSON_THROW_ON_ERROR) : null,
             $data['valid_from'] ?? null, $data['valid_until'] ?? null])->id;
        return response()->json(['data' => $this->find($id)], 201);
    }

    /** Ends the assignment now; the reason goes to the audit journal. */
    public function archive(Request $request, string $assignment)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        abort_unless(Str::isUuid($assignment), 404);
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        $row = DB::selectOne('UPDATE school.role_assignment SET archived_at = now() WHERE tenant_id = ? AND id = ? AND archived_at IS NULL RETURNING id',
            [$this->school->id(), $assignment]);
        abort_unless($row, 404);
        return ['data' => $this->find($row->id)];
    }

    private function find(string $id): array
    {
        return $this->present(DB::selectOne(self::SELECT.' WHERE a.tenant_id = ? AND a.id = ?', [$this->school->id(), $id]));
    }

    private function present(object $row): array
    {
        return [...(array) $row,
            'role_labels' => json_decode($row->role_labels, true, 4, JSON_THROW_ON_ERROR),
            'scope_ids' => $row->scope_ids === null ? null : json_decode($row->scope_ids, true, 4, JSON_THROW_ON_ERROR)];
    }
}
