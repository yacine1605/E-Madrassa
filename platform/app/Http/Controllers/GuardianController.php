<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\CurrentSchool;
use App\Support\PupilRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class GuardianController
{
    use HandlesLabels;

    private const SELECT = 'SELECT id, last_name_ar, first_name_ar, last_name_lat, first_name_lat,
        phone, email, profession, custom, site_id, anonymised_at, version, archived_at, created_at, updated_at FROM school.guardian';
    // With the guardian's name and phone, as the reader may see them (FR-ELV-02).
    private const LINKS = "SELECT id, student_id, guardian_id, relationship, is_primary_contact, is_payer,
        emergency_contact, version, archived_at,
        (SELECT jsonb_build_object('last_name_ar', g.last_name_ar, 'first_name_ar', g.first_name_ar, 'last_name_lat', g.last_name_lat,
           'first_name_lat', g.first_name_lat, 'phone', g.phone, 'email', g.email, 'archived_at', g.archived_at)
         FROM school.guardian g WHERE g.tenant_id = student_guardian.tenant_id AND g.id = student_guardian.guardian_id) AS guardian
        FROM school.student_guardian";

    public function __construct(private CurrentSchool $school, private PupilRecords $records) {}

    public function index(Request $request) { return $this->records->listing($request, self::SELECT, 'guardian'); }

    public function show(string $guardian) { return ['data' => $this->records->present($this->find($guardian), 'guardian')]; }

    /** FR-ELV-02: the guardians already recorded who are probably this one, as the reader may see them. */
    public function duplicates(Request $request)
    {
        $data = $request->validate($this->records->rules() + ['profession' => 'nullable|string|max:120']);
        $this->records->name($data);
        return ['data' => $this->suggestions($data)];
    }

    public function store(Request $request)
    {
        $this->records->createPermission('guardian.record.create');
        $data = $request->validate($this->records->rules() + ['profession' => 'nullable|string|max:120', 'duplicate_reason' => 'nullable|string|min:3|max:500']);
        unset($data['tenant_id'], $data['user_id'], $data['photo_file_id']);
        $this->records->name($data);
        $this->records->site($data);
        // As for pupils: the warning and the write together, against two secretaries entering the same father.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 32))', [$this->school->id()]);
        $matches = $this->suggestions($data);
        if ($matches && empty($data['duplicate_reason'])) throw Refusal::of('guardian_duplicate', extra: ['duplicates' => $matches]);
        if ($matches) DB::select("SELECT set_config('app.reason', ?, true)", [$data['duplicate_reason']]);
        unset($data['duplicate_reason']);
        $data['custom'] = $this->records->custom('guardian', $data['custom'] ?? []);
        $id = DB::table('school.guardian')->insertGetId(['tenant_id' => $this->school->id(), ...$data], 'id');
        return response()->json($this->show($id), 201);
    }

    public function update(Request $request, string $guardian)
    {
        $current = $this->find($guardian, true);
        $this->records->requireAction('guardian.record.update', 'guardian', $current->id);
        $data = $request->validate($this->records->rules() + ['profession' => 'nullable|string|max:120', 'version' => 'required|integer|min:1']);
        unset($data['tenant_id'], $data['user_id'], $data['photo_file_id']);
        $this->records->name(array_replace((array) $current, $data));
        $this->records->site($data);
        if (array_key_exists('custom', $data)) $data['custom'] = $this->records->custom('guardian', $data['custom'], $current);
        DB::table('school.guardian')->where('tenant_id', $this->school->id())->where('id', $current->id)->update($data);
        return $this->show($current->id);
    }

    public function links(Request $request, string $student)
    {
        $this->student($student);
        $request->validate(['include_archived' => 'boolean']);
        return ['data' => array_map(fn ($row) => $this->decoded($row, ['guardian']), DB::select(self::LINKS.' WHERE tenant_id = ? AND student_id = ?'.
            ($request->boolean('include_archived') ? '' : ' AND archived_at IS NULL').' ORDER BY created_at, id', [$this->school->id(), $student]))];
    }

    public function link(Request $request, string $student)
    {
        $this->records->createPermission('guardian.record.create');
        $this->student($student);
        $data = $request->validate($this->linkRules() + ['guardian_id' => 'required|uuid']);
        $guardian = $this->find($data['guardian_id']);
        abort_if($guardian->archived_at, 404);
        $id = DB::table('school.student_guardian')->insertGetId(['tenant_id' => $this->school->id(), 'student_id' => $student, ...$data], 'id');
        return response()->json(['data' => $this->decoded($this->findLink($student, $id), ['guardian'])], 201);
    }

    public function updateLink(Request $request, string $student, string $link)
    {
        $this->records->createPermission('guardian.record.update');
        $this->student($student);
        $current = $this->findLink($student, $link);
        $data = $request->validate($this->linkRules() + ['version' => 'required|integer|min:1', 'guardian_id' => 'prohibited']);
        unset($data['guardian_id']);
        DB::table('school.student_guardian')->where('tenant_id', $this->school->id())->where('id', $current->id)->update($data);
        return ['data' => $this->decoded($this->findLink($student, $link), ['guardian'])];
    }

    public function unlink(Request $request, string $student, string $link)
    {
        $this->records->createPermission('guardian.record.update');
        $this->student($student);
        $current = $this->findLink($student, $link);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500', 'version' => 'required|integer|min:1']);
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        DB::table('school.student_guardian')->where('tenant_id', $this->school->id())->where('id', $current->id)
            ->update(['archived_at' => now(), 'version' => $data['version']]);
        return ['data' => $this->decoded($this->findLink($student, $link), ['guardian'])];
    }

    /** The probable duplicates as the API shows them: the file's reference is the import's alone. */
    private function suggestions(array $data): array
    {
        return array_map(fn (array $row) => array_diff_key($row, ['external_ref' => 1]), $this->records->guardianDuplicates($data));
    }

    private function linkRules(): array
    {
        return ['relationship' => 'required|in:father,mother,guardian,other', 'is_primary_contact' => 'boolean',
            'is_payer' => 'boolean', 'emergency_contact' => 'boolean'];
    }

    private function find(string $id, bool $lock = false): object
    {
        $row = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?'.($lock ? ' FOR UPDATE' : ''), [$this->school->id(), $this->uuid($id)]);
        abort_unless($row, 404);
        return $row;
    }

    private function student(string $student): void
    {
        abort_unless(DB::selectOne('SELECT id FROM school.student WHERE tenant_id = ? AND id = ?', [$this->school->id(), $this->uuid($student)]), 404);
    }

    private function findLink(string $student, string $link): object
    {
        $row = DB::selectOne(self::LINKS.' WHERE tenant_id = ? AND student_id = ? AND id = ?', [$this->school->id(), $student, $this->uuid($link)]);
        abort_unless($row, 404);
        return $row;
    }
}
