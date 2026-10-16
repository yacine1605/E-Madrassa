<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-FIN-01: what an enrolment owes and when. Its adjustments (discounts, debt waivers,
 * penalties), each with its reason, approved by the direction beyond the school's threshold, by
 * someone else than their author (BR-08); its schedule, versioned, whose sum is what is owed
 * (AC1); what is paid (SQL 049) and refunded (SQL 050). Amounts are strings with two decimals, never floats (BR-04). The rules are SQL 048's.
 */
final class FinanceController
{
    private const ADJUSTMENTS = "SELECT a.id, a.enrollment_id, a.kind, a.amount::text AS amount, a.reason, a.status, a.decided_by, a.decided_at, a.decision_reason, a.version,
        a.created_by, a.created_at, (SELECT u.name FROM public.users u WHERE u.id = a.created_by) AS author,
        (SELECT u.name FROM public.users u WHERE u.id = a.decided_by) AS decider,
        (SELECT jsonb_build_object('program', e.terms_snapshot ->> 'code', 'matricule', s.matricule, 'last_name_ar', s.last_name_ar, 'first_name_ar', s.first_name_ar,
           'last_name_lat', s.last_name_lat, 'first_name_lat', s.first_name_lat)
         FROM school.enrollment e LEFT JOIN school.student s ON s.tenant_id = e.tenant_id AND s.id = e.student_id
         WHERE e.tenant_id = a.tenant_id AND e.id = a.enrollment_id) AS enrollment
        FROM school.adjustment a";
    private const AMOUNT = ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/'];

    public function __construct(private CurrentSchool $school) {}

