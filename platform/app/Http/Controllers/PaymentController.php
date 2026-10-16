<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-FIN-02, FR-FIN-03: an enrolment's payments. Recorded once per key, even sent five times at
 * once (TST-10), never beyond what is left to pay unless entered as an overpayment; validated by
 * someone else than their author (BR-08), the school's self-validation marked; never changed once
 * validated (TST-11), but taken back by a linked reversal, itself validated. Amounts are strings
 * with two decimals (BR-04). The rules are SQL 049's.
 */
final class PaymentController
{
    private const PAYMENTS = "SELECT p.id, p.enrollment_id, p.kind, p.reverses_payment_id, p.reference, p.amount::text AS amount, p.paid_on::text AS paid_on,
        p.method_code, p.external_ref, p.overpayment, p.reason, p.status, p.delegated, p.validated_at, p.self_validated, p.cancel_reason, p.version,
        p.created_by, p.created_at, (SELECT u.name FROM public.users u WHERE u.id = p.created_by) AS author,
        (SELECT u.name FROM public.users u WHERE u.id = p.validated_by) AS validator,
        (SELECT r.id FROM school.payment r WHERE r.tenant_id = p.tenant_id AND r.reverses_payment_id = p.id AND r.status <> 'cancelled') AS reversal_id,
        (SELECT x.reference FROM school.payment x WHERE x.tenant_id = p.tenant_id AND x.id = p.reverses_payment_id) AS reverses_reference,
        (SELECT jsonb_build_object('program', e.terms_snapshot ->> 'code', 'matricule', s.matricule, 'last_name_ar', s.last_name_ar, 'first_name_ar', s.first_name_ar,
           'last_name_lat', s.last_name_lat, 'first_name_lat', s.first_name_lat)
         FROM school.enrollment e LEFT JOIN school.student s ON s.tenant_id = e.tenant_id AND s.id = e.student_id
         WHERE e.tenant_id = p.tenant_id AND e.id = p.enrollment_id) AS enrollment
        FROM school.payment p";
    // Positive, two decimals at most: « 0 » and « 0.00 » are refused with the format.
    private const AMOUNT = ['required', 'string', 'regex:/^(?!0+(\.0+)?$)(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/'];

    public function __construct(private CurrentSchool $school) {}

    /** The payments the reader may see: the validation queue (`pending_validation`), or another state, of one enrolment or all. */
    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['status' => 'nullable|in:pending_validation,validated,reversed,cancelled', 'enrollment' => 'nullable|uuid']);
        $request->merge(['paginate' => true]);
        $sql = self::PAYMENTS.' WHERE p.tenant_id = ?';
        $bindings = [$this->school->id()];
        if (isset($data['status'])) { $sql .= ' AND p.status = ?'; $bindings[] = $data['status']; }
        if (isset($data['enrollment'])) { $sql .= ' AND p.enrollment_id = ?'; $bindings[] = $data['enrollment']; }
        return Paging::select($request, $sql, $bindings, 'p.created_at DESC, p.id', fn ($row) => self::shape($row));
    }

    /** FR-FIN-02: a payment of the enrolment, waiting for its validation; the same key again answers the first one. */
    public function store(Request $request, string $enrollment)
    {
        abort_unless(Str::isUuid($enrollment), 404);
        $data = $request->validate(['amount' => self::AMOUNT, 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'method_code' => 'required|string|max:20',
            'external_ref' => 'nullable|string|max:100', 'overpayment' => 'sometimes|boolean', 'idempotency_key' => 'required|uuid']);
        abort_unless(DB::selectOne('SELECT 1 AS found FROM school.enrollment WHERE tenant_id = ? AND id = ?', [$this->school->id(), $enrollment]), 404);
        $id = DB::selectOne('SELECT school.record_payment(?, ?::numeric, ?::date, ?, ?, ?, ?) AS id', [$enrollment, $data['amount'], $data['paid_on'], $data['method_code'],
            $data['external_ref'] ?? null, (bool) ($data['overpayment'] ?? false), $data['idempotency_key']])->id;
        return response()->json(['data' => $this->payment($id)], 201);
    }

    /** FR-FIN-03: its author corrects a payment while it waits. */
    public function update(Request $request, string $payment)
    {
        $current = $this->payment($payment);
        $data = $request->validate(['version' => 'required|integer|min:1', 'amount' => self::AMOUNT, 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'method_code' => 'required|string|max:20', 'external_ref' => 'nullable|string|max:100', 'overpayment' => 'sometimes|boolean']);
        return $this->change($current, $data['version'], 'amount = ?::numeric, paid_on = ?::date, method_code = ?, external_ref = ?, overpayment = ?',
            [$data['amount'], $data['paid_on'], $data['method_code'], $data['external_ref'] ?? null, (bool) ($data['overpayment'] ?? false)]);
    }

    /** Its author withdraws a waiting payment, saying why; its reference is not given again. */
    public function cancel(Request $request, string $payment)
    {
        $current = $this->payment($payment);
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:500']);
        return $this->change($current, $data['version'], "status = 'cancelled', cancel_reason = ?", [$data['reason']]);
    }

    /** AC3: validated by someone else than its author, but for the school's self-validation, marked. */
    public function validatePayment(Request $request, string $payment)
    {
        $current = $this->payment($payment);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        return $this->change($current, $data['version'], "status = 'validated'", []);
    }

    /** AC2: the reversal of a validated payment, with its reason, waiting for its validation; the payment stays. */
    public function reverse(Request $request, string $payment)
    {
        $current = $this->payment($payment);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500', 'idempotency_key' => 'required|uuid']);
        $id = DB::selectOne('SELECT school.reverse_payment(?, ?, ?) AS id', [$current['id'], $data['reason'], $data['idempotency_key']])->id;
        return response()->json(['data' => $this->payment($id)], 201);
    }

    private function change(array $current, int $version, string $set, array $bindings): array
    {
        if ($version !== (int) $current['version']) throw Refusal::of('stale_version');
        $changed = DB::update("UPDATE school.payment SET $set, version = ? WHERE tenant_id = ? AND id = ?", [...$bindings, $version, $this->school->id(), $current['id']]);
        if (!$changed) throw Refusal::permissionDenied();
        return ['data' => $this->payment($current['id'])];
    }

    private function payment(string $id): array
    {
        abort_unless(Str::isUuid($id), 404);
        $row = DB::selectOne(self::PAYMENTS.' WHERE p.tenant_id = ? AND p.id = ?', [$this->school->id(), $id]);
        abort_unless($row, 404);
        return self::shape($row);
    }

    /** @return list<array> an enrolment's payments and reversals that the reader may see, newest first */
    public static function of(string $tenant, string $enrollment): array
    {
        return array_map(fn ($row) => self::shape($row), DB::select(self::PAYMENTS.' WHERE p.tenant_id = ? AND p.enrollment_id = ? ORDER BY p.created_at DESC, p.id', [$tenant, $enrollment]));
    }

    private static function shape(object $row): array
    {
        $data = (array) $row;
        $data['enrollment'] = $data['enrollment'] === null ? null : json_decode($data['enrollment'], true);
        return $data;
    }
}
