<?php

namespace App\Http\Controllers;

use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-PRG-02: a programme's subjects or modules, each with its volume. A changed volume is a new
 * version, the previous ones kept (AC1); a line no session uses is removed, ended otherwise (AC3).
 * Hours are sent and read as decimals with two places, never rounded.
 */
final class ProgramSubjectController
{
    // With its subject, and the average length its hours and sessions imply: more than 10 % away
    // from the length of its sessions, a warning that blocks nothing.
    private const COLUMNS = "id, program_id, subject_id, total_hours, weekly_hours, session_count, session_duration_minutes, coefficient,
        valid_from, valid_to, volume_version, version, created_at, updated_at,
        round(total_hours * 60 / session_count, 1) AS implicit_minutes,
        coalesce(abs(total_hours * 60 / session_count - session_duration_minutes) > session_duration_minutes * 0.1, false) AS duration_gap,
        (SELECT jsonb_build_object('code', s.code, 'kind', s.kind, 'labels', s.labels, 'archived', s.archived_at IS NOT NULL)
         FROM school.subject s WHERE s.tenant_id = program_subject.tenant_id AND s.id = program_subject.subject_id) AS subject";
    // In the catalogue's order.
    private const ORDER = '(SELECT s.sort_order FROM school.subject s WHERE s.tenant_id = program_subject.tenant_id AND s.id = program_subject.subject_id),
        (SELECT s.code FROM school.subject s WHERE s.tenant_id = program_subject.tenant_id AND s.id = program_subject.subject_id), valid_from, id';

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    public function index(Request $request, string $program)
    {
        $this->records->find('program', $program);
        $request->validate(Paging::RULES + ['include_ended' => 'boolean']);
        $sql = 'SELECT '.self::COLUMNS.' FROM school.program_subject WHERE tenant_id = ? AND program_id = ?';
        // An ended line is history; one ending later is still taught.
        if (!$request->boolean('include_ended')) $sql .= ' AND (valid_to IS NULL OR valid_to >= current_date)';
        $request->merge(['paginate' => true]);
        return Paging::select($request, $sql, [$this->school->id(), $program], self::ORDER, fn ($row) => $this->present($row));
    }

    public function store(Request $request, string $program)
    {
        $row = $this->records->find('program', $program);
        $this->records->action('program.subject.manage', 'program', $row->id);
        $data = $request->validate(['subject_id' => 'required|uuid', ...$this->volumeRules(),
            'valid_from' => 'sometimes|nullable|date_format:Y-m-d', 'valid_to' => 'sometimes|nullable|date_format:Y-m-d',
            ...array_fill_keys(['tenant_id', 'program_id', 'volume_version'], 'prohibited')]);
        $this->records->find('subject', $data['subject_id']);
        unset($data['tenant_id'], $data['program_id'], $data['volume_version']);
        $id = DB::table('school.program_subject')->insertGetId(['tenant_id' => $this->school->id(), 'program_id' => $row->id, ...$data], 'id');
        return response()->json(['data' => $this->present($this->find($id))], 201);
    }

    /** A new volume: the next version. Omitted values stay, null ones are cleared (the length of a session goes back to the school's). */
    public function update(Request $request, string $line)
    {
        $row = $this->find($line, true);
        $this->records->action('program.subject.manage', 'program', $row->program_id);
        $data = $request->validate([...$this->volumeRules(), 'version' => 'required|integer|min:1',
            ...array_fill_keys(['tenant_id', 'program_id', 'subject_id', 'valid_from', 'valid_to', 'volume_version'], 'prohibited')]);
        unset($data['tenant_id'], $data['program_id'], $data['subject_id'], $data['valid_from'], $data['valid_to'], $data['volume_version']);
        DB::table('school.program_subject')->where('tenant_id', $this->school->id())->where('id', $row->id)->update($data);
        return ['data' => $this->present($this->find($row->id))];
    }

    /** AC3: taught until that day, its sessions and volumes kept; final. */
    public function end(Request $request, string $line)
    {
        $row = $this->find($line, true);
        $this->records->action('program.subject.manage', 'program', $row->program_id);
        $data = $request->validate(['valid_to' => 'required|date_format:Y-m-d', 'version' => 'required|integer|min:1']);
        DB::table('school.program_subject')->where('tenant_id', $this->school->id())->where('id', $row->id)->update($data);
        return ['data' => $this->present($this->find($row->id))];
    }

    /** A line nothing uses is deleted with its volumes (BR-02); the journal keeps its values. */
    public function destroy(Request $request, string $line)
    {
        $row = $this->find($line, true);
        $this->records->action('program.subject.manage', 'program', $row->program_id);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->records->version($row, (int) $data['version']);
        DB::table('school.program_subject')->where('tenant_id', $this->school->id())->where('id', $row->id)->delete();
        return response()->noContent();
    }

    public function volumes(Request $request, string $line)
    {
        $this->find($line);
        $request->validate(Paging::RULES);
        $request->merge(['paginate' => true]);
        return Paging::select($request, 'SELECT version, total_hours, weekly_hours, session_count, session_duration_minutes, coefficient, created_at, created_by
            FROM school.program_subject_volume WHERE tenant_id = ? AND program_subject_id = ?', [$this->school->id(), $line], 'version DESC');
    }

    private function volumeRules(): array
    {
        // Numbers or strings, refused rather than rounded beyond two decimal places.
        return ['total_hours' => 'sometimes|nullable|decimal:0,2|gt:0|max:9999.99', 'weekly_hours' => 'sometimes|nullable|decimal:0,2|gt:0|max:168',
            'session_count' => 'sometimes|nullable|integer|min:1|max:10000', 'session_duration_minutes' => 'sometimes|nullable|integer|min:15|max:1440',
            'coefficient' => 'sometimes|nullable|decimal:0,2|gt:0|max:999.99'];
    }

    private function find(string $id, bool $lock = false): object { return $this->records->find('program_subject', $id, $lock, self::COLUMNS); }

    private function present(object $row): array
    {
        $data = (array) $row;
        $data['subject'] = $data['subject'] === null ? null : json_decode($data['subject'], true, 8, JSON_THROW_ON_ERROR);
        return $data;
    }
}
