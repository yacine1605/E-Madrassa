<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-TDB-01: the finance dashboard, month by month, each figure summed from its source operations
 * when read (SQL 069): billed, net receipts (payments validated − reversals − refunds, TST-16 AC1),
 * expenses committed and paid other than the pays, the pays committed and paid out (never counted
 * twice, AC2), the management result (net receipts − expenses paid − pays paid: neither a cash
 * position nor a certified result), the arrears; a programme's profitability with its method. Each
 * figure's source operations are listed with the reader's own rights.
 */
final class FinanceDashboardController
{
    /** The indicators summed by the database, and those derived from them. */
    private const SOURCES = ['billed', 'payments', 'reversals', 'refunds', 'expenses_committed', 'expenses_paid', 'salaries_committed', 'salaries_paid'];

    public function __construct(private CurrentSchool $school) {}

    /** Month by month from one month to another (13 at most). */
    public function index(Request $request)
    {
        return $this->figures(...$this->period($request));
    }

    /** The figures from one month to another, as the screen and its exports show them (FR-TDB-03). */
    public function figures(string $from, string $to): array
    {
        [$from, $to] = [Carbon::parse($from)->startOfMonth()->toDateString(), Carbon::parse($to)->startOfMonth()->toDateString()];
        $months = [];
        for ($m = Carbon::parse($from); $m <= Carbon::parse($to); $m = $m->addMonth()) {
            $months[$m->toDateString()] = ['id' => $m->toDateString(), 'month' => $m->toDateString()] + array_fill_keys(self::SOURCES, null);
        }
        $arrears = null;
        $present = [];
        foreach (DB::select('SELECT month::text AS month, indicator, amount::numeric(14, 2)::text AS amount, operations FROM school.finance_dashboard(?::date, ?::date)',
            [$from, $to]) as $row) {
            if ($row->indicator === 'arrears') { $arrears = ['amount' => $row->amount, 'enrollments' => $row->operations, 'as_of' => $row->month]; continue; }
            $months[$row->month][$row->indicator] = $row->amount;
            $months[$row->month][$row->indicator.'_count'] = $row->operations;
        }
        $modules = collect(DB::select('SELECT key FROM unnest(ARRAY[?, ?, ?]) AS key WHERE school.module_enabled(key)', ['student_finance', 'expenses', 'payroll']))
            ->pluck('key')->all();
        foreach ($months as &$month) {
            $receipts = in_array('student_finance', $modules, true) ? $this->sum($month, ['payments'], ['reversals', 'refunds']) : null;
            $month['net_receipts'] = $receipts;
            $month['net_result'] = $receipts === null ? null : $this->sum($month + ['net_receipts' => $receipts], ['net_receipts'], ['expenses_paid', 'salaries_paid']);
        }
        return ['data' => array_values($months), 'meta' => ['modules' => $modules, 'arrears' => $arrears, 'computed_at' => now()->toIso8601String()]];
    }

