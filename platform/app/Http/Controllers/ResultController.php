<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-LMS-03, FR-LMS-04: the correction and the results. The teacher of the group's subject corrects
 * the open answers and marks the homework, decides a zero for a missing work or an excused absence
 * with their reason, publishes the results and the answer key when the activity leaves it to them;
 * the pedagogy manager re-corrects with a reason. The pupil reads their published results and their
 * attempts back, the key when it is due. Every rule is the database's (SQL 083).
 */
final class ResultController
{
    private const POINTS = ['required', 'regex:/^\d{1,5}(\.\d{1,2})?$/'];

    public function __construct(private CurrentSchool $school) {}

    /** Each pupil of the audience, their state, points, decisions and what was published. */
    public function index(string $activity)
    {
        $this->activity($activity);
        return ['data' => DB::select('SELECT r.*, s.matricule, s.last_name_ar, s.first_name_ar, s.last_name_lat, s.first_name_lat
            FROM school.activity_results(?) r LEFT JOIN school.student s ON s.tenant_id = ? AND s.id = r.student_id
            ORDER BY s.last_name_lat, s.first_name_lat, r.student_id', [$activity, $this->school->id()])];
    }

    /** A submitted attempt to correct: its version's questions with their answers, the pupil's answers and points. */
    public function attempt(string $attempt)
    {
        abort_unless(Str::isUuid($attempt), 404);
        $row = DB::selectOne('SELECT x.id, x.activity_id, x.student_id, x.number, x.status, x.auto_points, x.pending_questions, x.max_points, v.questions
            FROM school.quiz_attempt x JOIN school.activity_version v ON v.tenant_id = x.tenant_id AND v.id = x.version_id
            WHERE x.tenant_id = ? AND x.id = ?', [$this->school->id(), $attempt]);
        abort_unless($row, 404);
        $answers = collect(DB::select('SELECT question_id, choices, answer_text, points, comment, corrected_at FROM school.quiz_answer WHERE tenant_id = ? AND attempt_id = ?',
            [$this->school->id(), $row->id]))->keyBy('question_id');
        $questions = array_map(fn (array $question) => [...$question, 'answer' => ($answer = $answers->get($question['id']))
            ? [...(array) $answer, 'choices' => json_decode($answer->choices, true, 4, JSON_THROW_ON_ERROR)] : null],
            json_decode($row->questions, true, 16, JSON_THROW_ON_ERROR));
        return ['data' => [...(array) $row, 'questions' => $questions]];
    }

    public function correct(Request $request, string $attempt)
    {
        abort_unless(Str::isUuid($attempt), 404);
        $data = $request->validate(['question_id' => 'required|uuid', 'points' => self::POINTS, 'comment' => 'nullable|string|max:2000']);
        DB::select('SELECT school.correct_answer(?, ?, ?::numeric, ?)', [$attempt, $data['question_id'], $data['points'], $data['comment'] ?? null]);
        return $this->attempt($attempt);
    }

    /** A homework's mark, on the version to correct. */
    public function grade(Request $request, string $activity)
    {
        $this->activity($activity);
        $data = $request->validate(['student_id' => 'required|uuid', 'points' => self::POINTS, 'feedback' => 'nullable|string|max:4000']);
        DB::select('SELECT school.grade_homework(?, ?, ?::numeric, ?)', [$activity, $data['student_id'], $data['points'], $data['feedback'] ?? null]);
        return $this->index($activity);
    }

    /** A zero for a missing work, an excused absence, or neither any more; with its reason. */
    public function decide(Request $request, string $activity)
    {
        $this->activity($activity);
        $data = $request->validate(['student_id' => 'required|uuid', 'decision' => 'present|nullable|in:zero_missing,excused', 'reason' => 'nullable|string|max:500']);
        DB::select('SELECT school.decide_mark(?, ?, ?, ?)', [$activity, $data['student_id'], $data['decision'], $data['reason'] ?? null]);
        return $this->index($activity);
    }

    /** The results complete, of the pupils given or all; a result changed since its publication needs the reason. */
    public function publish(Request $request, string $activity)
    {
        $this->activity($activity);
        $data = $request->validate(['student_ids' => 'sometimes|array', 'student_ids.*' => 'uuid', 'reason' => 'nullable|string|max:500']);
        $ids = isset($data['student_ids']) ? '{'.implode(',', $data['student_ids']).'}' : null;
        $published = DB::selectOne('SELECT school.publish_marks(?, ?::uuid[], ?) AS n', [$activity, $ids, $data['reason'] ?? null])->n;
        return ['data' => ['published' => $published, 'results' => $this->index($activity)['data']]];
    }

    public function publishKey(string $activity)
    {
        $this->activity($activity);
        DB::select('SELECT school.publish_key(?)', [$activity]);
        return ['data' => ['key_published' => true]];
    }

    /** By the pedagogy manager alone, with its reason. */
    public function regrade(Request $request, string $activity)
    {
        $this->activity($activity);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500']);
        $count = DB::selectOne('SELECT school.regrade_activity(?, ?) AS n', [$activity, $data['reason']])->n;
        return ['data' => ['regraded' => $count, 'results' => $this->index($activity)['data']]];
    }

    /** The caller's published results. */
    public function mine()
    {
        return ['data' => DB::select('SELECT * FROM school.my_results() ORDER BY published_at DESC, activity_id')];
    }

    /** The caller's submitted attempt read back: the key when due, the points once published. */
    public function review(string $attempt)
    {
        abort_unless(Str::isUuid($attempt), 404);
        $row = DB::selectOne('SELECT * FROM school.my_review(?)', [$attempt]);
        if (!$row) throw Refusal::of('lms_access_denied', 403);
        return ['data' => [...(array) $row, 'questions' => json_decode($row->questions, true, 16, JSON_THROW_ON_ERROR)]];
    }

    private function activity(string $activity): void
    {
        abort_unless(Str::isUuid($activity), 404);
        abort_unless(DB::selectOne('SELECT 1 FROM school.activity WHERE tenant_id = ? AND id = ?', [$this->school->id(), $activity]), 404);
    }
}
