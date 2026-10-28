<?php

namespace App\Http\Controllers;

use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-LMS-01: a lesson's working copy, kept by the pedagogy manager in its chapter's order, and its
 * published versions. Its document is a ProseMirror/Tiptap tree checked by the database: no HTML, its
 * images and links on the school's allow-list (SQL 078). A change to a published lesson is a draft
 * until published again; what pupils read stays its last version.
 */
final class LessonController
{
    private const COLUMNS = 'id, chapter_id, course_id, title, objectives, prerequisites, duration_minutes, content, position, status,
        submitted_by, submitted_at, published_version, version, archived_at, created_at, updated_at';

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    private function rules(bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';
        return ['title' => $required.'|string|min:1|max:200', 'objectives' => 'sometimes|nullable|string|max:2000',
            'prerequisites' => 'sometimes|nullable|string|max:2000', 'duration_minutes' => 'sometimes|nullable|integer|min:1|max:600',
            'content' => 'sometimes|array'];
    }

    public function store(Request $request, string $chapter)
    {
        $row = $this->records->find('course_chapter', $chapter, columns: 'id');
        $data = $request->validate($this->rules(true));
        $id = DB::table('school.lesson')->insertGetId(['tenant_id' => $this->school->id(), 'chapter_id' => $row->id, ...$this->sent($data)], 'id');
        return response()->json($this->show($id), 201);
    }

    /** The working copy and every version published, newest first. */
    public function show(string $lesson)
    {
        $row = $this->find($lesson);
        $versions = DB::select('SELECT v.id, v.version, v.title, v.visible_from, v.published_at, v.published_by, v.approved_by,
            (SELECT u.name FROM public.users u WHERE u.id = v.published_by) AS published_by_name,
            (SELECT u.name FROM public.users u WHERE u.id = v.approved_by) AS approved_by_name
            FROM school.lesson_version v WHERE v.tenant_id = ? AND v.lesson_id = ? ORDER BY v.version DESC', [$this->school->id(), $row->id]);
        return ['data' => [...$this->present($row), 'versions' => $versions]];
    }

    /** A version as it was published, for its preview or an activity that names it. */
    public function version(string $lesson, string $version)
    {
        $row = $this->find($lesson);
        $found = DB::selectOne('SELECT * FROM school.lesson_version WHERE tenant_id = ? AND lesson_id = ? AND version = ?', [$this->school->id(), $row->id, (int) $version]);
        abort_unless($found, 404);
        return ['data' => [...(array) $found, 'content' => json_decode($found->content, true, 64, JSON_THROW_ON_ERROR)]];
    }

    public function update(Request $request, string $lesson)
    {
        $row = $this->find($lesson, true);
        $data = $request->validate($this->rules(false) + ['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.lesson')->where('tenant_id', $this->school->id())->where('id', $row->id)->update($this->sent($data));
        return $this->show($row->id);
    }

    /** Out of the pupils' view for good, its versions kept. */
    public function archive(Request $request, string $lesson)
    {
        $row = $this->find($lesson, true);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.lesson')->where('tenant_id', $this->school->id())->where('id', $row->id)->update(['archived_at' => now(), 'version' => $data['version']]);
        return $this->show($row->id);
    }

    /** For review, when the school asks for one: another manager publishes it. */
    public function submit(string $lesson)
    {
        $row = $this->find($lesson);
        DB::select('SELECT school.submit_lesson(?)', [$row->id]);
        return $this->show($row->id);
    }

    /** A new order of the chapter's lessons in use, all of them, written at once. */
    public function order(Request $request, string $chapter)
    {
        $row = $this->records->find('course_chapter', $chapter, true, 'id, course_id');
        $data = $request->validate(['lessons' => 'required|array|min:1|max:500', 'lessons.*' => 'uuid|distinct']);
        CourseController::order('school.lesson', 'chapter_id', $row->id, $data['lessons'], $this->school->id());
        return ['data' => DB::select('SELECT id, title, position, status, published_version, version FROM school.lesson
            WHERE tenant_id = ? AND chapter_id = ? AND archived_at IS NULL ORDER BY position', [$this->school->id(), $row->id])];
    }

    /** As the table takes it: the document as JSON. */
    private function sent(array $data): array
    {
        if (array_key_exists('content', $data)) $data['content'] = json_encode($data['content'], JSON_THROW_ON_ERROR);
        return $data;
    }

    private function find(string $id, bool $lock = false): object { return $this->records->find('lesson', $id, $lock, self::COLUMNS); }

    private function present(object $row): array
    {
        return [...(array) $row, 'content' => json_decode($row->content, true, 64, JSON_THROW_ON_ERROR)];
    }
}
