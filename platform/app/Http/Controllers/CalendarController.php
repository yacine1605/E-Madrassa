<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesLabels;
use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * School years and their periods (FR-REF-05). The database keeps one current year, periods
 * inside their year without overlap, and a closed year final.
 */
final class CalendarController
{
    use HandlesLabels;

    private const YEAR = 'SELECT id, code, start_date, end_date, status, closed_at, version FROM school.academic_year';
    private const PERIOD = 'SELECT id, year_id, code, labels, start_date, end_date, sort_order, version, archived_at FROM school.period';

    public function __construct(private CurrentSchool $school) {}

    /** Years, most recent first, each with its periods in use. */
    public function index()
    {
        $periods = collect(DB::select(self::PERIOD.' WHERE tenant_id = ? AND archived_at IS NULL ORDER BY start_date', [$this->school->id()]))
            ->map(fn ($row) => $this->decoded($row))->groupBy('year_id');
        return ['data' => array_map(fn ($year) => [...(array) $year, 'periods' => $periods->get($year->id, collect())->values()],
            DB::select(self::YEAR.' WHERE tenant_id = ? ORDER BY start_date DESC', [$this->school->id()]))];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,20}$/'],
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after:start_date',
            'status' => ['required', Rule::in(['planned', 'current'])],
        ]);
        $id = DB::selectOne('INSERT INTO school.academic_year (tenant_id, code, start_date, end_date, status) VALUES (?, ?, ?, ?, ?) RETURNING id',
            [$this->school->id(), $data['code'], $data['start_date'], $data['end_date'], $data['status']])->id;
        return response()->json(['data' => $this->year($id)], 201);
    }

    public function update(Request $request, string $year)
    {
        $data = $request->validate(['start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after:start_date',
            'version' => 'required|integer|min:1']);
        $row = DB::selectOne('UPDATE school.academic_year SET start_date = ?, end_date = ?, version = ? WHERE tenant_id = ? AND id = ? RETURNING id',
            [$data['start_date'], $data['end_date'], $data['version'], $this->school->id(), $this->uuid($year)]);
        abort_unless($row, 404);
        return ['data' => $this->year($row->id)];
    }

    /** planned → current → closed. Closing is final; the reason goes to the audit journal. */
    public function status(Request $request, string $year)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['current', 'closed'])], 'reason' => 'required_if:status,closed|nullable|string|min:3|max:1000']);
        if (isset($data['reason'])) DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        $row = DB::selectOne('UPDATE school.academic_year SET status = ? WHERE tenant_id = ? AND id = ? RETURNING id',
            [$data['status'], $this->school->id(), $this->uuid($year)]);
        abort_unless($row, 404);
        return ['data' => $this->year($row->id)];
    }

    public function storePeriod(Request $request, string $year)
    {
        $data = $request->validate($this->labelRules() + $this->periodDates() + [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_]{1,20}$/'],
            'sort_order' => 'nullable|integer|min:0|max:1000',
        ]);
        abort_unless($this->yearExists($year), 404);
        $id = DB::selectOne('INSERT INTO school.period (tenant_id, year_id, code, labels, start_date, end_date, sort_order) VALUES (?, ?, ?, ?::jsonb, ?, ?, ?) RETURNING id',
            [$this->school->id(), $year, $data['code'], $this->labels($data['labels']), $data['start_date'], $data['end_date'], $data['sort_order'] ?? 0])->id;
        return response()->json(['data' => $this->period($id)], 201);
    }

    public function updatePeriod(Request $request, string $period)
    {
        $data = $request->validate($this->labelRules() + $this->periodDates() + ['sort_order' => 'required|integer|min:0|max:1000', 'version' => 'required|integer|min:1']);
        $row = DB::selectOne('UPDATE school.period SET labels = ?::jsonb, start_date = ?, end_date = ?, sort_order = ?, version = ?
            WHERE tenant_id = ? AND id = ? RETURNING id',
            [$this->labels($data['labels']), $data['start_date'], $data['end_date'], $data['sort_order'], $data['version'], $this->school->id(), $this->uuid($period)]);
        abort_unless($row, 404);
        return ['data' => $this->period($row->id)];
    }

    public function archivePeriod(Request $request, string $period)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        $row = DB::selectOne('UPDATE school.period SET archived_at = now() WHERE tenant_id = ? AND id = ? AND archived_at IS NULL RETURNING id',
            [$this->school->id(), $this->uuid($period)]);
        abort_unless($row, 404);
        return ['data' => $this->period($row->id)];
    }

    private function periodDates(): array
    {
        return ['start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date'];
    }

    private function yearExists(string $year): bool
    {
        return (bool) DB::selectOne('SELECT 1 AS found FROM school.academic_year WHERE tenant_id = ? AND id = ?', [$this->school->id(), $this->uuid($year)]);
    }

    private function year(string $id): array
    {
        return (array) DB::selectOne(self::YEAR.' WHERE tenant_id = ? AND id = ?', [$this->school->id(), $id]);
    }

    private function period(string $id): array
    {
        return $this->decoded(DB::selectOne(self::PERIOD.' WHERE tenant_id = ? AND id = ?', [$this->school->id(), $id]));
    }
}
