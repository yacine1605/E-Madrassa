<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-LMS-05: a pupil's progression in their lessons, three facts kept apart. Opened and declared
 * complete are the pupil's own; mastery is verified only by the last published result of an activity
 * on the lesson reaching its pass mark, never by reading nor declaring. The teacher of a group's
 * subject reads the group's; every rule is the database's (SQL 088).
 */
final class ProgressController
{
    public function __construct(private CurrentSchool $school) {}

    /** The pupil opened the lesson: the first time kept, the last once a day. */
    public function opened(string $lesson)
    {
        abort_unless(Str::isUuid($lesson), 404);
        DB::select('SELECT school.open_lesson(?)', [$lesson]);
        return response()->noContent();
    }

    /** The pupil declares the lesson complete, or no longer. */
    public function complete(Request $request, string $lesson)
    {
        abort_unless(Str::isUuid($lesson), 404);
        $request->validate(['done' => 'required|boolean']);
        DB::select('SELECT school.declare_lesson(?, ?)', [$lesson, $request->boolean('done')]);
        return ['data' => collect($this->rows($this->courseOf($lesson)))->firstWhere('lesson_id', $lesson)];
    }

    /** The caller's own progression in a course, lesson by lesson. */
    public function mine(string $course)
    {
        abort_unless(Str::isUuid($course), 404);
        return ['data' => $this->rows($course)];
    }

    /** A group's progression in a subject: each pupil with access, each published lesson of its course. */
    public function group(Request $request, string $group)
    {
        abort_unless(Str::isUuid($group), 404);
        $data = $request->validate(['subject_id' => 'required|uuid']);
        $rows = collect(DB::select('SELECT * FROM school.course_progress(?, ?)', [$group, $data['subject_id']]));
        $lessons = DB::select('SELECT * FROM school.activity_lessons(?, ?)', [$group, $data['subject_id']]);
        $pupils = $rows->pluck('student_id')->unique()->values()->all();
        $names = collect($pupils === [] ? [] : DB::select('SELECT id, matricule, last_name_ar, first_name_ar, last_name_lat, first_name_lat FROM school.student
            WHERE tenant_id = ? AND id = ANY (?::uuid[])', [$this->school->id(), '{'.implode(',', $pupils).'}']))->keyBy('id');
        return ['data' => ['lessons' => $lessons, 'pupils' => $rows->groupBy('student_id')->map(fn ($progress, $student) => [
            'student_id' => $student, 'student' => isset($names[$student]) ? (array) $names[$student] : null,
            'lessons' => $progress->map(fn ($row) => (array) $row)->keyBy('lesson_id')->all()])->values()->all()]];
    }

    private function rows(?string $course): array
    {
        return $course === null ? [] : array_map(fn ($row) => (array) $row, DB::select('SELECT * FROM school.my_progress(?)', [$course]));
    }

    private function courseOf(string $lesson): ?string
    {
        return DB::selectOne('SELECT school.my_lesson_course(?) AS c', [$lesson])->c;
    }
}
