<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use App\Support\Files;
use App\Support\PupilRecords;
use App\Support\StudentPhotos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A pupil's photo (FR-ELV-01): optional, seen by whoever may read the pupil's record, added,
 * replaced or removed by whoever may update it. The previous image goes for good (BR-17); the
 * journal says the photo changed, never which image. Never a file of its own: no public link.
 */
final class StudentPhotoController
{
    public function __construct(private CurrentSchool $school, private PupilRecords $records, private StudentPhotos $photos, private Files $files) {}

    /** The photo, or with `size=thumb` its thumbnail of 128 pixels; nothing while the school has photos turned off. */
    public function show(Request $request, string $student)
    {
        $size = $request->validate(['size' => 'nullable|in:full,thumb'])['size'] ?? 'full';
        $pupil = $this->find($student);
        $id = $size === 'thumb' ? $pupil->photo_thumb_file_id : $pupil->photo_file_id;
        abort_unless($id && $this->photos->enabled(), 404);
        $file = DB::selectOne('SELECT storage_key, media_type, size_bytes FROM school.file_object
            WHERE tenant_id = ? AND id = ? AND archived_at IS NULL AND purged_at IS NULL', [$this->school->id(), $id]) ?? abort(404);
        return $this->files->inline($file->storage_key, $file->media_type, (int) $file->size_bytes);
    }

    /** `photo` (JPEG, PNG or WebP, 2 MB at most), `version`, and `square` to keep its centred square. */
    public function store(Request $request, string $student)
    {
        $pupil = $this->find($student, true);
        $this->records->requireAction('student.record.update', 'student', $pupil->id);
        $data = $request->validate(['photo' => 'required|file', 'square' => 'boolean', 'version' => 'required|integer|min:1']);
        if ((int) $data['version'] !== (int) $pupil->version) throw Refusal::of('stale_version');
        if (!$this->photos->enabled()) throw Refusal::of('student_photo_disabled');
        [$photo, $thumb] = $this->photos->store($request->file('photo'), $pupil->id, $request->boolean('square'));
        return $this->change($pupil, $photo->id, $thumb->id, (int) $data['version']);
    }

    public function destroy(Request $request, string $student)
    {
        $pupil = $this->find($student, true);
        $this->records->requireAction('student.record.update', 'student', $pupil->id);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        abort_unless($pupil->photo_file_id, 404);
        return $this->change($pupil, null, null, (int) $data['version']);
    }

    private function change(object $pupil, ?string $photo, ?string $thumb, int $version): array
    {
        DB::table('school.student')->where('tenant_id', $this->school->id())->where('id', $pupil->id)
            ->update(['photo_file_id' => $photo, 'photo_thumb_file_id' => $thumb, 'version' => $version]);
        $this->photos->discardAfterCommit([$pupil->photo_file_id, $pupil->photo_thumb_file_id]);
        return app(StudentController::class)->show($pupil->id);
    }

    private function find(string $id, bool $lock = false): object
    {
        abort_unless(Str::isUuid($id), 404);
        return DB::selectOne('SELECT id, version, photo_file_id, photo_thumb_file_id FROM school.student WHERE tenant_id = ? AND id = ?'
            .($lock ? ' FOR UPDATE' : ''), [$this->school->id(), $id]) ?? abort(404);
    }
}
