<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProgramController
{
    use HandlesLabels;

    private const COLUMNS = 'id, year_id, node_id, code, labels, description, duration_value, duration_unit,
        base_price, payment_terms, price_version, status, copied_from, version, archived_at, created_at, updated_at';

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    public function index(Request $request)
    {
        return $this->records->listing($request, 'SELECT '.self::COLUMNS.' FROM school.program',
            ['year_id' => 'nullable|uuid', 'node_id' => 'nullable|uuid', 'status' => 'nullable|in:draft,active,inactive'],
            ['code', "labels->>'fr'", "labels->>'ar'", "labels->>'en'"], 'code, id', fn ($row) => $this->present($row));
    }

    public function show(string $program) { return ['data' => $this->present($this->find($program))]; }

    public function store(Request $request)
    {
        $this->records->global('program.program.manage');
        $data = $request->validate($this->rules() + ['year_id' => 'required|uuid', 'node_id' => 'required|uuid',
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{1,30}$/']]);
        $this->records->find('academic_year', $data['year_id']);
        $this->records->find('ref_node', $data['node_id']);
        $this->duration($data);
        $data = $this->encoded($data);
        $id = DB::table('school.program')->insertGetId(['tenant_id' => $this->school->id(), ...$data], 'id');
        return response()->json($this->show($id), 201);
    }

    public function update(Request $request, string $program)
    {
        $row = $this->find($program, true);
        $this->records->action('program.program.manage', 'program', $row->id);
        $data = $request->validate(($request->exists('labels') ? $this->labelRules() : []) + $this->rules(false) + ['version' => 'required|integer|min:1',
            'year_id' => 'prohibited', 'node_id' => 'prohibited', 'code' => 'prohibited']);
        unset($data['year_id'], $data['node_id'], $data['code']);
        $this->duration(array_replace((array) $row, $data));
        DB::table('school.program')->where('tenant_id', $this->school->id())->where('id', $row->id)->update($this->encoded($data));
        return $this->show($row->id);
    }

    /**
     * FR-PRG-02: a copy in a later year, a draft with the subjects and volumes in force at the source
     * year's end (SQL 044); the same copy asked again answers the first one.
     */
    public function copy(Request $request, string $program)
    {
        $row = $this->find($program);
        $this->records->global('program.program.manage');
        $data = $request->validate(['year_id' => 'required|uuid']);
        $this->records->find('academic_year', $data['year_id']);
        $id = DB::selectOne('SELECT school.copy_program(?, ?) AS id', [$row->id, $data['year_id']])->id;
        return response()->json($this->show($id), 201);
    }

    public function prices(Request $request, string $program)
    {
        $this->find($program);
        $request->validate(Paging::RULES);
        $request->merge(['paginate' => true]);
        return Paging::select($request, 'SELECT version, base_price, payment_terms, created_at, created_by FROM school.program_price WHERE tenant_id = ? AND program_id = ?',
            [$this->school->id(), $program], 'version DESC', fn ($row) => $this->decoded($row, ['payment_terms']));
    }

    private function rules(bool $create = true): array
    {
        $labels = $create ? $this->labelRules() : [];
        return $labels + ['description' => 'nullable|string|max:2000', 'duration_value' => 'nullable|integer|min:1|max:2147483647',
            'duration_unit' => 'nullable|in:hours,days,weeks,months,years',
            // Strings avoid a float round trip, and refuse rather than round extra decimal places.
            'base_price' => ['sometimes', 'string', 'regex:/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/'],
            'payment_terms' => 'sometimes|array|list|max:100', 'status' => 'sometimes|in:draft,active,inactive',
            'tenant_id' => 'prohibited', 'price_version' => 'prohibited', 'archived_at' => 'prohibited'];
    }

    private function duration(array $data): void
    {
        if (isset($data['duration_value']) !== isset($data['duration_unit']))
            throw ValidationException::withMessages(['duration_value' => __('errors.duration_pair_required')]);
    }

    private function encoded(array $data): array
    {
        unset($data['tenant_id'], $data['price_version'], $data['archived_at']);
        if (isset($data['labels'])) $data['labels'] = $this->labels($data['labels']);
        if (isset($data['payment_terms'])) {
            try {
                // Leave room for the enclosing enrolment snapshot in the JSON reader.
                $data['payment_terms'] = json_encode($data['payment_terms'], JSON_THROW_ON_ERROR, 12);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['payment_terms' => __('errors.payment_terms_invalid')]);
            }
        }
        return $data;
    }

    private function find(string $id, bool $lock = false): object { return $this->records->find('program', $id, $lock, self::COLUMNS); }
    private function present(object $row): array { return $this->decoded($row, ['labels', 'payment_terms']); }
}