    /** What the enrolment owes, its adjustments and its schedule in force. */
    public function show(string $enrollment)
    {
        $row = $this->enrollment($enrollment);
        // FR-FIN-02 step 1: what is owed, paid and waiting, for whoever reads the finance or records its payments.
        $balance = DB::selectOne('SELECT due::text AS due, paid::text AS paid, pending::text AS pending, balance::text AS balance, state, refunded::text AS refunded,
            refunds_pending::text AS refunds_pending, credit::text AS credit FROM school.payment_balance(?)', [$row->id]);
        $due = $balance->due;
        $sums = DB::selectOne("SELECT coalesce(sum(amount) FILTER (WHERE kind <> 'penalty' AND status = 'approved'), 0)::text AS reductions,
            coalesce(sum(amount) FILTER (WHERE kind = 'penalty' AND status = 'approved'), 0)::text AS penalties,
            coalesce(sum(amount) FILTER (WHERE status = 'pending'), 0)::text AS pending FROM school.adjustment WHERE tenant_id = ? AND enrollment_id = ?",
            [$this->school->id(), $row->id]);
        $schedule = DB::selectOne('SELECT s.id, s.version, s.total::text AS total, s.reason, s.created_at, (SELECT u.name FROM public.users u WHERE u.id = s.created_by) AS author
            FROM school.payment_schedule s WHERE s.tenant_id = ? AND s.enrollment_id = ? ORDER BY s.version DESC LIMIT 1', [$this->school->id(), $row->id]);
        return ['data' => [
            'enrollment_id' => $row->id, 'status' => $row->status, 'agreed_price' => $row->agreed_price, 'reductions' => $sums->reductions,
            'penalties' => $sums->penalties, 'pending' => $sums->pending, 'due' => $due,
            // FR-INS-02: the financial state, computed from the validated payments net of their reversals (TST-09).
            'paid' => $balance->paid, 'payments_pending' => $balance->pending, 'balance' => $balance->balance, 'state' => $balance->state,
            // FR-FIN-04: what was refunded or carried over, what waits to be, and the credit to decide on.
            'refunded' => $balance->refunded, 'refunds_pending' => $balance->refunds_pending, 'credit' => $balance->credit,
            // FR-INS-02, FR-FIN-06: overdue, an indicator computed from the schedule and today, for whoever reads the payments.
            ...$this->overdue($row->id),
            'schedule' => $schedule ? [...(array) $schedule, 'installments' => array_map(fn ($line) => (array) $line, DB::select(
                'SELECT seq, due_date, amount::text AS amount FROM school.installment WHERE tenant_id = ? AND schedule_id = ? ORDER BY seq', [$this->school->id(), $schedule->id]))] : null,
            // A schedule no longer matching what is owed (an adjustment since) waits for its next version.
            'schedule_matches' => $schedule ? $schedule->total === $due : null,
            'adjustments' => array_map(fn ($row) => $this->present($row), DB::select(self::ADJUSTMENTS.' WHERE a.tenant_id = ? AND a.enrollment_id = ? ORDER BY a.created_at DESC, a.id',
                [$this->school->id(), $row->id])),
            'payments' => PaymentController::of($this->school->id(), $row->id),
            'refunds' => RefundController::of($this->school->id(), $row->id),
            // The pupil's other open enrolments the reader may see, where a credit may be carried over.
            'siblings' => array_map(fn ($other) => (array) $other, DB::select("SELECT o.id, o.terms_snapshot ->> 'code' AS program, o.status FROM school.enrollment o
                WHERE o.tenant_id = ? AND o.student_id = (SELECT e.student_id FROM school.enrollment e WHERE e.tenant_id = ? AND e.id = ?) AND o.id <> ? AND o.status <> 'cancelled'
                ORDER BY o.created_at", [$this->school->id(), $this->school->id(), $row->id, $row->id])),
        ]];
    }

    /** The adjustments waiting for the direction (or another state), as the reader may see them. */
    public function adjustments(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['status' => 'nullable|in:pending,approved,rejected']);
        $request->merge(['paginate' => true]);
        $sql = self::ADJUSTMENTS.' WHERE a.tenant_id = ?';
        $bindings = [$this->school->id()];
        if (isset($data['status'])) { $sql .= ' AND a.status = ?'; $bindings[] = $data['status']; }
        return Paging::select($request, $sql, $bindings, 'a.created_at DESC, a.id', fn ($row) => $this->present($row));
    }

    /** AC2: with its reason; approved at once within the threshold, else pending. The same key again answers the first one (BR-05). */
    public function adjust(Request $request, string $enrollment)
    {
        $row = $this->enrollment($enrollment);
        // FR-FIN-04: a credit kept by the school (`credit_retained`), within the credit, is an adjustment too.
        $data = $request->validate(['kind' => 'required|in:discount,debt_waiver,penalty,credit_retained', 'amount' => self::AMOUNT, 'reason' => 'required|string|min:3|max:500',
            'idempotency_key' => 'required|uuid']);
        $existing = DB::selectOne(self::ADJUSTMENTS.' WHERE a.tenant_id = ? AND a.idempotency_key = ?', [$this->school->id(), $data['idempotency_key']]);
        if ($existing) {
            if ($existing->enrollment_id !== $row->id) throw Refusal::of('idempotency_key_reused');
            return ['data' => $this->present($existing)];
        }
        if ((float) $data['amount'] <= 0) throw Refusal::of('schedule_invalid');
        $id = DB::table('school.adjustment')->insertGetId(['tenant_id' => $this->school->id(), 'enrollment_id' => $row->id, ...$data], 'id');
        return response()->json(['data' => $this->adjustment($id)], 201);
    }

    public function approve(Request $request, string $adjustment) { return $this->decide($request, $adjustment, 'approved'); }
    public function reject(Request $request, string $adjustment) { return $this->decide($request, $adjustment, 'rejected'); }

    /** AC1: a new version of the schedule, its sum what is owed; the same key again answers the same version. */
    public function schedule(Request $request, string $enrollment)
    {
        $row = $this->enrollment($enrollment);
        $data = $request->validate(['installments' => 'required|array|min:1|max:120', 'installments.*.due_date' => 'required|date_format:Y-m-d',
            'installments.*.amount' => self::AMOUNT, 'reason' => 'nullable|string|min:3|max:500', 'idempotency_key' => 'required|uuid']);
        $lines = array_map(fn (array $line) => ['due_date' => $line['due_date'], 'amount' => $line['amount']], $data['installments']);
        DB::selectOne('SELECT school.set_schedule(?, ?::jsonb, ?, ?) AS id', [$row->id, json_encode($lines, JSON_THROW_ON_ERROR), $data['reason'] ?? null, $data['idempotency_key']]);
        return response()->json($this->show($row->id), 201);
    }

    /**
     * A schedule to start from: the programme's payment terms copied with the enrolment (BR-11),
     * each a share of what is owed (`percent`) falling due on its day (`due_date`), else equal shares
     * a month apart from today. Each line rounded half up to the centime, the last one taking the
     * remainder, so that the lines add up to what is owed (BR-04).
     */
    public function proposal(string $enrollment)
    {
        $row = $this->enrollment($enrollment);
        $due = DB::selectOne('SELECT school.enrollment_due(?)::text AS due', [$row->id])->due;
        $terms = array_values(array_filter(json_decode($row->payment_terms, true) ?: [], 'is_array'));
        $count = max(1, count($terms));
        $shares = count(array_filter($terms, fn ($term) => is_numeric($term['percent'] ?? null))) === $count && abs(array_sum(array_column($terms, 'percent')) - 100) < 0.001
            ? array_map(fn ($term) => (float) $term['percent'], $terms) : array_fill(0, $count, 100 / $count);
        $cents = (int) round((float) $due * 100);
        $lines = [];
        $left = $cents;
        foreach ($shares as $i => $share) {
            $amount = $i === $count - 1 ? $left : (int) round($cents * $share / 100, 0, PHP_ROUND_HALF_UP);
            $left -= $amount;
            $date = $terms[$i]['due_date'] ?? null;
            $lines[] = ['due_date' => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : now()->addMonthsNoOverflow($i)->toDateString(),
                'amount' => number_format($amount / 100, 2, '.', '')];
        }
        return ['data' => ['due' => $due, 'installments' => $cents > 0 ? $lines : []]];
    }

    private function decide(Request $request, string $adjustment, string $status): array
    {
        $current = $this->adjustment($adjustment);
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => ($status === 'rejected' ? 'required' : 'nullable').'|string|min:3|max:500']);
        if ((int) $data['version'] !== (int) $current['version']) throw Refusal::of('stale_version');
        $changed = DB::update('UPDATE school.adjustment SET status = ?, decision_reason = ?, version = ? WHERE tenant_id = ? AND id = ?',
            [$status, $data['reason'] ?? null, $data['version'], $this->school->id(), $current['id']]);
        if (!$changed) throw Refusal::permissionDenied();
        return ['data' => $this->adjustment($current['id'])];
    }

    /** @return array{overdue: ?string, overdue_since: ?string, next_due_date: ?string} */
    private function overdue(string $enrollment): array
    {
        if (!app(\App\Support\Permissions::class)->can('finance.payment.read')) return ['overdue' => null, 'overdue_since' => null, 'next_due_date' => null];
        $row = DB::selectOne('SELECT overdue::text AS overdue, overdue_since::text AS overdue_since, next_due_date::text AS next_due_date FROM school.arrears(current_date, ?)', [$enrollment]);
        return ['overdue' => $row?->overdue, 'overdue_since' => $row?->overdue_since, 'next_due_date' => $row?->next_due_date];
    }

    private function enrollment(string $id): object
    {
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404);
        $row = DB::selectOne("SELECT id, status, agreed_price::text AS agreed_price, terms_snapshot -> 'payment_terms' AS payment_terms FROM school.enrollment
            WHERE tenant_id = ? AND id = ?", [$this->school->id(), $id]);
        abort_unless($row, 404);
        return $row;
    }

    private function adjustment(string $id): array
    {
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404);
        $row = DB::selectOne(self::ADJUSTMENTS.' WHERE a.tenant_id = ? AND a.id = ?', [$this->school->id(), $id]);
        abort_unless($row, 404);
        return $this->present($row);
    }

    private function present(object $row): array
    {
        $data = (array) $row;
        $data['enrollment'] = $data['enrollment'] === null ? null : json_decode($data['enrollment'], true);
        return $data;
    }
}
