<?php

namespace App\Http\Controllers;

use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-LMS-04: the grading rules of a programme or a level, versioned without overlap, never
 * rewritten; a group's gradebook in a subject over a period, its marks on their activities' scales
 * and its weighted averages from the published marks only; the pupil's own averages (SQL 084).
 */
final class GradingController
{
    private const COLUMNS = "r.id, r.program_id, r.node_id, r.scale_max, r.step, r.rounding, r.pass_ratio, r.valid_from, r.valid_to, r.version, r.created_at,
        p.code AS program_code, n.code AS node_code";
    private const FROM = ' FROM school.grading_rule r LEFT JOIN school.program p ON p.tenant_id = r.tenant_id AND p.id = r.program_id
        LEFT JOIN school.ref_node n ON n.tenant_id = r.tenant_id AND n.id = r.node_id';

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['program_id' => 'nullable|uuid', 'node_id' => 'nullable|uuid', 'include_ended' => 'boolean']);
        $sql = 'SELECT '.self::COLUMNS.self::FROM.' WHERE r.tenant_id = ?';
        $bindings = [$this->school->id()];
        foreach (['program_id', 'node_id'] as $column) {
            if (isset($data[$column])) { $sql .= ' AND r.'.$column.' = ?'; $bindings[] = $data[$column]; }
        }
        if (!$request->boolean('include_ended')) $sql .= ' AND (r.valid_to IS NULL OR r.valid_to >= current_date)';
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, 'coalesce(p.code, n.code), r.valid_from, r.id');
    }

    /** A programme's or a level's rule from today or later. */
    public function store(Request $request)
    {
        $data = $request->validate(['program_id' => 'required_without:node_id|nullable|uuid', 'node_id' => 'required_without:program_id|nullable|uuid',
            'scale_max' => ['required', 'regex:/^\d{1,4}(\.\d{1,2})?$/'], 'step' => 'sometimes|in:0.25,0.5,1', 'rounding' => 'sometimes|in:half_up,down,up',
            'pass_ratio' => ['sometimes', 'regex:/^(0(\.\d{1,3})?|1(\.0{1,3})?)$/'], 'valid_from' => 'sometimes|nullable|date_format:Y-m-d',
            'valid_to' => 'sometimes|nullable|date_format:Y-m-d', 'tenant_id' => 'prohibited']);
        if (empty($data['valid_from'])) unset($data['valid_from']);
        $id = DB::table('school.grading_rule')->insertGetId(['tenant_id' => $this->school->id(), ...$data], 'id');
        return response()->json(['data' => $this->find($id)], 201);
    }

    /** Ended, yesterday at the earliest; final. */
    public function end(Request $request, string $rule)
    {
        $row = $this->records->find('grading_rule', $rule, true, 'id, version');
        $data = $request->validate(['valid_to' => 'required|date_format:Y-m-d', 'version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.grading_rule')->where('tenant_id', $this->school->id())->where('id', $row->id)->update($data);
        return ['data' => $this->find($row->id)];
    }

    /** One that has not begun. */
    public function destroy(Request $request, string $rule)
    {
        $row = $this->records->find('grading_rule', $rule, true, 'id, version');
        $this->records->version($row, (int) $request->validate(['version' => 'required|integer|min:1'])['version']);
        DB::table('school.grading_rule')->where('tenant_id', $this->school->id())->where('id', $row->id)->delete();
        return response()->noContent();
    }

    /** A group's gradebook in a subject over a period: each pupil, their marks and their average. */
    public function gradebook(Request $request, string $group)
    {
        abort_unless(Str::isUuid($group), 404);
        $data = $request->validate(['subject_id' => 'required|uuid', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        return ['data' => $this->book($group, $data['subject_id'], $data['from'], $data['to'])];
    }

    /** @return list<array> each pupil with their names, marks and average */
    public function book(string $group, string $subject, string $from, string $to): array
    {
        $marks = collect(DB::select('SELECT * FROM school.gradebook(?, ?, ?::date, ?::date)', [$group, $subject, $from, $to]))->groupBy('student_id');
        $averages = collect(DB::select('SELECT * FROM school.gradebook_averages(?, ?, ?::date, ?::date)', [$group, $subject, $from, $to]))->keyBy('student_id');
        $pupils = $marks->keys()->all();
        $names = collect($pupils === [] ? [] : DB::select('SELECT id, matricule, last_name_ar, first_name_ar, last_name_lat, first_name_lat FROM school.student
            WHERE tenant_id = ? AND id = ANY (?::uuid[])', [$this->school->id(), '{'.implode(',', $pupils).'}']))->keyBy('id');
        return $marks->map(fn ($rows, $student) => ['student_id' => $student, 'student' => isset($names[$student]) ? (array) $names[$student] : null,
            'marks' => $rows->map(fn ($row) => (array) $row)->values()->all(), 'average' => isset($averages[$student]) ? (array) $averages[$student] : null])
            ->sortBy(fn (array $row) => ($row['student']['last_name_lat'] ?? '').' '.($row['student']['first_name_lat'] ?? ''))->values()->all();
    }

    /** The caller's own averages over a period, subject by subject, and their marks on their scales. */
    public function mine(Request $request)
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        return ['data' => ['averages' => DB::select('SELECT * FROM school.my_averages(?::date, ?::date)', [$data['from'], $data['to']]),
            'marks' => DB::select('SELECT * FROM school.my_marks()')]];
    }

    private function find(string $id): array
    {
        $row = DB::selectOne('SELECT '.self::COLUMNS.self::FROM.' WHERE r.tenant_id = ? AND r.id = ?', [$this->school->id(), $id]);
        abort_unless($row, 404);
        return (array) $row;
    }
}
