<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-INS-05: the annual progression. A batch of decisions, one per validated enrolment, in the
 * request's one transaction: if one target fails (capacity, a pupil already enrolled), none is
 * made (AC1). A promotion or a repetition enrols the pupil, pending, in a class of a later year
 * (SQL 040); the same decision again makes nothing (AC2).
 */
final class ProgressionController
{
    // With the pupil and the target class, as the reader may see them.
    private const SELECT = "SELECT p.id, p.enrollment_id, p.decision, p.reason, p.target_group_id, p.due_date, p.target_enrollment_id, p.created_by,
        (SELECT u.name FROM public.users u WHERE u.id = p.created_by) AS decided_by, p.created_at,
        (SELECT jsonb_build_object('matricule', s.matricule, 'last_name_ar', s.last_name_ar, 'first_name_ar', s.first_name_ar,
           'last_name_lat', s.last_name_lat, 'first_name_lat', s.first_name_lat)
         FROM school.enrollment e JOIN school.student s ON s.tenant_id = e.tenant_id AND s.id = e.student_id
         WHERE e.tenant_id = p.tenant_id AND e.id = p.enrollment_id) AS student,
        (SELECT jsonb_build_object('code', g.code, 'labels', g.labels) FROM school.study_group g
         WHERE g.tenant_id = p.tenant_id AND g.id = p.target_group_id) AS target_group
        FROM school.progression p JOIN school.enrollment source ON source.tenant_id = p.tenant_id AND source.id = p.enrollment_id";
    private const DECISIONS = 'promoted,repeated,left,completed';

    public function __construct(private CurrentSchool $school) {}

    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['year_id' => 'nullable|uuid', 'decision' => 'nullable|in:'.self::DECISIONS,
            'target_group_id' => 'nullable|uuid']);
        $sql = self::SELECT.' WHERE p.tenant_id = ?';
        $bindings = [$this->school->id()];
        foreach (['year_id' => 'source.year_id', 'decision' => 'p.decision', 'target_group_id' => 'p.target_group_id'] as $field => $column) {
            if (isset($data[$field])) { $sql .= " AND $column = ?"; $bindings[] = $data[$field]; }
        }
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, 'p.created_at DESC, p.id', fn ($row) => $this->present($row));
    }

    /** `decisions`: each an enrolment, its decision and reason, and for a promotion or a repetition the target class and the first due date. */
    public function store(Request $request)
    {
        $data = $request->validate(['decisions' => 'required|array|min:1|max:200', 'decisions.*.enrollment_id' => 'required|uuid|distinct',
            'decisions.*.decision' => 'required|in:'.self::DECISIONS, 'decisions.*.reason' => 'required|string|min:3|max:500',
            'decisions.*.target_group_id' => 'nullable|uuid', 'decisions.*.due_date' => 'nullable|date_format:Y-m-d']);
        $ids = [];
        foreach ($data['decisions'] as $one) {
            $ids[] = DB::selectOne('SELECT school.decide_progression(?, ?, ?, ?, ?::date) AS id', [$one['enrollment_id'], $one['decision'], $one['reason'],
                $one['target_group_id'] ?? null, $one['due_date'] ?? null])->id;
        }
        $rows = DB::select(self::SELECT.' WHERE p.tenant_id = ? AND p.id = ANY (?::uuid[])', [$this->school->id(), '{'.implode(',', $ids).'}']);
        return response()->json(['data' => array_map(fn ($row) => $this->present($row), $rows)], 201);
    }

    private function present(object $row): array
    {
        $data = (array) $row;
        foreach (['student', 'target_group'] as $column) if ($data[$column] !== null) $data[$column] = json_decode($data[$column], true, 8, JSON_THROW_ON_ERROR);
        return $data;
    }
}
