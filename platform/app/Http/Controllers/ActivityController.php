<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\LessonDocument;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * FR-LMS-02, FR-LMS-03: a quiz or a homework of a group's subject, written by the teacher assigned
 * to it that day or the pedagogy manager, published in frozen versions for the group's pupils with
 * access, a pupil added later explicitly, one given a deadline of their own with its reason. Who may
 * write, the dates, the questions' choices and points, the versions and the audience are the
 * database's (SQL 080). Times are entered in Algiers' time.
 */
final class ActivityController
{
    private const COLUMNS = "a.id, a.group_id, a.subject_id, a.kind, a.title, a.instructions, a.lesson_id, a.opens_at, a.deadline_at, a.duration_minutes,
        a.max_points, a.coefficient, a.max_attempts, a.selection, a.late_policy, a.late_until, a.results_visibility, a.key_visibility, a.replacement_hours,
        a.status, a.published_version, a.version, a.archived_at, a.created_at, a.updated_at,
        g.code AS group_code, s.code AS subject_code, s.labels AS subject_labels,
        (SELECT count(*) FROM school.activity_audience x WHERE x.tenant_id = a.tenant_id AND x.activity_id = a.id)::integer AS audience,
        school.activity_state(a.published_version IS NOT NULL, a.archived_at, coalesce(a.opens_at, now()), coalesce(a.late_until, a.deadline_at)) AS state";
    private const FROM = ' FROM school.activity a
        LEFT JOIN school.study_group g ON g.tenant_id = a.tenant_id AND g.id = a.group_id
        LEFT JOIN school.subject s ON s.tenant_id = a.tenant_id AND s.id = a.subject_id';

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    /** The activities of the groups' subjects the reader teaches today, or all of them for the manager. */
    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['group_id' => 'nullable|uuid', 'subject_id' => 'nullable|uuid', 'include_archived' => 'boolean']);
        $sql = 'SELECT '.self::COLUMNS.self::FROM.' WHERE a.tenant_id = ?';
        $bindings = [$this->school->id()];
        foreach (['group_id', 'subject_id'] as $column) {
            if (isset($data[$column])) { $sql .= ' AND a.'.$column.' = ?'; $bindings[] = $data[$column]; }
        }
        if (!$request->boolean('include_archived')) $sql .= ' AND a.archived_at IS NULL';
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, 'a.deadline_at DESC, a.id', fn ($row) => $this->present($row));
    }

    private function rules(bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';
        $visibility = 'sometimes|in:immediate,after_deadline,on_teacher_publish';
        return ($create ? ['group_id' => 'required|uuid', 'subject_id' => 'required|uuid'] : []) + [
            'kind' => $required.'|in:quiz,homework', 'title' => $required.'|string|min:1|max:200', 'instructions' => 'sometimes|array',
            'lesson_id' => 'sometimes|nullable|uuid', 'opens_at' => 'sometimes|nullable|date', 'deadline_at' => $required.'|date',
            'duration_minutes' => 'sometimes|nullable|integer|min:1|max:600', 'max_points' => ['sometimes', 'regex:/^\d{1,5}(\.\d{1,2})?$/'],
            'coefficient' => ['sometimes', 'regex:/^\d{1,3}(\.\d{1,2})?$/'], 'max_attempts' => 'sometimes|integer|min:1|max:20',
            'selection' => 'sometimes|in:latest,best,average', 'late_policy' => 'sometimes|in:refuse,accept_until', 'late_until' => 'sometimes|nullable|date',
            'results_visibility' => $visibility, 'key_visibility' => $visibility, 'replacement_hours' => 'sometimes|integer|min:1|max:720',
            'tenant_id' => 'prohibited'];
    }

    public function store(Request $request)
    {
        // A group the writer does not teach is refused alike whether they may read it or not (lot 5 §7).
        $data = $this->sent($request->validate($this->rules(true)));
        $id = DB::table('school.activity')->insertGetId(['tenant_id' => $this->school->id(), ...$data], 'id');
        return response()->json($this->show($id), 201);
    }

    /** The working copy, its questions with their answers, its versions, and what would stop its publication. */
    public function show(string $activity)
    {
        $row = $this->find($activity);
        $questions = array_map(fn ($question) => [...(array) $question, 'choices' => json_decode($question->choices, true, 8, JSON_THROW_ON_ERROR)],
            DB::select('SELECT id, position, kind, prompt, points, choices, version FROM school.activity_question WHERE tenant_id = ? AND activity_id = ? ORDER BY position',
                [$this->school->id(), $row->id]));
        $versions = DB::select('SELECT id, version, title, opens_at, deadline_at, closes_at, published_at, published_by,
            (SELECT u.name FROM public.users u WHERE u.id = v.published_by) AS published_by_name FROM school.activity_version v
            WHERE v.tenant_id = ? AND v.activity_id = ? ORDER BY v.version DESC', [$this->school->id(), $row->id]);
        return ['data' => [...$this->present($row), 'questions' => $questions, 'versions' => $versions, 'problems' => $this->problems($row->id)]];
    }

    public function update(Request $request, string $activity)
    {
        $row = $this->find($activity, true);
        $data = $this->sent($request->validate($this->rules(false) + ['version' => 'required|integer|min:1']));
        $this->records->version($row, (int) $data['version']);
        DB::table('school.activity')->where('tenant_id', $this->school->id())->where('id', $row->id)->update($data);
        return $this->show($row->id);
    }

    /** A draft never published; a published activity is archived instead. */
    public function destroy(Request $request, string $activity)
    {
        $row = $this->find($activity, true);
        $this->records->version($row, (int) $request->validate(['version' => 'required|integer|min:1'])['version']);
        DB::table('school.activity')->where('tenant_id', $this->school->id())->where('id', $row->id)->delete();
        return response()->noContent();
    }

    public function archive(Request $request, string $activity)
    {
        $row = $this->find($activity, true);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.activity')->where('tenant_id', $this->school->id())->where('id', $row->id)->update(['archived_at' => now(), 'version' => $data['version']]);
        return $this->show($row->id);
    }

    /** A new version, all of it or nothing; the audience made at the first one. */
    public function publish(string $activity)
    {
        $row = $this->find($activity);
        DB::select('SELECT school.publish_activity(?)', [$row->id]);
        return response()->json($this->show($row->id), 201);
    }

    public function storeQuestion(Request $request, string $activity)
    {
        $row = $this->find($activity);
        $data = $request->validate($this->questionRules(true));
        $id = DB::table('school.activity_question')->insertGetId(['tenant_id' => $this->school->id(), 'activity_id' => $row->id,
            ...$this->sentQuestion($data)], 'id');
        return response()->json(['data' => $this->question($id)], 201);
    }

    public function updateQuestion(Request $request, string $question)
    {
        $row = $this->records->find('activity_question', $question, true, 'id, version');
        $data = $request->validate($this->questionRules(false) + ['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.activity_question')->where('tenant_id', $this->school->id())->where('id', $row->id)->update($this->sentQuestion($data));
        return ['data' => $this->question($row->id)];
    }

    public function destroyQuestion(Request $request, string $question)
    {
        $row = $this->records->find('activity_question', $question, true, 'id, version');
        $this->records->version($row, (int) $request->validate(['version' => 'required|integer|min:1'])['version']);
        DB::table('school.activity_question')->where('tenant_id', $this->school->id())->where('id', $row->id)->delete();
        return response()->noContent();
    }

    /** A new order of the questions, all of them, written at once. */
    public function orderQuestions(Request $request, string $activity)
    {
        $row = $this->find($activity, true);
        $data = $request->validate(['questions' => 'required|array|min:1|max:200', 'questions.*' => 'uuid|distinct']);
        $current = array_column(DB::select('SELECT id FROM school.activity_question WHERE tenant_id = ? AND activity_id = ? FOR UPDATE', [$this->school->id(), $row->id]), 'id');
        $given = $data['questions'];
        sort($current);
        sort($given);
        if ($current !== $given) throw Refusal::of('lms_order_invalid', 422);
        DB::update('UPDATE school.activity_question SET position = array_position(?::uuid[], id) WHERE tenant_id = ? AND activity_id = ?',
            ['{'.implode(',', $data['questions']).'}', $this->school->id(), $row->id]);
        return $this->show($row->id);
    }

    /** Who the activity is for, each with their own deadline if any. */
    public function audience(string $activity)
    {
        $row = $this->find($activity);
        return ['data' => DB::select('SELECT x.id, x.student_id, x.enrollment_id, x.added_later, x.deadline_at, x.extension_reason, x.version, x.created_at,
            s.matricule, s.last_name_ar, s.first_name_ar, s.last_name_lat, s.first_name_lat
            FROM school.activity_audience x LEFT JOIN school.student s ON s.tenant_id = x.tenant_id AND s.id = x.student_id
            WHERE x.tenant_id = ? AND x.activity_id = ? ORDER BY s.last_name_lat, s.first_name_lat, x.id', [$this->school->id(), $row->id])];
    }

    /** A pupil of the group with access, added after the first publication, with a deadline of their own if given. */
    public function addToAudience(Request $request, string $activity)
    {
        $row = $this->find($activity);
        $data = $request->validate(['student_id' => 'required|uuid', 'deadline_at' => 'nullable|date', 'reason' => 'nullable|string|min:3|max:500']);
        DB::select('SELECT school.add_to_audience(?, ?, ?::timestamptz, ?)', [$row->id, $data['student_id'], $this->moment($data['deadline_at'] ?? null), $data['reason'] ?? null]);
        return response()->json($this->audience($row->id), 201);
    }

    /** An individual extension: a later deadline for one pupil, with its reason. */
    public function extend(Request $request, string $entry)
    {
        $row = $this->records->find('activity_audience', $entry, true, 'id, activity_id, version');
        $data = $request->validate(['deadline_at' => 'required|date', 'reason' => 'required|string|min:3|max:500', 'version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.activity_audience')->where('tenant_id', $this->school->id())->where('id', $row->id)
            ->update(['deadline_at' => $this->moment($data['deadline_at']), 'extension_reason' => $data['reason'], 'version' => $data['version']]);
        return $this->audience($row->activity_id);
    }

    private function questionRules(bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';
        return ['kind' => [$required, Rule::in(['single', 'multiple', 'open'])], 'prompt' => $required.'|string|min:1|max:4000',
            'points' => [$required, 'regex:/^\d{1,5}(\.\d{1,2})?$/'], 'choices' => 'sometimes|array|max:10', 'choices.*.id' => 'required|string|max:20',
            'choices.*.text' => 'required|string|max:500', 'choices.*.correct' => 'required|boolean'];
    }

    private function sentQuestion(array $data): array
    {
        if (array_key_exists('choices', $data)) {
            $data['choices'] = json_encode(array_map(fn (array $choice) => ['id' => (string) $choice['id'], 'text' => (string) $choice['text'],
                'correct' => (bool) $choice['correct']], array_values($data['choices'])), JSON_THROW_ON_ERROR);
        }
        unset($data['version']);
        return $data;
    }

    private function question(string $id): array
    {
        $row = $this->records->find('activity_question', $id, columns: 'id, activity_id, position, kind, prompt, points, choices, version');
        return [...(array) $row, 'choices' => json_decode($row->choices, true, 8, JSON_THROW_ON_ERROR)];
    }

    /** @return list<array{problem: string, question_id: ?string, message: string}> */
    private function problems(string $activity): array
    {
        return array_map(fn ($line) => [...(array) $line, 'message' => __('errors.'.$line->problem)],
            DB::select('SELECT problem, question_id FROM school.activity_problems(?)', [$activity]));
    }

    /** As the table takes them: moments in Algiers' time, the instructions' document as JSON. */
    private function sent(array $data): array
    {
        foreach (['opens_at', 'deadline_at', 'late_until'] as $column) if (array_key_exists($column, $data)) $data[$column] = $this->moment($data[$column]);
        if (array_key_exists('instructions', $data)) $data['instructions'] = json_encode(LessonDocument::clean($data['instructions']), JSON_THROW_ON_ERROR);
        return $data;
    }

    private function moment(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value, 'Africa/Algiers')->toIso8601String();
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
        return [...(array) $row, 'instructions' => json_decode($row->instructions, true, 64, JSON_THROW_ON_ERROR),
            'subject_labels' => $row->subject_labels === null ? null : json_decode($row->subject_labels, true, 8, JSON_THROW_ON_ERROR)];
    }
}
