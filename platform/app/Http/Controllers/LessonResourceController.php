<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-LMS-01: a teacher's own resource, a link of the school's allow-list added to a visible lesson
 * of a course they teach, published at once; withdrawn by its author or the pedagogy manager, never
 * deleted (SQL 079). Read with the course's published lessons.
 */
final class LessonResourceController
{
    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    public function store(Request $request, string $lesson)
    {
        abort_unless(Str::isUuid($lesson), 404);
        $data = $request->validate(['title' => 'required|string|min:1|max:200', 'url' => 'required|string|max:2000']);
        $id = DB::table('school.lesson_resource')->insertGetId(['tenant_id' => $this->school->id(), 'lesson_id' => $lesson, ...$data], 'id');
        return response()->json(['data' => $this->find($id)], 201);
    }

    public function withdraw(Request $request, string $resource)
    {
        $row = $this->records->find('lesson_resource', $resource, true, 'id, version');
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.lesson_resource')->where('tenant_id', $this->school->id())->where('id', $row->id)->update(['archived_at' => now(), 'version' => $data['version']]);
        return ['data' => $this->find($row->id)];
    }

    private function find(string $id): array
    {
        return (array) $this->records->find('lesson_resource', $id, columns: 'id, lesson_id, course_id, title, url, version, archived_at, created_at');
    }
}
