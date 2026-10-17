<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\AcademicRecords;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-REM-02, FR-REM-03: the pays of a month, one per member however often generated (AC1, TST-12),
 * from the version of their rule in force that month: its fixed part over the days paid (AC9), the
 * units done entered by hand in lot 3, each at the rate of its level (AC5), those a mix includes
 * the month's earliest (AC11), the recurring and one-off bonuses, the adjustments with their reason.
 * A unit without a rate blocks the validation (AC8, BR-16). Validated by someone else than whoever
 * computed it (BR-08), a pay is frozen with its detail (BR-03); a correction then is an adjustment
 * of a month to come linked to it. Amounts are strings with two decimals (BR-04). Read with
 * `payroll.amount.read`, `payroll.run.generate` or `payroll.run.validate`. The rules are SQL 059's.
 */
final class PayrollRunController
{
    private const COLUMNS = "r.id, r.staff_id, r.period::text AS period, r.status, r.pay_rule_id, r.kind, r.monthly_amount::text AS monthly_amount, r.variable_basis,
        r.included_quantity::text AS included_quantity, r.days_paid, r.days_in_month, r.fixed_amount::text AS fixed_amount, r.variable_amount::text AS variable_amount,
        r.bonus_amount::text AS bonus_amount, r.adjustment_amount::text AS adjustment_amount, r.net_amount::text AS net_amount, r.missing_rates,
        r.computed_at, r.computed_by, r.validated_at, r.validated_by, r.version, s.staff_number, s.staff_type, s.last_name_lat, s.first_name_lat,
        s.last_name_ar, s.first_name_ar, (SELECT u.name FROM public.users u WHERE u.id = r.computed_by) AS computer,
        (SELECT u.name FROM public.users u WHERE u.id = r.validated_by) AS validator
        FROM school.payroll_run r LEFT JOIN school.staff s ON s.tenant_id = r.tenant_id AND s.id = r.staff_id";
    // Formateurs and administratifs, told apart (FR-REM-02).
    public const GROUPS = ['teaching' => ['teacher_internal', 'teacher_external'], 'administrative' => ['administrative', 'other']];
    private const MONTH = ['required', 'date_format:Y-m-d', 'regex:/-01$/'];
    // Above or below zero, two decimals at most.
    private const SIGNED = ['required', 'string', 'regex:/^-?(?!0+(\.0+)?$)(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/'];

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    /** A month's pays, by member. */
    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['period' => self::MONTH, 'status' => 'nullable|in:draft,validated',
            'group' => 'nullable|in:'.implode(',', array_keys(self::GROUPS)), 'search' => 'nullable|string|max:100']);
        $request->merge(['paginate' => true]);
        $sql = 'SELECT '.self::COLUMNS.' WHERE r.tenant_id = ? AND r.period = ?::date';
        $bindings = [$this->school->id(), $data['period']];
        if (isset($data['status'])) { $sql .= ' AND r.status = ?'; $bindings[] = $data['status']; }
        if (isset($data['group'])) { $sql .= ' AND s.staff_type = ANY (?::text[])'; $bindings[] = '{'.implode(',', self::GROUPS[$data['group']]).'}'; }
        if (isset($data['search']) && trim($data['search']) !== '') {
            $sql .= ' AND '.Paging::search(['s.last_name_lat', 's.first_name_lat', 's.last_name_ar', 's.first_name_ar', 's.staff_number']);
            $bindings[] = $data['search'];
        }
        return Paging::select($request, $sql, $bindings, 's.last_name_lat, s.first_name_lat, r.id', fn ($row) => (array) $row);
    }

    /** AC1: the drafts of a month begun, for every member with a version in force then or for one; generated again, never twice. */
    public function generate(Request $request)
    {
        $data = $request->validate(['period' => self::MONTH, 'staff_id' => 'nullable|uuid']);
        if (isset($data['staff_id'])) $this->records->find('staff', $data['staff_id'], columns: 'id');
        $count = DB::selectOne('SELECT school.generate_payroll(?::date, ?::uuid) AS n', [$data['period'], $data['staff_id'] ?? null])->n;
        return ['data' => ['period' => $data['period'], 'generated' => (int) $count]];
    }

    /** A pay with its detail line by line (FR-REM-03), its units and its adjustments. */
    public function show(string $run)
    {
        return ['data' => $this->run($run, true)];
    }

    /** A draft's units entered by hand, all at once, then the draft recomputed. */
    public function units(Request $request, string $run)
    {
        $current = $this->run($run);
        $data = $request->validate(['version' => 'required|integer|min:1', 'units' => 'present|array|max:500', 'units.*.node_id' => 'nullable|uuid',
            'units.*.worked_on' => 'required|date_format:Y-m-d', 'units.*.quantity' => 'required|decimal:0,2|gt:0|max:999']);
        if ((int) $current['version'] !== (int) $data['version']) throw Refusal::of('stale_version');
        $units = array_values(array_map(fn (array $unit) => ['node_id' => $unit['node_id'] ?? null, 'worked_on' => $unit['worked_on'],
            'quantity' => (string) $unit['quantity']], $data['units']));
        DB::select('SELECT school.save_payroll_units(?, ?::jsonb)', [$current['id'], json_encode($units)]);
        return ['data' => $this->run($current['id'], true)];
    }

    /** FR-REM-03 AC1: a one-off bonus or an adjustment of a draft with its reason, once per key; one correcting a pay validated before links it. */
    public function adjust(Request $request, string $run)
    {
        $current = $this->run($run);
        $data = $request->validate(['kind' => 'required|in:bonus,adjustment', 'amount' => self::SIGNED, 'reason' => 'required|string|min:3|max:500',
            'corrects_run_id' => 'nullable|uuid', 'idempotency_key' => 'required|uuid']);
        DB::selectOne('SELECT school.adjust_payroll(?, ?, ?::numeric, ?, ?::uuid, ?) AS id', [$current['id'], $data['kind'], $data['amount'], $data['reason'],
            $data['corrects_run_id'] ?? null, $data['idempotency_key']]);
        return response()->json(['data' => $this->run($current['id'], true)], 201);
    }

    /** BR-08: by someone else than whoever computed it, no unit lacking a rate (AC8), on what holds now; then frozen. */
    public function validateRun(Request $request, string $run)
    {
        $current = $this->run($run);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        if ((int) $current['version'] !== (int) $data['version']) throw Refusal::of('stale_version');
        $changed = DB::update("UPDATE school.payroll_run SET status = 'validated', version = ? WHERE tenant_id = ? AND id = ?",
            [$data['version'], $this->school->id(), $current['id']]);
        if (!$changed) throw Refusal::permissionDenied();
        return ['data' => $this->run($current['id'], true)];
    }

    /** A member's validated pays before this one's month, which an adjustment of it may correct. */
    public function corrected(string $run)
    {
        $current = $this->run($run);
        return ['data' => array_map(fn ($row) => (array) $row, DB::select("SELECT r.id, r.period::text AS period, r.net_amount::text AS net_amount
            FROM school.payroll_run r WHERE r.tenant_id = ? AND r.staff_id = ? AND r.status <> 'draft' AND r.period < ?::date ORDER BY r.period DESC LIMIT 24",
            [$this->school->id(), $current['staff_id'], $current['period']]))];
    }

    private function run(string $id, bool $detail = false): array
    {
        abort_unless(Str::isUuid($id), 404);
        $row = DB::selectOne('SELECT '.self::COLUMNS.' WHERE r.tenant_id = ? AND r.id = ?', [$this->school->id(), $id]);
        abort_unless($row, 404);
        $data = (array) $row;
        if (!$detail) return $data;
        $tenant = $this->school->id();
        $data['lines'] = array_map(fn ($line) => [...(array) $line, 'node_labels' => $line->node_labels ? json_decode($line->node_labels, true) : null],
            DB::select('SELECT l.id, l.line_no, l.kind, l.node_id, l.node_code, l.node_labels, l.worked_on::text AS worked_on, l.quantity::text AS quantity, l.included,
                l.unit_amount::text AS unit_amount, l.amount::text AS amount, l.label, l.adjustment_id FROM school.payroll_line l
                WHERE l.tenant_id = ? AND l.run_id = ? ORDER BY l.line_no', [$tenant, $id]));
        $data['units'] = array_map(fn ($unit) => (array) $unit, DB::select('SELECT u.id, u.node_id, u.worked_on::text AS worked_on, u.quantity::text AS quantity
            FROM school.payroll_unit u WHERE u.tenant_id = ? AND u.run_id = ? ORDER BY u.worked_on, u.created_at, u.id', [$tenant, $id]));
        $data['adjustments'] = array_map(fn ($adjustment) => (array) $adjustment, DB::select('SELECT a.id, a.kind, a.amount::text AS amount, a.reason,
            a.corrects_run_id, c.period::text AS corrects_period, a.created_at, (SELECT u.name FROM public.users u WHERE u.id = a.created_by) AS author
            FROM school.payroll_adjustment a LEFT JOIN school.payroll_run c ON c.tenant_id = a.tenant_id AND c.id = a.corrects_run_id
            WHERE a.tenant_id = ? AND a.run_id = ? ORDER BY a.created_at, a.id', [$tenant, $id]));
        return $data;
    }
}
