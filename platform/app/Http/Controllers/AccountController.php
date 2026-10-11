<?php

namespace App\Http\Controllers;

use App\Support\Accounts;
use App\Support\CurrentSchool;
use App\Support\Tasks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The school's accounts (FR-IAM-01): created, changed, invited, suspended, revoked and
 * imported through the database's functions, which check the permission, keep a person of
 * several schools in charge of their own identity, and protect the last administrator.
 */
final class AccountController
{
    public function __construct(private CurrentSchool $school, private Accounts $accounts) {}

    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['invited', 'active', 'suspended', 'revoked'])], 'q' => 'nullable|string|max:100',
            'paginate' => 'boolean', 'page' => 'integer|min:1', 'per_page' => 'integer|min:1|max:100']);
        $sql = Accounts::SELECT.' WHERE m.tenant_id = ?';
        $bindings = [$this->school->id()];
        if (isset($data['status'])) { $sql .= ' AND m.status = ?'; $bindings[] = $data['status']; }
        // FR-I18-01 AC2: « احمد » finds « أحمد », « helene » finds « Hélène », by name, email or username.
        if (isset($data['q'])) {
            $sql .= " AND position(coalesce(school.search_key(?), '') IN coalesce(school.search_key(concat_ws(' ', u.name, u.email, m.username)), '')) > 0";
            $bindings[] = $data['q'];
        }
        if (!$request->boolean('paginate')) return ['data' => DB::select($sql.' ORDER BY u.name, m.user_id', $bindings)];
        $total = (int) DB::selectOne('SELECT count(*) AS n FROM ('.$sql.') accounts', $bindings)->n;
        $perPage = $data['per_page'] ?? 25;
        $page = $data['page'] ?? 1;
        return ['data' => DB::select($sql.' ORDER BY u.name, m.user_id LIMIT ? OFFSET ?', [...$bindings, $perPage, ($page - 1) * $perPage]),
            'meta' => ['total' => $total, 'per_page' => $perPage, 'page' => $page]];
    }

    /**
     * Step 1 of FR-IAM-01: an activation link sent by email (`link`), or a provisional password
     * shown once to the administrator (`password`), changed at first sign-in. Roles may be given
     * at once, if the author may also assign roles.
     */
    public function store(Request $request)
    {
        $data = $request->validate($this->identityRules() + [
            'method' => ['required', Rule::in(['link', 'password'])],
            'roles' => 'nullable|array|max:20',
            'roles.*.role_id' => 'required|uuid',
            'roles.*.scope_type' => ['required', Rule::in(['tenant', 'site', 'level', 'subject', 'personal'])],
            'roles.*.scope_ids' => 'required_if:roles.*.scope_type,site,level,subject|nullable|array|min:1|max:100',
            'roles.*.scope_ids.*' => 'uuid',
        ]);
        if ($data['method'] === 'link' && empty($data['email'])) {
            throw ValidationException::withMessages(['email' => __('errors.activation_needs_email')]);
        }
        $provisional = $data['method'] === 'password' ? $this->accounts->provisional() : null;
        $created = $this->accounts->create($data, $provisional);
        foreach ($data['roles'] ?? [] as $role) {
            DB::insert('INSERT INTO school.role_assignment (tenant_id, user_id, role_id, scope_type, scope_ids)
                VALUES (?, ?, ?, ?, (SELECT array_agg(value::uuid) FROM json_array_elements_text(?::json)))',
                [$this->school->id(), $created->user_id, $role['role_id'], $role['scope_type'],
                 isset($role['scope_ids']) ? json_encode($role['scope_ids'], JSON_THROW_ON_ERROR) : null]);
        }
        $result = ['data' => $this->find($created->user_id)];
        // A person already known by this email is always invited: their password is theirs.
        if ($created->status === 'invited') $result += $this->accounts->invite($created->user_id);
        else $result['provisional_password'] = $provisional;
        return response()->json($result, 201);
    }

    /** The complete account: an email left out is removed. */
    public function update(Request $request, int $user)
    {
        abort_unless($this->find($user), 404);
        $data = $request->validate($this->identityRules());
        DB::select('SELECT school.update_account(?, ?, ?, ?, ?)',
            [$user, $data['name'], $data['email'] ?? null, $data['username'] ?? null, $data['external_ref'] ?? null]);
        return ['data' => $this->find($user)];
    }

    /** A new activation link; the previous one stops working (FR-IAM-01: expired link, resend). */
    public function invitation(int $user)
    {
        abort_unless($this->find($user), 404);
        return ['data' => $this->find($user)] + $this->accounts->invite($user);
    }

    public function status(Request $request, int $user)
    {
        abort_unless($this->find($user), 404);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended', 'revoked'])], 'reason' => 'required|string|min:3|max:1000']);
        DB::select('SELECT school.set_account_status(?, ?, ?)', [$user, $data['status'], $data['reason']]);
        return ['data' => $this->find($user)];
    }

    /** For an account without email, which has no recovery link: a provisional password, shown once. */
    public function password(int $user)
    {
        abort_unless($this->find($user), 404);
        $provisional = $this->accounts->provisional();
        DB::select('SELECT school.reset_password(?, ?)', [$user, Hash::make($provisional)]);
        return ['data' => $this->find($user), 'provisional_password' => $provisional];
    }

    /**
     * Accounts by their stable reference (FR-IAM-01 AC3). With `background`, a job imports them
     * by batches and reports at the end (FR-IMP-01, FR-JOB-01); the same key starts it once.
     */
    public function import(Request $request, Tasks $tasks)
    {
        $data = $request->validate([
            'invite' => 'boolean',
            'background' => 'boolean',
            'idempotency_key' => 'required_if:background,true|nullable|string|max:100',
            'rows' => 'required|array|min:1|max:5000',
            'rows.*.external_ref' => 'required|string|max:100|distinct',
            'rows.*.name' => 'required|string|max:200',
            'rows.*.email' => 'nullable|email|max:200',
            'rows.*.username' => ['nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{2,39}$/'],
        ]);
        $rows = array_map(fn ($row) => array_intersect_key($row, ['external_ref' => 1, 'name' => 1, 'email' => 1, 'username' => 1]), $data['rows']);
        if ($request->boolean('background')) {
            $job = $tasks->start('accounts.import', $data['idempotency_key'], ['rows' => $rows, 'invite' => $request->boolean('invite')]);
            return response()->json(['data' => $job], 202);
        }
        if (count($rows) > 1000) throw ValidationException::withMessages(['rows' => __('errors.rows_too_many')]);
        return $this->accounts->import($rows, $request->boolean('invite'));
    }

    private function find(int $user): ?object
    {
        return DB::selectOne(Accounts::SELECT.' WHERE m.tenant_id = ? AND m.user_id = ?', [$this->school->id(), $user]);
    }

    private function identityRules(): array
    {
        return ['name' => 'required|string|max:200', 'email' => 'nullable|email|max:200',
            'username' => ['nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{2,39}$/'], 'external_ref' => 'nullable|string|max:100'];
    }
}
