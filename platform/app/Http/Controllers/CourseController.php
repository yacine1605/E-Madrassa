<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-LMS-01: a programme's subject taught as a course, in chapters and lessons kept by the pedagogy
 * manager, published in versions by lesson, chapter or course. A pupil and a teacher read the
 * published lessons of the courses their own records reach; the documents, links, states and
 * versions are the database's (SQL 078).
 */
final class CourseController
{
    // With its programme and subject, as the programmes and the catalogue name them; a pupil, who reads no programme, has its subject.
    private const COLUMNS = "k.id, k.program_id, k.subject_id, k.description, k.version, k.archived_at, k.created_at, k.updated_at,
        p.code AS program_code, p.labels AS program_labels, s.code AS subject_code, s.labels AS subject_labels";
    private const FROM = ' FROM school.course k
        LEFT JOIN school.program p ON p.tenant_id = k.tenant_id AND p.id = k.program_id
        LEFT JOIN school.subject s ON s.tenant_id = k.tenant_id AND s.id = k.subject_id';

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    /** The courses the reader reaches: all of them for the manager and the direction, a teacher's subjects', a pupil's programmes'. */
    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['program_id' => 'nullable|uuid']);
        $sql = 'SELECT '.self::COLUMNS.self::FROM.' WHERE k.tenant_id = ? AND k.archived_at IS NULL';
        $bindings = [$this->school->id()];
        if (isset($data['program_id'])) { $sql .= ' AND k.program_id = ?'; $bindings[] = $data['program_id']; }
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, $bindings, 'p.code, s.code, k.id', fn ($row) => $this->present($row));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['program_id' => 'required|uuid', 'subject_id' => 'required|uuid', 'description' => 'nullable|string|max:2000',
            'tenant_id' => 'prohibited']);
        $this->records->find('program', $data['program_id'], columns: 'id');
        $this->records->find('subject', $data['subject_id'], columns: 'id');
        $id = DB::table('school.course')->insertGetId(['tenant_id' => $this->school->id(), ...$data], 'id');
        return response()->json(['data' => $this->present($this->find($id))], 201);
    }

    public function update(Request $request, string $course)
    {
        $row = $this->find($course, true);
        $data = $request->validate(['description' => 'present|nullable|string|max:2000', 'version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.course')->where('tenant_id', $this->school->id())->where('id', $row->id)->update($data);
        return ['data' => $this->present($this->find($row->id))];
    }

    /** The manager's view: the chapters and the lessons' working copies, their state and their last version, archived ones apart. */
    public function outline(string $course)
    {
        $row = $this->find($course);
        $chapters = DB::select('SELECT id, title, position, version FROM school.course_chapter WHERE tenant_id = ? AND course_id = ? AND archived_at IS NULL
            ORDER BY position', [$this->school->id(), $row->id]);
        $lessons = collect(DB::select("SELECT l.id, l.chapter_id, l.title, l.position, l.status, l.published_version, l.duration_minutes, l.version, l.updated_at,
            (SELECT v.visible_from FROM school.lesson_version v WHERE v.tenant_id = l.tenant_id AND v.lesson_id = l.id ORDER BY v.version DESC LIMIT 1) AS visible_from
            FROM school.lesson l WHERE l.tenant_id = ? AND l.course_id = ? AND l.archived_at IS NULL ORDER BY l.position", [$this->school->id(), $row->id]))
            ->map(fn ($lesson) => [...(array) $lesson, 'scheduled' => $lesson->visible_from !== null && now()->lt($lesson->visible_from)])->groupBy('chapter_id');
        return ['data' => [...$this->present($row), 'chapters' => array_map(fn ($chapter) => [...(array) $chapter,
            'lessons' => $lessons->get($chapter->id, collect())->values()->all()], $chapters)]];
    }

    /** What a pupil reads: each lesson at its last version visible now; refused alike whether the course exists or not. */
    public function lessons(string $course)
    {
        abort_unless(Str::isUuid($course), 404);
        if (!DB::selectOne('SELECT school.course_readable(?::uuid) AS ok', [$course])->ok) throw Refusal::of('lms_access_denied', 403);
        $row = $this->find($course);
        // The teachers' resources of each lesson (SQL 079), with whether the reader may withdraw them.
        $resources = collect(DB::select('SELECT r.*, x.version FROM school.lesson_resources(?) r
            LEFT JOIN school.lesson_resource x ON x.tenant_id = ? AND x.id = r.id', [$row->id, $this->school->id()]))->groupBy('lesson_id');
        $lessons = array_map(fn ($lesson) => [...(array) $lesson, 'content' => json_decode($lesson->content, true, 64, JSON_THROW_ON_ERROR),
            'resources' => $resources->get($lesson->lesson_id, collect())->map(fn ($resource) => (array) $resource)->values()->all()],
            DB::select('SELECT * FROM school.published_lessons(?) ORDER BY chapter_position, lesson_position', [$row->id]));
        return ['data' => [...$this->present($row), 'lessons' => $lessons]];
    }

    public function storeChapter(Request $request, string $course)
    {
        $row = $this->find($course);
        $data = $request->validate(['title' => 'required|string|min:1|max:200']);
        $id = DB::table('school.course_chapter')->insertGetId(['tenant_id' => $this->school->id(), 'course_id' => $row->id, 'title' => $data['title']], 'id');
        return response()->json(['data' => $this->chapter($id)], 201);
    }

    public function updateChapter(Request $request, string $chapter)
    {
        $row = $this->records->find('course_chapter', $chapter, true);
        $data = $request->validate(['title' => 'required|string|min:1|max:200', 'version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.course_chapter')->where('tenant_id', $this->school->id())->where('id', $row->id)->update($data);
        return ['data' => $this->chapter($row->id)];
    }

    /** Archived with its lessons out of the pupils' view; never deleted. */
    public function archiveChapter(Request $request, string $chapter)
    {
        $row = $this->records->find('course_chapter', $chapter, true);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.course_chapter')->where('tenant_id', $this->school->id())->where('id', $row->id)->update(['archived_at' => now(), 'version' => $data['version']]);
        return ['data' => $this->chapter($row->id)];
    }

    /** A new order of the course's chapters in use, all of them, written at once. */
    public function orderChapters(Request $request, string $course)
    {
        $row = $this->find($course, true);
        $data = $request->validate(['chapters' => 'required|array|min:1|max:200', 'chapters.*' => 'uuid|distinct']);
        self::order('school.course_chapter', 'course_id', $row->id, $data['chapters'], $this->school->id());
        return $this->outline($row->id);
    }

    /** What a publication of a lesson, a chapter or the course would publish, and what stops it. */
    public function preview(Request $request)
    {
        $data = $request->validate(['scope' => 'required|in:lesson,chapter,course', 'id' => 'required|uuid']);
        return ['data' => array_map(fn ($line) => [...(array) $line, 'message' => $line->problem === null ? null : __('errors.'.$line->problem)],
            DB::select('SELECT * FROM school.publication_preview(?, ?)', [$data['scope'], $data['id']]))];
    }

    /** Publishes it all or nothing, now or from a later moment (Algiers' time on screen). */
    public function publish(Request $request)
    {
        $data = $request->validate(['scope' => 'required|in:lesson,chapter,course', 'id' => 'required|uuid', 'visible_from' => 'nullable|date']);
        $published = DB::select('SELECT lesson_id, version FROM school.publish_lessons(?, ?, ?::timestamptz)',
            [$data['scope'], $data['id'], isset($data['visible_from']) ? Carbon::parse($data['visible_from'], 'Africa/Algiers')->toIso8601String() : null]);
        return response()->json(['data' => $published], 201);
    }

    /**
     * Writes positions 1 to n in the order given, which must name every row in use of the parent;
     * the positions' uniqueness is checked at the transaction's end (SQL 078).
     *
     * @param list<string> $ids
     */
    public static function order(string $table, string $parent, string $parentId, array $ids, string $tenant): void
    {
        $current = array_column(DB::select("SELECT id FROM $table WHERE tenant_id = ? AND $parent = ? AND archived_at IS NULL FOR UPDATE", [$tenant, $parentId]), 'id');
        sort($current);
        $given = $ids;
        sort($given);
        if ($current !== $given) throw Refusal::of('lms_order_invalid', 422);
        DB::update("UPDATE $table SET position = array_position(?::uuid[], id) WHERE tenant_id = ? AND $parent = ? AND archived_at IS NULL",
            ['{'.implode(',', $ids).'}', $tenant, $parentId]);
    }

    private function chapter(string $id): array
    {
        return (array) $this->records->find('course_chapter', $id, columns: 'id, course_id, title, position, version, archived_at, created_at, updated_at');
    }

    private function find(string $id, bool $lock = false): object
    {
        abort_unless(Str::isUuid($id), 404);
        $row = DB::selectOne('SELECT '.self::COLUMNS.self::FROM.' WHERE k.tenant_id = ? AND k.id = ?'.($lock ? ' FOR UPDATE OF k' : ''), [$this->school->id(), $id]);
        abort_unless($row, 404);
        return $row;
    }

    private function present(object $row): array
    {
        $data = (array) $row;
        foreach (['program_labels', 'subject_labels'] as $column) $data[$column] = $data[$column] === null ? null : json_decode($data[$column], true, 8, JSON_THROW_ON_ERROR);
        return $data;
    }
}
