<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-LMS-03: the pupil's quiz attempts. The server sets an attempt's end, `min(start + duration,
 * deadline)`, saves each answer as it comes and refuses those after the end, resumes the same attempt
 * after a cut with its time left, and closes it at its end if the pupil does not submit it. Starting
 * and submitting take an idempotency key (SQL 081). The teacher reads their quiz's attempts.
 */
final class QuizAttemptController
{
    public function __construct(private CurrentSchool $school) {}

    /** The caller's attempts of the quiz, those that ran out closed first. */
    public function index(string $activity)
    {
        abort_unless(Str::isUuid($activity), 404);
        if (!DB::selectOne('SELECT 1 FROM school.my_activities() WHERE activity_id = ?', [$activity])) throw Refusal::of('lms_access_denied', 403);
        return ['data' => DB::select('SELECT * FROM school.my_attempts(?)', [$activity])];
    }

    /** Starts the attempt, or resumes the one in progress; again with the same key, the same attempt. */
    public function start(Request $request, string $activity)
    {
        abort_unless(Str::isUuid($activity), 404);
        $data = $request->validate(['idempotency_key' => 'required|uuid']);
        $id = DB::selectOne('SELECT school.start_attempt(?, ?) AS id', [$activity, $data['idempotency_key']])->id;
        return response()->json($this->show($id), 201);
    }

    /** The attempt as the pupil takes it: its questions without their answers, the answers saved, the seconds left. */
    public function show(string $attempt)
    {
        abort_unless(Str::isUuid($attempt), 404);
        $row = DB::selectOne('SELECT * FROM school.my_attempt(?)', [$attempt]);
        if (!$row) throw Refusal::of('lms_access_denied', 403);
        return ['data' => [...(array) $row, 'questions' => $row->questions === null ? null : json_decode($row->questions, true, 16, JSON_THROW_ON_ERROR),
            'answers' => json_decode($row->answers, true, 16, JSON_THROW_ON_ERROR)]];
    }

    /** One answer, saved at the server's time while the attempt runs. */
    public function answer(Request $request, string $attempt, string $question)
    {
        abort_unless(Str::isUuid($attempt) && Str::isUuid($question), 404);
        $data = $request->validate(['choices' => 'sometimes|array|max:10', 'choices.*' => 'string|max:20', 'answer_text' => 'sometimes|nullable|string|max:20000']);
        $saved = DB::selectOne('SELECT school.save_answer(?, ?, ?::jsonb, ?) AS at', [$attempt, $question,
            json_encode(array_values($data['choices'] ?? []), JSON_THROW_ON_ERROR), $data['answer_text'] ?? null])->at;
        return ['data' => ['question_id' => $question, 'saved_at' => $saved]];
    }

    /** Submitted once; again with the same key, the attempt as it is. */
    public function submit(Request $request, string $attempt)
    {
        abort_unless(Str::isUuid($attempt), 404);
        $data = $request->validate(['idempotency_key' => 'required|uuid']);
        DB::select('SELECT school.submit_attempt(?, ?)', [$attempt, $data['idempotency_key']]);
        return $this->show($attempt);
    }

    /** The quiz's attempts for its teacher: each pupil's, their state and the points graded so far. */
    public function forActivity(string $activity)
    {
        abort_unless(Str::isUuid($activity), 404);
        abort_unless(DB::selectOne('SELECT 1 FROM school.activity WHERE tenant_id = ? AND id = ?', [$this->school->id(), $activity]), 404);
        return ['data' => DB::select('SELECT x.id, x.student_id, x.number, x.status, x.started_at, x.ends_at, x.submitted_at, x.closed_by, x.max_points,
            x.auto_points, x.pending_questions, s.matricule, s.last_name_ar, s.first_name_ar, s.last_name_lat, s.first_name_lat
            FROM school.quiz_attempt x LEFT JOIN school.student s ON s.tenant_id = x.tenant_id AND s.id = x.student_id
            WHERE x.tenant_id = ? AND x.activity_id = ? ORDER BY s.last_name_lat, s.first_name_lat, x.number', [$this->school->id(), $activity])];
    }
}
