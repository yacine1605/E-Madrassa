<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\CurrentSchool;
use App\Support\PupilRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StudentController
{
    use HandlesLabels;

    private const SELECT = 'SELECT id, matricule, last_name_ar, first_name_ar, last_name_lat, first_name_lat,
        birth_date, birth_place, gender, phone, email, address, status, status_reason, origin_school, custom,
        version, archived_at, created_at, updated_at FROM school.student';

    public function __construct(private CurrentSchool $school, private PupilRecords $records) {}

    public function index(Request $request) { return $this->records->listing($request, self::SELECT, 'student'); }

    public function show(string $student) { return ['data' => $this->records->present($this->find($student), 'student')]; }

    private function rules(bool $create): array
    {
        return $this->records->rules() + ['birth_date' => ($create ? 'required' : 'sometimes').'|date_format:Y-m-d|before_or_equal:today',
            'birth_place' => 'nullable|string|max:120', 'gender' => 'nullable|in:m,f', 'address' => 'nullable|string|max:300',
            'origin_school' => 'nullable|string|max:200', 'status' => 'prohibited', 'status_reason' => 'prohibited',
            'matricule' => $create ? ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,30}$/'] : 'prohibited'];
    }

    public function duplicates(Request $request)
    {
        $data = $request->validate($this->rules(true));
        $this->records->name($data);
        return ['data' => $this->records->duplicates($data)];
    }

    public function store(Request $request)
    {
        $this->records->createPermission('student.record.create');
        $data = $request->validate($this->rules(true) + ['duplicate_reason' => 'nullable|string|min:3|max:500']);
        unset($data['tenant_id'], $data['user_id'], $data['photo_file_id'], $data['status'], $data['status_reason']);
        $this->records->name($data);
        // Serialize the warning with the write: simultaneous admissions also see the first dossier.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 31))', [$this->school->id()]);
        $matches = $this->records->duplicates($data);
        if ($matches && empty($data['duplicate_reason'])) throw Refusal::of('student_duplicate', extra: ['duplicates' => $matches]);
        if ($matches) DB::select("SELECT set_config('app.reason', ?, true)", [$data['duplicate_reason']]);
        unset($data['duplicate_reason']);
        $data['custom'] = $this->records->custom('student', $data['custom'] ?? []);
        $id = DB::table('school.student')->insertGetId(['tenant_id' => $this->school->id(), ...$data], 'id');
        return response()->json($this->show($id), 201);
    }

    public function update(Request $request, string $student)
    {
        $current = $this->find($student, true);
        $this->records->requireAction('student.record.update', 'student', $current->id);
        $data = $request->validate($this->rules(false) + ['version' => 'required|integer|min:1']);
        unset($data['tenant_id'], $data['user_id'], $data['photo_file_id'], $data['status'], $data['status_reason'], $data['matricule']);
        $this->records->name(array_replace((array) $current, $data));
        if (array_key_exists('custom', $data)) $data['custom'] = $this->records->custom('student', $data['custom'], $current);
        DB::table('school.student')->where('tenant_id', $this->school->id())->where('id', $current->id)->update($data);
        return $this->show($current->id);
    }

    public function status(Request $request, string $student)
    {
        $current = $this->find($student, true);
        $this->records->requireAction('student.record.update', 'student', $current->id);
        $data = $request->validate(['status' => 'required|in:active,former,blocked', 'reason' => 'required|string|min:3|max:500',
            'version' => 'required|integer|min:1', 'confirm_enrollments' => 'boolean']);
        if ((int) $data['version'] !== (int) $current->version) throw Refusal::of('stale_version');
        if ($data['status'] === 'blocked' && $current->status !== 'blocked') {
            $count = DB::selectOne('SELECT school.student_enrollment_count(?) AS n', [$current->id])->n;
            if ($count > 0 && !$request->boolean('confirm_enrollments'))
                throw Refusal::of('student_active_enrollments', extra: ['active_enrollments' => $count]);
        }
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        DB::table('school.student')->where('tenant_id', $this->school->id())->where('id', $current->id)->update([
            'status' => $data['status'], 'status_reason' => $data['status'] === 'blocked' ? $data['reason'] : null, 'version' => $data['version']]);
        return $this->show($current->id);
    }

    public function archive(Request $request, string $student)
    {
        $current = $this->find($student, true);
        $this->records->requireAction('student.record.archive', 'student', $current->id);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500', 'version' => 'required|integer|min:1']);
        if ($current->status !== 'former') throw Refusal::of('student_archive_former');
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        DB::table('school.student')->where('tenant_id', $this->school->id())->where('id', $current->id)
            ->update(['archived_at' => now(), 'version' => $data['version']]);
        return $this->show($current->id);
    }

    private function find(string $id, bool $lock = false): object
    {
        $row = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?'.($lock ? ' FOR UPDATE' : ''), [$this->school->id(), $this->uuid($id)]);
        abort_unless($row, 404);
        return $row;
    }
}
