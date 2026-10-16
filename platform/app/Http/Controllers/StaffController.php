<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use App\Support\PupilRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FR-STF-01: the staff's records, with or without an account (P7), on a site; a departure with
 * its day, never a deletion. Row access, transitions and the account's rules are the database's (SQL 031).
 */
final class StaffController
{
    use HandlesLabels;

    // With the linked account's name and e-mail, as the reader may see the school's people (020).
    private const SELECT = "SELECT id, staff_number, last_name_ar, first_name_ar, last_name_lat, first_name_lat, staff_type, phone, email,
        hire_date, status, left_on, site_id, custom, user_id, version, archived_at, created_at, updated_at,
        (SELECT jsonb_build_object('name', u.name, 'email', u.email) FROM public.users u WHERE u.id = staff.user_id) AS account,
        ".self::PENDING." AS availability_pending
        FROM school.staff";
    // FR-STF-03: a declaration or a day off of theirs waits for an approver.
    private const PENDING = "(EXISTS (SELECT 1 FROM school.availability a WHERE a.tenant_id = staff.tenant_id AND a.staff_id = staff.id AND a.status = 'pending')
        OR EXISTS (SELECT 1 FROM school.unavailability v WHERE v.tenant_id = staff.tenant_id AND v.staff_id = staff.id AND v.status = 'pending'))";
    public const TYPES = ['teacher_internal', 'teacher_external', 'administrative', 'other'];

    public function __construct(private CurrentSchool $school, private PupilRecords $records, private AcademicRecords $academic) {}

    /**
     * FR-STF-02 AC2: `subject_id` with `node_id` (and `on`, today by default) keeps the teachers qualified for them, a parent node's
     * qualification covering its children. FR-STF-03: `availability_pending` keeps those whose availability waits for an approver.
     */
    public function index(Request $request)
    {
        $qualified = $request->validate(['subject_id' => 'nullable|uuid|required_with:node_id', 'node_id' => 'nullable|uuid|required_with:subject_id',
            'on' => 'nullable|date_format:Y-m-d', 'availability_pending' => 'boolean']);
        $conditions = $bindings = [];
        if (isset($qualified['subject_id'])) {
            $conditions[] = 'school.qualified(id, ?::uuid, ?::uuid, coalesce(?::date, current_date))';
            array_push($bindings, $qualified['subject_id'], $qualified['node_id'], $qualified['on'] ?? null);
        }
        if ($request->boolean('availability_pending')) $conditions[] = self::PENDING;
        $where = $conditions ? [implode(' AND ', $conditions), $bindings] : null;
        return $this->academic->listing($request, self::SELECT, ['status' => 'nullable|in:active,on_leave,left',
            'staff_type' => 'nullable|in:'.implode(',', self::TYPES), 'site_id' => 'nullable|uuid'],
            [...PupilRecords::NAMES, 'phone', 'email', 'staff_number'], 'name_key, id', fn ($row) => $this->present($row), $where);
    }

    public function show(string $staff) { return ['data' => $this->present($this->find($staff))]; }

    private function rules(bool $create): array
    {
        return $this->records->rules() + ['staff_type' => ($create ? 'required' : 'sometimes').'|in:'.implode(',', self::TYPES),
            'hire_date' => 'nullable|date_format:Y-m-d', 'status' => 'prohibited', 'left_on' => 'prohibited',
            'staff_number' => $create ? ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,30}$/'] : 'prohibited'];
    }

    public function store(Request $request)
    {
        $this->records->createPermission('staff.profile.create');
        $data = $request->validate($this->rules(true));
        unset($data['tenant_id'], $data['user_id'], $data['photo_file_id']);
        if (($data['staff_number'] ?? null) === null) unset($data['staff_number']);
        $this->records->name($data);
        $this->records->site($data);
        $data['custom'] = $this->records->custom('staff', $data['custom'] ?? []);
        $id = DB::table('school.staff')->insertGetId(['tenant_id' => $this->school->id(), ...$data], 'id');
        return response()->json($this->show($id), 201);
    }

    public function update(Request $request, string $staff)
    {
        $current = $this->find($staff, true);
        DB::select('SELECT school.require_staff(?, ?)', ['staff.profile.update', $current->id]);
        $data = $request->validate($this->rules(false) + ['version' => 'required|integer|min:1']);
        unset($data['tenant_id'], $data['user_id'], $data['photo_file_id'], $data['staff_number']);
        $this->records->name(array_replace((array) $current, $data));
        $this->records->site($data);
        if (array_key_exists('custom', $data)) $data['custom'] = $this->records->custom('staff', $data['custom'], $current);
        DB::table('school.staff')->where('tenant_id', $this->school->id())->where('id', $current->id)->update($data);
        return $this->show($current->id);
    }

    /** Active, on leave or left (with its day, today by default); the reason goes to the journal. */
    public function status(Request $request, string $staff)
    {
        $current = $this->find($staff, true);
        DB::select('SELECT school.require_staff(?, ?)', ['staff.profile.update', $current->id]);
        $data = $request->validate(['status' => 'required|in:active,on_leave,left', 'reason' => 'required|string|min:3|max:500',
            'version' => 'required|integer|min:1', 'left_on' => 'nullable|date_format:Y-m-d|exclude_unless:status,left']);
        $this->academic->version($current, (int) $data['version']);
        $this->academic->reason($data['reason']);
        // Still gone, without a new day: the day they left stays.
        $left = $data['left_on'] ?? ($current->status === 'left' ? $current->left_on : null);
        DB::table('school.staff')->where('tenant_id', $this->school->id())->where('id', $current->id)
            ->update(['status' => $data['status'], 'left_on' => $data['status'] === 'left' ? $left : null, 'version' => $data['version']]);
        return $this->show($current->id);
    }

    /** The account the member signs in with, among the school's (not revoked); none to unlink. */
    public function account(Request $request, string $staff)
    {
        $current = $this->find($staff, true);
        DB::select('SELECT school.require_staff(?, ?)', ['staff.profile.update', $current->id]);
        $data = $request->validate(['user_id' => 'present|nullable|integer|min:1', 'version' => 'required|integer|min:1']);
        if ($data['user_id'] !== null && !DB::selectOne("SELECT 1 FROM school.membership WHERE tenant_id = ? AND user_id = ? AND status <> 'revoked'",
            [$this->school->id(), $data['user_id']])) {
            throw ValidationException::withMessages(['user_id' => __('errors.account_unknown')]);
        }
        $this->academic->version($current, (int) $data['version']);
        DB::table('school.staff')->where('tenant_id', $this->school->id())->where('id', $current->id)
            ->update(['user_id' => $data['user_id'], 'version' => $data['version']]);
        return $this->show($current->id);
    }

    /** Only a member who left; the record leaves the lists, readable with the archived ones, and no longer changes. */
    public function archive(Request $request, string $staff)
    {
        $current = $this->find($staff, true);
        DB::select('SELECT school.require_staff(?, ?)', ['staff.profile.update', $current->id]);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500', 'version' => 'required|integer|min:1']);
        if ($current->status !== 'left') throw Refusal::of('staff_archive_left');
        $this->academic->reason($data['reason']);
        DB::table('school.staff')->where('tenant_id', $this->school->id())->where('id', $current->id)
            ->update(['archived_at' => now(), 'version' => $data['version']]);
        return $this->show($current->id);
    }

    /** The school's accounts a record may be linked to: not revoked, not already the record of a member the reader sees. */
    public function accounts(Request $request)
    {
        $this->records->createPermission('staff.profile.update');
        $data = $request->validate(Paging::RULES + ['q' => 'nullable|string|max:100']);
        $sql = "SELECT m.user_id AS id, u.name, u.email, m.status FROM school.membership m JOIN public.users u ON u.id = m.user_id
            WHERE m.tenant_id = ? AND m.status <> 'revoked' AND NOT EXISTS (SELECT 1 FROM school.staff s WHERE s.tenant_id = m.tenant_id AND s.user_id = m.user_id)";
        $bindings = [$this->school->id()];
        if (isset($data['q']) && $data['q'] !== '') { $sql .= ' AND '.Paging::search(['u.name', 'u.email']); $bindings[] = $data['q']; }
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, 'u.name, m.user_id');
    }

    private function present(object $row): array
    {
        $data = $this->records->present($row, 'staff');
        $data['account'] = $data['account'] === null ? null : json_decode($data['account'], true, 4, JSON_THROW_ON_ERROR);
        return $data;
    }

    private function find(string $id, bool $lock = false): object
    {
        $row = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?'.($lock ? ' FOR UPDATE' : ''), [$this->school->id(), $this->uuid($id)]);
        abort_unless($row, 404);
        return $row;
    }
}
