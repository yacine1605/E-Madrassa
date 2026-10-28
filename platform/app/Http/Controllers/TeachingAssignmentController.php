<?php

namespace App\Http\Controllers;

use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lot 5 §1: who teaches a group's subject, from a day until another, one teacher at a time. Lot
 * 5's activities are published by the teacher assigned that day or by the pedagogy manager. The
 * group's programme, the teacher's qualification and the overlaps are the database's (SQL 077).
 */
final class TeachingAssignmentController
{
    // With its group, subject and teacher, as the groups, the catalogue and the staff name them.
    private const COLUMNS = "a.id, a.group_id, a.subject_id, a.staff_id, a.valid_from, a.valid_to, a.version, a.created_at, a.updated_at,
        g.code AS group_code, g.labels AS group_labels, g.site_id, s.code AS subject_code, s.labels AS subject_labels,
        t.last_name_ar AS teacher_last_name_ar, t.first_name_ar AS teacher_first_name_ar, t.last_name_lat AS teacher_last_name_lat,
        t.first_name_lat AS teacher_first_name_lat, school.is_own_staff(a.staff_id) AS mine";
    private const FROM = ' FROM school.teaching_assignment a
        JOIN school.study_group g ON g.tenant_id = a.tenant_id AND g.id = a.group_id
        JOIN school.subject s ON s.tenant_id = a.tenant_id AND s.id = a.subject_id
        LEFT JOIN school.staff t ON t.tenant_id = a.tenant_id AND t.id = a.staff_id';

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    /** The assignments of the groups the reader sees, or their own; those in force and to come unless asked. */
    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['group_id' => 'nullable|uuid', 'subject_id' => 'nullable|uuid', 'staff_id' => 'nullable|uuid',
            'mine' => 'boolean', 'include_ended' => 'boolean']);
        $sql = 'SELECT '.self::COLUMNS.self::FROM.' WHERE a.tenant_id = ?';
        $bindings = [$this->school->id()];
        foreach (['group_id', 'subject_id', 'staff_id'] as $column) {
            if (isset($data[$column])) { $sql .= ' AND a.'.$column.' = ?'; $bindings[] = $data[$column]; }
        }
        if ($request->boolean('mine')) $sql .= ' AND school.is_own_staff(a.staff_id)';
        if (!$request->boolean('include_ended')) $sql .= ' AND (a.valid_to IS NULL OR a.valid_to >= current_date)';
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, 'g.code, s.code, a.valid_from, a.id', fn ($row) => $this->present($row));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['group_id' => 'required|uuid', 'subject_id' => 'required|uuid', 'staff_id' => 'required|uuid',
            'valid_from' => 'sometimes|nullable|date_format:Y-m-d', 'valid_to' => 'sometimes|nullable|date_format:Y-m-d',
            'tenant_id' => 'prohibited']);
        $this->records->find('study_group', $data['group_id'], columns: 'id');
        $this->records->find('subject', $data['subject_id'], columns: 'id');
        $this->records->find('staff', $data['staff_id'], columns: 'id');
        $id = DB::table('school.teaching_assignment')->insertGetId(['tenant_id' => $this->school->id(), ...$data], 'id');
        return response()->json(['data' => $this->present($this->find($id))], 201);
    }

    /** Taught until that day included, yesterday at the earliest; final. */
    public function end(Request $request, string $assignment)
    {
        $row = $this->find($assignment, true);
        $data = $request->validate(['valid_to' => 'required|date_format:Y-m-d', 'version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.teaching_assignment')->where('tenant_id', $this->school->id())->where('id', $row->id)->update($data);
        return ['data' => $this->present($this->find($row->id))];
    }

    /** One that has not begun; the journal keeps its values. */
    public function destroy(Request $request, string $assignment)
    {
        $row = $this->find($assignment, true);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.teaching_assignment')->where('tenant_id', $this->school->id())->where('id', $row->id)->delete();
        return response()->noContent();
    }

    private function find(string $id, bool $lock = false): object
    {
        abort_unless(Str::isUuid($id), 404);
        $row = DB::selectOne('SELECT '.self::COLUMNS.self::FROM.' WHERE a.tenant_id = ? AND a.id = ?'.($lock ? ' FOR UPDATE OF a' : ''), [$this->school->id(), $id]);
        abort_unless($row, 404);
        return $row;
    }

    private function present(object $row): array
    {
        $data = (array) $row;
        foreach (['group_labels', 'subject_labels'] as $column) $data[$column] = json_decode($data[$column], true, 8, JSON_THROW_ON_ERROR);
        return $data;
    }
}