    /** A programme's profitability over the period: billed, received, its direct costs; shared costs and pays not shared out. */
    public function programs(Request $request)
    {
        [$from, $to] = $this->period($request);
        return ['data' => array_map(fn (object $row) => (array) $row, DB::select("SELECT x.program_id AS id, x.program_id, p.code, p.labels,
            x.billed::numeric(14, 2)::text AS billed, x.received::numeric(14, 2)::text AS received, x.direct_costs::numeric(14, 2)::text AS direct_costs,
            CASE WHEN x.direct_costs IS NULL THEN NULL ELSE (x.received - x.direct_costs)::numeric(14, 2)::text END AS margin
            FROM school.program_profitability(?::date, ?::date) x LEFT JOIN school.program p ON p.tenant_id = ? AND p.id = x.program_id
            ORDER BY p.code NULLS LAST", [$from, $to, $this->school->id()]))];
    }

    /** A figure's source operations in a month, as the reader may read them: none of what they may not. */
    public function sources(Request $request)
    {
        $data = $request->validate(['indicator' => 'required|in:'.implode(',', self::SOURCES), 'month' => 'required|date_format:Y-m-d']);
        $first = Carbon::parse($data['month'])->startOfMonth()->toDateString();
        $last = Carbon::parse($data['month'])->endOfMonth()->toDateString();
        $t = $this->school->id();
        [$sql, $bindings] = match ($data['indicator']) {
            'billed' => ["SELECT i.id, i.due_date::text AS day, i.amount::text AS amount, p.code AS reference, NULL AS label FROM school.installment i
                JOIN school.payment_schedule s ON s.tenant_id = i.tenant_id AND s.id = i.schedule_id
                JOIN school.enrollment e ON e.tenant_id = s.tenant_id AND e.id = s.enrollment_id AND e.status <> 'cancelled'
                LEFT JOIN school.program p ON p.tenant_id = e.tenant_id AND p.id = e.program_id
                WHERE i.tenant_id = ? AND i.due_date BETWEEN ? AND ?
                  AND s.version = (SELECT max(z.version) FROM school.payment_schedule z WHERE z.tenant_id = s.tenant_id AND z.enrollment_id = s.enrollment_id)", [$t, $first, $last]],
            'payments', 'reversals' => ["SELECT p.id, p.paid_on::text AS day, p.amount::text AS amount, p.reference, p.method_code AS label FROM school.payment p
                WHERE p.tenant_id = ? AND p.paid_on BETWEEN ? AND ? AND ".($data['indicator'] === 'payments'
                    ? "p.kind = 'payment' AND p.status IN ('validated', 'reversed') AND p.carried_from_refund_id IS NULL" : "p.kind = 'reversal' AND p.status = 'validated'"),
                [$t, $first, $last]],
            'refunds' => ["SELECT r.id, r.paid_on::text AS day, r.amount::text AS amount, r.reference, r.method_code AS label FROM school.refund r
                WHERE r.tenant_id = ? AND r.kind = 'refund' AND r.status = 'executed' AND r.paid_on BETWEEN ? AND ?", [$t, $first, $last]],
            'expenses_committed' => ["SELECT x.id, x.expense_date::text AS day, x.amount::text AS amount, NULL AS reference, x.beneficiary AS label FROM school.expense x
                WHERE x.tenant_id = ? AND x.status = 'validated' AND x.payroll_payout_id IS NULL AND x.expense_date BETWEEN ? AND ?
                UNION ALL SELECT k.id, k.corrected_on::text, k.amount::text, NULL, x.beneficiary FROM school.expense_correction k
                JOIN school.expense x ON x.tenant_id = k.tenant_id AND x.id = k.expense_id
                WHERE k.tenant_id = ? AND k.status = 'validated' AND x.payroll_payout_id IS NULL AND k.corrected_on BETWEEN ? AND ?", [$t, $first, $last, $t, $first, $last]],
            'expenses_paid' => ["SELECT y.id, y.paid_on::text AS day, (CASE y.kind WHEN 'payment' THEN y.amount ELSE -y.amount END)::text AS amount, NULL AS reference,
                x.beneficiary AS label FROM school.expense_payment y JOIN school.expense x ON x.tenant_id = y.tenant_id AND x.id = y.expense_id
                WHERE y.tenant_id = ? AND x.payroll_payout_id IS NULL AND y.paid_on BETWEEN ? AND ?
                  AND ((y.kind = 'payment' AND y.status IN ('validated', 'reversed')) OR (y.kind = 'reversal' AND y.status = 'validated'))", [$t, $first, $last]],
            'salaries_committed' => ["SELECT r.id, r.period::text AS day, r.net_amount::text AS amount, s.staff_number AS reference,
                trim(coalesce(s.last_name_lat, '') || ' ' || coalesce(s.first_name_lat, '')) AS label FROM school.payroll_run r
                LEFT JOIN school.staff s ON s.tenant_id = r.tenant_id AND s.id = r.staff_id
                WHERE r.tenant_id = ? AND r.status <> 'draft' AND r.period BETWEEN ? AND ?", [$t, $first, $last]],
            'salaries_paid' => ["SELECT o.id, o.paid_on::text AS day, (CASE o.kind WHEN 'payout' THEN o.amount ELSE -o.amount END)::text AS amount, NULL AS reference,
                NULL AS label FROM school.payroll_payout o WHERE o.tenant_id = ? AND o.paid_on BETWEEN ? AND ?
                  AND ((o.kind = 'payout' AND o.status IN ('validated', 'reversed')) OR (o.kind = 'reversal' AND o.status = 'validated'))", [$t, $first, $last]],
        };
        return ['data' => array_map(fn (object $row) => (array) $row, DB::select("SELECT * FROM ($sql) x ORDER BY day, id", $bindings))];
    }

    private function period(Request $request): array
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        return [Carbon::parse($data['from'])->startOfMonth()->toDateString(), Carbon::parse($data['to'])->startOfMonth()->toDateString()];
    }

    /** The sum of some figures less others, to the cent; none while none of them is known. */
    private function sum(array $month, array $plus, array $minus): ?string
    {
        $cents = 0;
        foreach ($plus as $key) $cents += (int) round(((float) ($month[$key] ?? 0)) * 100);
        foreach ($minus as $key) $cents -= (int) round(((float) ($month[$key] ?? 0)) * 100);
        return number_format($cents / 100, 2, '.', '');
    }
}
