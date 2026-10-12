<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class GroupMembershipController
{
    private const COLUMNS = 'id, enrollment_id, group_id, is_main, valid_from, valid_to, version, created_at, updated_at';

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    public function index(Request $request, string $group)
    {
        $this->records->find('study_group', $group);
        $request->validate(Paging::RULES + ['include_history' => 'boolean', 'enrollment_id' => 'nullable|uuid']);
        $sql = 'SELECT '.self::COLUMNS.' FROM school.group_membership WHERE tenant_id = ? AND group_id = ?';
        $bindings = [$this->school->id(), $group];
        if (!$request->boolean('include_history')) $sql .= ' AND valid_to IS NULL';
        if ($request->filled('enrollment_id')) { $sql .= ' AND enrollment_id = ?'; $bindings[] = $request->input('enrollment_id'); }
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, 'valid_from, id');
    }

    public function store(Request $request, string $group)
    {
        $this->records->global('group.membership.manage');
        $this->records->find('study_group', $group);
        $data = $request->validate(['enrollment_id' => 'required|uuid', 'valid_from' => 'sometimes|date_format:Y-m-d',
            ...array_fill_keys(['tenant_id', 'group_id', 'is_main', 'valid_to'], 'prohibited')]);
        $this->records->find('enrollment', $data['enrollment_id']);
        unset($data['tenant_id'], $data['group_id'], $data['is_main'], $data['valid_to']);
        $id = DB::table('school.group_membership')->insertGetId(['tenant_id' => $this->school->id(), 'group_id' => $group, ...$data], 'id');
        return response()->json(['data' => (array) $this->find($id)], 201);
    }

    public function end(Request $request, string $membership)
    {
        $row = $this->find($membership, true);
        $this->records->action('group.membership.manage', 'group_membership', $row->id);
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:500']);
        $this->records->version($row, (int) $data['version']);
        if ($row->valid_to !== null) throw Refusal::of('membership_ended');
        $this->records->reason($data['reason']);
        $changed = DB::selectOne('UPDATE school.group_membership SET valid_to = greatest(current_date, valid_from), version = ? WHERE tenant_id = ? AND id = ? RETURNING '.self::COLUMNS,
            [$data['version'], $this->school->id(), $row->id]);
        abort_unless($changed, 404);
        return ['data' => (array) $changed];
    }

    public function transfer(Request $request, string $membership)
    {
        // The destination is a new membership; schema 021 currently requires a tenant grant.
        $this->records->global('group.membership.manage');
        $row = $this->find($membership, true);
        $data = $request->validate(['group_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:500']);
        $this->records->version($row, (int) $data['version']);
        $this->records->find('study_group', $data['group_id']);
        if ($row->group_id === $data['group_id']) throw Refusal::of('group_unchanged');
        $this->records->reason($data['reason']);
        $id = DB::selectOne('SELECT school.move_to_group(?, ?) AS id', [$row->id, $data['group_id']])->id;
        return ['data' => (array) $this->find($id), 'previous' => (array) $this->find($row->id)];
    }

    private function find(string $id, bool $lock = false): object { return $this->records->find('group_membership', $id, $lock, self::COLUMNS); }
}
