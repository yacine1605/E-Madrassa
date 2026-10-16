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
 * FR-REM-01: how a member of staff is paid, in versions from the first day of a month: by the hour,
 * the session, a fixed monthly salary or a mix (the administrative staff by a fixed salary only,
 * AC3), unit rates by default and by node of the referential (the most specific winning, AC5),
 * recurring bonuses. A version is written with its rates and bonuses once per key (BR-05); a new
 * one ends the one before, never in a month gone by (AC1, AC4); begun, it keeps what it says; its
 * end only comes earlier; one not begun is deleted. Amounts are strings with two decimals (BR-04).
 * Read and written with `payroll.rule.manage`, read with `payroll.amount.read` (AC2). The rules are
 * SQL 058's.
 */
final class PayRuleController
{
    // With where it stands today, its rates (the default first) and bonuses.
    private const COLUMNS = "r.id, r.staff_id, r.kind, r.monthly_amount::text AS monthly_amount, r.variable_basis, r.included_quantity::text AS included_quantity,
        r.valid_from::text AS valid_from, r.valid_to::text AS valid_to, r.end_reason, r.version, r.created_at,
        CASE WHEN r.valid_from > current_date THEN 'upcoming' WHEN r.valid_to < current_date THEN 'ended' ELSE 'in_force' END AS state,
        coalesce((SELECT jsonb_agg(jsonb_build_object('id', x.id, 'node_id', x.node_id, 'node_code', n.code, 'node_labels', n.labels, 'unit_amount', x.unit_amount::text)
          ORDER BY x.node_id IS NOT NULL, n.code) FROM school.pay_rule_rate x LEFT JOIN school.ref_node n ON n.tenant_id = x.tenant_id AND n.id = x.node_id
          WHERE x.tenant_id = r.tenant_id AND x.pay_rule_id = r.id), '[]') AS rates,
        coalesce((SELECT jsonb_agg(jsonb_build_object('label', b.label, 'amount', b.amount::text) ORDER BY b.label) FROM school.pay_rule_bonus b
          WHERE b.tenant_id = r.tenant_id AND b.pay_rule_id = r.id), '[]') AS bonuses";
    public const KINDS = ['fixed_monthly', 'hourly', 'per_session', 'mixed'];
    // Positive, two decimals at most.
    private const AMOUNT = ['string', 'regex:/^(?!0+(\.0+)?$)(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/'];

    public function __construct(private CurrentSchool $school, private AcademicRecords $records) {}

    /** A member's versions, the latest first. */
    public function index(Request $request, string $staff)
    {
        $this->records->find('staff', $staff, columns: 'id');
        $request->validate(Paging::RULES);
        $request->merge(['paginate' => true]);
        return Paging::select($request, 'SELECT '.self::COLUMNS.' FROM school.pay_rule r WHERE r.tenant_id = ? AND r.staff_id = ?',
            [$this->school->id(), $staff], 'r.valid_from DESC, r.id', fn ($row) => self::shape($row));
    }

    /** A version from the first day of a month, with its rates and bonuses, all or nothing; the version in force then ends the day before. */
    public function store(Request $request, string $staff)
    {
        $row = $this->records->find('staff', $staff, columns: 'id');
        $data = $request->validate([
            'kind' => 'required|in:'.implode(',', self::KINDS),
            'monthly_amount' => ['nullable', ...self::AMOUNT], 'variable_basis' => 'nullable|in:hourly,per_session',
            'included_quantity' => 'nullable|decimal:0,2|min:0|max:999999',
            'valid_from' => 'required|date_format:Y-m-d',
            'rates' => 'array|max:200', 'rates.*.node_id' => 'nullable|uuid', 'rates.*.unit_amount' => ['required', ...self::AMOUNT],
            'bonuses' => 'array|max:20', 'bonuses.*.label' => 'required|string|min:2|max:100', 'bonuses.*.amount' => ['required', ...self::AMOUNT],
            'idempotency_key' => 'required|uuid',
        ]);
        $rates = array_values(array_map(fn (array $rate) => ['node_id' => $rate['node_id'] ?? null, 'unit_amount' => $rate['unit_amount']], $data['rates'] ?? []));
        $bonuses = array_values(array_map(fn (array $bonus) => ['label' => $bonus['label'], 'amount' => $bonus['amount']], $data['bonuses'] ?? []));
        $id = DB::selectOne('SELECT school.save_pay_rule(?, ?, ?::numeric, ?, ?::numeric, ?::date, ?::jsonb, ?::jsonb, ?) AS id', [$row->id, $data['kind'],
            $data['monthly_amount'] ?? null, $data['variable_basis'] ?? null, $data['included_quantity'] ?? null, $data['valid_from'],
            json_encode($rates), json_encode($bonuses), $data['idempotency_key']])->id;
        return response()->json(['data' => $this->rule($id)], 201);
    }

    /** Ended at the end of a month not gone by, earlier than planned, with its reason if told; never later. */
    public function end(Request $request, string $rule)
    {
        $current = $this->rule($rule);
        $data = $request->validate(['valid_to' => 'required|date_format:Y-m-d', 'end_reason' => 'nullable|string|min:3|max:500', 'version' => 'required|integer|min:1']);
        if ((int) $current['version'] !== (int) $data['version']) throw Refusal::of('stale_version');
        $changed = DB::update('UPDATE school.pay_rule SET valid_to = ?::date, end_reason = ?, version = ? WHERE tenant_id = ? AND id = ?',
            [$data['valid_to'], $data['end_reason'] ?? null, $data['version'], $this->school->id(), $current['id']]);
        if (!$changed) throw Refusal::permissionDenied();
        return ['data' => $this->rule($current['id'])];
    }

    /** A version not begun yet; the one it ended is in force again. The journal keeps its values. */
    public function destroy(Request $request, string $rule)
    {
        $current = $this->rule($rule);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        if ((int) $current['version'] !== (int) $data['version']) throw Refusal::of('stale_version');
        if (!DB::delete('DELETE FROM school.pay_rule WHERE tenant_id = ? AND id = ?', [$this->school->id(), $current['id']])) throw Refusal::permissionDenied();
        return response()->noContent();
    }

    /** AC5: the rate of a version for a node of the referential: its own, its nearest ancestor's, else the default; none when nothing resolves (BR-16). */
    public function rate(Request $request, string $rule)
    {
        $current = $this->rule($rule);
        $data = $request->validate(['node' => 'required|uuid']);
        $this->records->find('ref_node', $data['node'], columns: 'id');
        return ['data' => ['node_id' => $data['node'], 'unit_amount' => DB::selectOne('SELECT school.pay_rate(?, ?, ?)::text AS rate',
            [$this->school->id(), $current['id'], $data['node']])->rate]];
    }

    private function rule(string $id): array
    {
        abort_unless(Str::isUuid($id), 404);
        $row = DB::selectOne('SELECT '.self::COLUMNS.' FROM school.pay_rule r WHERE r.tenant_id = ? AND r.id = ?', [$this->school->id(), $id]);
        abort_unless($row, 404);
        return self::shape($row);
    }

    private static function shape(object $row): array
    {
        $data = (array) $row;
        $data['rates'] = array_map(fn (array $rate) => [...$rate, 'node_labels' => $rate['node_labels'] ?? null], json_decode($data['rates'], true));
        $data['bonuses'] = json_decode($data['bonuses'], true);
        return $data;
    }
}
