<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudyGroupController
{
    use HandlesLabels;

    // With the programme's code and the current members the reader may see (all of them, from a
    // grant on the group, its site or the whole school).
    private const COLUMNS = 'id, year_id, code, labels, group_type, program_id, site_id, capacity, valid_from, valid_to,
        status, version, archived_at, created_at, updated_at,
        (SELECT p.code FROM school.program p WHERE p.tenant_id = study_group.tenant_id AND p.id = study_group.program_id) AS program_code,
        (SELECT count(*) FROM school.group_membership m WHERE m.tenant_id = study_group.tenant_id AND m.group_id = study_group.id
           AND m.valid_to IS NULL) AS members';

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    public function index(Request $request)
    {
        return $this->records->listing($request, 'SELECT '.self::COLUMNS.' FROM school.study_group',
            ['year_id' => 'nullable|uuid', 'program_id' => 'nullable|uuid', 'site_id' => 'nullable|uuid',
                'group_type' => 'nullable|in:class,support,option,session', 'status' => 'nullable|in:draft,active,closed'],
            ['code', "labels->>'fr'", "labels->>'ar'", "labels->>'en'"], 'code, id', fn ($row) => $this->decoded($row));
    }

    public function show(string $group) { return ['data' => $this->decoded($this->find($group))]; }

    public function store(Request $request)
    {
        $this->records->global('group.group.manage');
        $data = $request->validate($this->labelRules() + $this->rules() + ['year_id' => 'required|uuid', 'site_id' => 'required|uuid',
            'program_id' => 'required_if:group_type,class,session|nullable|uuid', 'group_type' => 'required|in:class,support,option,session',
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{1,30}$/'], 'valid_from' => 'required|date_format:Y-m-d']);
        $this->records->find('academic_year', $data['year_id']);
        $this->records->find('site', $data['site_id']);
        if (isset($data['program_id'])) $this->records->find('program', $data['program_id']);
        $this->dates($data);
        $data['labels'] = $this->labels($data['labels']);
        unset($data['tenant_id'], $data['archived_at']);
        $id = DB::table('school.study_group')->insertGetId(['tenant_id' => $this->school->id(), ...$data], 'id');
        return response()->json($this->show($id), 201);
    }

    public function update(Request $request, string $group)
    {
        $row = $this->find($group, true);
        $this->records->action('group.group.manage', 'study_group', $row->id);
        $labels = $request->exists('labels') ? $this->labelRules() : [];
        $rules = $this->rules();
        $rules['capacity'] = 'sometimes|integer|min:1|max:2147483647';
        $data = $request->validate($labels + $rules + ['version' => 'required|integer|min:1',
            ...array_fill_keys(['year_id', 'site_id', 'program_id', 'group_type', 'code', 'valid_from'], 'prohibited')]);
        foreach (['year_id', 'site_id', 'program_id', 'group_type', 'code', 'valid_from', 'tenant_id', 'archived_at'] as $key) unset($data[$key]);
        $this->dates(array_replace((array) $row, $data));
        if (isset($data['labels'])) $data['labels'] = $this->labels($data['labels']);
        DB::table('school.study_group')->where('tenant_id', $this->school->id())->where('id', $row->id)->update($data);
        return $this->show($row->id);
    }

    private function rules(): array
    {
        return ['capacity' => 'required|integer|min:1|max:2147483647', 'valid_to' => 'nullable|date_format:Y-m-d',
            'status' => 'sometimes|in:draft,active,closed', 'tenant_id' => 'prohibited', 'archived_at' => 'prohibited'];
    }

    private function dates(array $data): void
    {
        if (isset($data['valid_to']) && $data['valid_to'] < $data['valid_from'])
            throw ValidationException::withMessages(['valid_to' => __('errors.group_dates_invalid')]);
    }

    private function find(string $id, bool $lock = false): object { return $this->records->find('study_group', $id, $lock, self::COLUMNS); }
}
