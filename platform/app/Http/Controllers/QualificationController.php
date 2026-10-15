<?php

namespace App\Http\Controllers;

use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-STF-02: the subjects a teacher may teach, each at a node of the referential (a level, a cycle,
 * a specialty) whose descendants it covers, from a day until another. A line ends, or is removed
 * while nothing uses it; the teacher's state, the catalogue and the overlaps are the database's (SQL 032).
 */
final class QualificationController
{
    // With its subject and its node, as the catalogue and the referential name them.
    private const COLUMNS = "id, staff_id, subject_id, node_id, valid_from, valid_to, version, created_at, updated_at,
        (SELECT jsonb_build_object('code', s.code, 'kind', s.kind, 'labels', s.labels, 'archived', s.archived_at IS NOT NULL)
         FROM school.subject s WHERE s.tenant_id = qualification.tenant_id AND s.id = qualification.subject_id) AS subject,
        (SELECT jsonb_build_object('code', n.code, 'type_code', n.type_code, 'labels', n.labels, 'archived', n.archived_at IS NOT NULL)
         FROM school.ref_node n WHERE n.tenant_id = qualification.tenant_id AND n.id = qualification.node_id) AS node";
    // In the catalogue's order, then the referential's.
    private const ORDER = '(SELECT s.code FROM school.subject s WHERE s.tenant_id = qualification.tenant_id AND s.id = qualification.subject_id),
        (SELECT n.code FROM school.ref_node n WHERE n.tenant_id = qualification.tenant_id AND n.id = qualification.node_id), valid_from, id';

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    public function index(Request $request, string $staff)
    {
        $this->records->find('staff', $staff, columns: 'id');
        $request->validate(Paging::RULES + ['include_ended' => 'boolean']);
        $sql = 'SELECT '.self::COLUMNS.' FROM school.qualification WHERE tenant_id = ? AND staff_id = ?';
        // An ended line is history; one ending later still holds.
        if (!$request->boolean('include_ended')) $sql .= ' AND (valid_to IS NULL OR valid_to >= current_date)';
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, [$this->school->id(), $staff], self::ORDER, fn ($row) => $this->present($row));
    }

    public function store(Request $request, string $staff)
    {
        $row = $this->records->find('staff', $staff, columns: 'id');
        DB::select('SELECT school.require_staff(?, ?)', ['staff.qualification.manage', $row->id]);
        $data = $request->validate(['subject_id' => 'required|uuid', 'node_id' => 'required|uuid',
            'valid_from' => 'sometimes|nullable|date_format:Y-m-d', 'valid_to' => 'sometimes|nullable|date_format:Y-m-d',
            ...array_fill_keys(['tenant_id', 'staff_id'], 'prohibited')]);
        $this->records->find('subject', $data['subject_id'], columns: 'id');
        $this->records->find('ref_node', $data['node_id'], columns: 'id');
        unset($data['tenant_id'], $data['staff_id']);
        $id = DB::table('school.qualification')->insertGetId(['tenant_id' => $this->school->id(), 'staff_id' => $row->id, ...$data], 'id');
        return response()->json(['data' => $this->present($this->find($id))], 201);
    }

    /** Held until that day included; final. */
    public function end(Request $request, string $line)
    {
        $row = $this->find($line, true);
        DB::select('SELECT school.require_staff(?, ?)', ['staff.qualification.manage', $row->staff_id]);
        $data = $request->validate(['valid_to' => 'required|date_format:Y-m-d', 'version' => 'required|integer|min:1']);
        DB::table('school.qualification')->where('tenant_id', $this->school->id())->where('id', $row->id)->update($data);
        return ['data' => $this->present($this->find($row->id))];
    }

    /** A line nothing uses is deleted; the journal keeps its values. */
    public function destroy(Request $request, string $line)
    {
        $row = $this->find($line, true);
        DB::select('SELECT school.require_staff(?, ?)', ['staff.qualification.manage', $row->staff_id]);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.qualification')->where('tenant_id', $this->school->id())->where('id', $row->id)->delete();
        return response()->noContent();
    }

    private function find(string $id, bool $lock = false): object { return $this->records->find('qualification', $id, $lock, self::COLUMNS); }

    private function present(object $row): array
    {
        $data = (array) $row;
        foreach (['subject', 'node'] as $column) {
            $data[$column] = $data[$column] === null ? null : json_decode($data[$column], true, 8, JSON_THROW_ON_ERROR);
        }
        return $data;
    }
}
