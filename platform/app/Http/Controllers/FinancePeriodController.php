<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-FIN-07, BR-15: the financial period's close. A past month closed for the whole school by
 * `finance.period.close`, once nothing dated in it waits for its validation, with its
 * reconciliation; afterwards nothing is recorded, corrected, validated or executed with a date in
 * it (AC1). Reopened by `finance.period.reopen`, with its reason, never by whoever closed it,
 * journalled (AC2). The rules are SQL 053's.
 */
final class FinancePeriodController
{
    private const PERIODS = "SELECT f.id, f.period_start::text AS period_start, f.period_end::text AS period_end, f.status, f.report, f.reopen_reason, f.reopened_at,
        f.created_at, f.created_by, f.version, (SELECT u.name FROM public.users u WHERE u.id = f.created_by) AS author,
        (SELECT u.name FROM public.users u WHERE u.id = f.reopened_by) AS reopener
        FROM school.finance_period f";

    public function __construct(private CurrentSchool $school) {}

    /** The closures, the latest month first; a month reopened then closed again shows both. */
    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['status' => 'nullable|in:closed,reopened']);
        $request->merge(['paginate' => true]);
        $sql = self::PERIODS.' WHERE f.tenant_id = ?';
        $bindings = [$this->school->id()];
        if (isset($data['status'])) { $sql .= ' AND f.status = ?'; $bindings[] = $data['status']; }
        return Paging::select($request, $sql, $bindings, 'f.period_start DESC, f.created_at DESC, f.id', fn ($row) => self::shape($row));
    }

    /** A month's reconciliation before its closure, with what still waits for its validation in it. */
    public function reconciliation(Request $request)
    {
        $data = $request->validate(['month' => 'required|date_format:Y-m']);
        return ['data' => json_decode(DB::selectOne('SELECT school.reconciliation(?::date) AS report', [$data['month'].'-01'])->report, true)];
    }

    /** A past month closed, its reconciliation written with it. */
    public function store(Request $request)
    {
        $data = $request->validate(['month' => 'required|date_format:Y-m']);
        $id = DB::selectOne('SELECT school.close_period(?::date) AS id', [$data['month'].'-01'])->id;
        return response()->json(['data' => $this->period($id)], 201);
    }

    /** AC2: reopened with its reason, by someone else than whoever closed it. */
    public function reopen(Request $request, string $period)
    {
        $current = $this->period($period);
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:500']);
        if ($data['version'] !== (int) $current['version']) throw Refusal::of('stale_version');
        $changed = DB::update("UPDATE school.finance_period SET status = 'reopened', reopen_reason = ?, version = ? WHERE tenant_id = ? AND id = ?",
            [$data['reason'], $data['version'], $this->school->id(), $current['id']]);
        if (!$changed) throw Refusal::permissionDenied();
        return ['data' => $this->period($current['id'])];
    }

    private function period(string $id): array
    {
        abort_unless(Str::isUuid($id), 404);
        $row = DB::selectOne(self::PERIODS.' WHERE f.tenant_id = ? AND f.id = ?', [$this->school->id(), $id]);
        abort_unless($row, 404);
        return self::shape($row);
    }

    private static function shape(object $row): array
    {
        $data = (array) $row;
        $data['report'] = json_decode($data['report'], true);
        return $data;
    }
}
