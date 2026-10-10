<?php

namespace App\Http\Controllers;

use App\Support\AccountLinks;
use App\Support\CurrentSchool;
use App\Support\DatabaseErrors;
use Illuminate\Database\QueryException;
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
    private const SELECT = 'SELECT m.user_id AS id, u.name, u.email, m.username, m.external_ref, m.status, m.activated_at, m.created_at,
        u.mfa_enabled_at IS NOT NULL AS mfa_enabled FROM school.membership m JOIN public.users u ON u.id = m.user_id';
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private CurrentSchool $school) {}

    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['invited', 'active', 'suspended', 'revoked'])]]);
        $sql = self::SELECT.' WHERE m.tenant_id = ?';
        $bindings = [$this->school->id()];
        if (isset($data['status'])) { $sql .= ' AND m.status = ?'; $bindings[] = $data['status']; }
        return ['data' => DB::select($sql.' ORDER BY u.name, m.user_id', $bindings)];
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
            throw ValidationException::withMessages(['email' => 'Un lien d’activation s’envoie à une adresse e-mail.']);
        }
        $provisional = $data['method'] === 'password' ? $this->provisional() : null;
        $created = $this->create($data, $provisional);
        foreach ($data['roles'] ?? [] as $role) {
            DB::insert('INSERT INTO school.role_assignment (tenant_id, user_id, role_id, scope_type, scope_ids)
                VALUES (?, ?, ?, ?, (SELECT array_agg(value::uuid) FROM json_array_elements_text(?::json)))',
                [$this->school->id(), $created->user_id, $role['role_id'], $role['scope_type'],
                 isset($role['scope_ids']) ? json_encode($role['scope_ids'], JSON_THROW_ON_ERROR) : null]);
        }
        $result = ['data' => $this->find($created->user_id)];
        // A person already known by this email is always invited: their password is theirs.
        if ($created->status === 'invited') $result += $this->invite($created->user_id);
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
        return ['data' => $this->find($user)] + $this->invite($user);
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
        $provisional = $this->provisional();
        DB::select('SELECT school.reset_password(?, ?)', [$user, Hash::make($provisional)]);
        return ['data' => $this->find($user), 'provisional_password' => $provisional];
    }

    /**
     * FR-IAM-01 AC3, BR-05: accounts by their stable reference. Imported again, a row updates
     * its account or leaves it as it is, never duplicates it. Accounts are created invited; with
     * `invite`, those with an email receive their link. A refused row is reported, not fatal.
     */
    public function import(Request $request)
    {
        $data = $request->validate([
            'invite' => 'boolean',
            'rows' => 'required|array|min:1|max:1000',
            'rows.*.external_ref' => 'required|string|max:100|distinct',
            'rows.*.name' => 'required|string|max:200',
            'rows.*.email' => 'nullable|email|max:200',
            'rows.*.username' => ['nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{2,39}$/'],
        ]);
        $existing = collect(DB::select(self::SELECT.' WHERE m.tenant_id = ? AND m.external_ref IS NOT NULL', [$this->school->id()]))->keyBy('external_ref');
        $report = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'invited' => 0, 'rejected' => []];
        foreach ($data['rows'] as $index => $row) {
            $row['external_ref'] = trim($row['external_ref']);
            $current = $existing->get($row['external_ref']);
            try {
                // Each row in its own savepoint: a refused row leaves the others in place.
                $outcome = DB::transaction(function () use ($row, $current, $data) {
                    if (!$current) {
                        $created = $this->create($row, null);
                        return $created->status === 'invited' && ($data['invite'] ?? false) && !empty($row['email'])
                            && ($this->invite($created->user_id)['delivery'] === 'sent') ? 'invited' : 'created';
                    }
                    $wanted = [trim($row['name']), self::lower($row['email'] ?? null), self::lower($row['username'] ?? null)];
                    if ($wanted === [$current->name, $current->email, $current->username]) return 'unchanged';
                    DB::select('SELECT school.update_account(?, ?, ?, ?, ?)',
                        [$current->id, $row['name'], $row['email'] ?? null, $row['username'] ?? null, $row['external_ref']]);
                    return 'updated';
                });
                if ($outcome === 'invited') $report['created']++;
                $report[$outcome]++;
            } catch (QueryException $e) {
                $refusal = DatabaseErrors::classify($e) ?? throw $e;
                $report['rejected'][] = ['row' => $index + 1, 'external_ref' => $row['external_ref'], 'code' => $refusal[1], 'message' => $refusal[2]];
            }
        }
        return $report;
    }

    private function create(array $data, ?string $provisional): object
    {
        return DB::selectOne('SELECT user_id, linked, status FROM school.create_account(?, ?, ?, ?, ?)', [$data['name'], $data['email'] ?? null,
            $data['username'] ?? null, $data['external_ref'] ?? null, $provisional === null ? null : Hash::make($provisional)]);
    }

    /** @return array{delivery: string, activation_url?: string} */
    private function invite(int $user): array
    {
        [$token, $hash] = AccountLinks::token();
        DB::select('SELECT school.issue_activation(?, ?)', [$user, $hash]);
        $person = DB::selectOne('SELECT name, email, password IS NULL AS fresh FROM public.users WHERE id = ?', [$user]);
        $school = DB::selectOne('SELECT name FROM platform.tenant WHERE id = ?', [$this->school->id()])->name;
        if ($person->email && AccountLinks::send('activation', $person->email, $person->name, $token, $school)) return ['delivery' => 'sent'];
        // FR-IAM-01: when sending fails, the administrator may hand the link over, but only for
        // a person who has no password yet: the link never opens an existing person's account.
        return ['delivery' => 'failed'] + ($person->fresh ? ['activation_url' => AccountLinks::url('activation', $token)] : []);
    }

    private function find(int $user): ?object
    {
        return DB::selectOne(self::SELECT.' WHERE m.tenant_id = ? AND m.user_id = ?', [$this->school->id(), $user]);
    }

    private function identityRules(): array
    {
        return ['name' => 'required|string|max:200', 'email' => 'nullable|email|max:200',
            'username' => ['nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{2,39}$/'], 'external_ref' => 'nullable|string|max:100'];
    }

    private function provisional(): string
    {
        $password = '';
        for ($i = 0; $i < 12; $i++) $password .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        return $password;
    }

    private static function lower(?string $value): ?string
    {
        $value = $value === null ? null : mb_strtolower(trim($value));
        return $value === '' ? null : $value;
    }
}
