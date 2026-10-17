<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-REM-05: a member's own pays, once validated, when the school lets its members read theirs
 * (`payroll.self_view` setting) and they hold `payroll.self_view`: the hours or sessions each
 * counts, its detail, what is paid of it and its payouts; never anyone else's, whatever else the
 * reader may read. The database decides what is theirs (SQL 060).
 */
final class MyPayController
{
    private const PAYS = "SELECT r.id, r.period::text AS period, r.kind, r.variable_basis, r.days_paid, r.days_in_month, r.fixed_amount::text AS fixed_amount,
        r.variable_amount::text AS variable_amount, r.bonus_amount::text AS bonus_amount, r.adjustment_amount::text AS adjustment_amount,
        r.net_amount::text AS net_amount, y.paid::text AS paid, (r.net_amount - y.paid)::numeric(14, 2)::text AS left_to_pay,
        school.payroll_state(r.status, r.net_amount, y.paid) AS state, r.validated_at,
        (SELECT coalesce(sum(l.quantity), 0)::numeric(8, 2)::text FROM school.payroll_line l WHERE l.tenant_id = r.tenant_id AND l.run_id = r.id AND l.kind = 'unit') AS units
        FROM school.payroll_run r CROSS JOIN LATERAL (SELECT school.payroll_paid(r.tenant_id, r.id) AS paid) y
        WHERE r.tenant_id = ? AND r.status <> 'draft' AND school.is_own_staff(r.staff_id)";

    public function __construct(private CurrentSchool $school) {}

    /** Their validated pays, the latest month first. */
    public function index(Request $request)
    {
        $request->validate(Paging::RULES);
        $request->merge(['paginate' => true]);
        return Paging::select($request, self::PAYS, [$this->school->id()], 'r.period DESC, r.id', fn ($row) => (array) $row);
    }

    /** One of them, its detail line by line and its payouts (their reversals once validated). */
    public function show(string $run)
    {
        abort_unless(Str::isUuid($run), 404);
        $tenant = $this->school->id();
        $row = DB::selectOne(self::PAYS.' AND r.id = ?', [$tenant, $run]);
        abort_unless($row, 404);
        $data = (array) $row;
        $data['lines'] = array_map(fn ($line) => [...(array) $line, 'node_labels' => $line->node_labels ? json_decode($line->node_labels, true) : null],
            DB::select('SELECT l.id, l.line_no, l.kind, l.node_code, l.node_labels, l.worked_on::text AS worked_on, l.quantity::text AS quantity, l.included,
                l.unit_amount::text AS unit_amount, l.amount::text AS amount, l.label FROM school.payroll_line l
                WHERE l.tenant_id = ? AND l.run_id = ? ORDER BY l.line_no', [$tenant, $run]));
        $data['payouts'] = array_map(fn ($payout) => (array) $payout, DB::select("SELECT o.id, o.kind, o.amount::text AS amount, o.paid_on::text AS paid_on,
            o.method_code, o.status FROM school.payroll_payout o WHERE o.tenant_id = ? AND o.run_id = ? AND (o.kind = 'payout' OR o.status = 'validated')
            ORDER BY o.paid_on DESC, o.created_at DESC, o.id", [$tenant, $run]));
        return ['data' => $data];
    }
}
