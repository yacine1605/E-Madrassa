<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-FIN-04: refunds and carry-overs, a circuit distinct from payments. The finance asks, with the
 * reason (`finance.refund.request`); the direction approves or rejects (`finance.refund.approve`),
 * never the one who asked (BR-08); once approved, the finance executes it: a refund with its means
 * and date, a carry-over by a payment on the pupil's other enrolment, validated as any other.
 * Never beyond what is paid. The rules are SQL 050's.
 */
final class RefundController
{
    private const REFUNDS = "SELECT r.id, r.enrollment_id, r.kind, r.target_enrollment_id, r.amount::text AS amount, r.reason, r.status, r.decided_at, r.decision_reason,
        r.reference, r.method_code, r.paid_on::text AS paid_on, r.external_ref, r.executed_at, r.version, r.created_by, r.created_at,
        (SELECT u.name FROM public.users u WHERE u.id = r.created_by) AS author, (SELECT u.name FROM public.users u WHERE u.id = r.decided_by) AS decider,
        (SELECT u.name FROM public.users u WHERE u.id = r.executed_by) AS executor,
        (SELECT t.terms_snapshot ->> 'code' FROM school.enrollment t WHERE t.tenant_id = r.tenant_id AND t.id = r.target_enrollment_id) AS target_program,
        (SELECT jsonb_build_object('program', e.terms_snapshot ->> 'code', 'matricule', s.matricule, 'last_name_ar', s.last_name_ar, 'first_name_ar', s.first_name_ar,
           'last_name_lat', s.last_name_lat, 'first_name_lat', s.first_name_lat)
         FROM school.enrollment e LEFT JOIN school.student s ON s.tenant_id = e.tenant_id AND s.id = e.student_id
         WHERE e.tenant_id = r.tenant_id AND e.id = r.enrollment_id) AS enrollment
        FROM school.refund r";
    // Positive, two decimals at most.
    private const AMOUNT = ['required', 'string', 'regex:/^(?!0+(\.0+)?$)(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/'];

    public function __construct(private CurrentSchool $school) {}

    /** The refunds the reader may see: those waiting for the direction (`requested`), or another state. */
    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['status' => 'nullable|in:requested,approved,rejected,executed']);
        $request->merge(['paginate' => true]);
        $sql = self::REFUNDS.' WHERE r.tenant_id = ?';
        $bindings = [$this->school->id()];
        if (isset($data['status'])) { $sql .= ' AND r.status = ?'; $bindings[] = $data['status']; }
        return Paging::select($request, $sql, $bindings, 'r.created_at DESC, r.id', fn ($row) => self::shape($row));
    }

    /** A refund or a carry-over asked for, with its reason; the same key again answers the first one (BR-05). */
    public function store(Request $request, string $enrollment)
    {
        abort_unless(Str::isUuid($enrollment), 404);
        $data = $request->validate(['kind' => 'required|in:refund,carry_over', 'target_enrollment_id' => 'required_if:kind,carry_over|nullable|uuid',
            'amount' => self::AMOUNT, 'reason' => 'required|string|min:3|max:500', 'idempotency_key' => 'required|uuid']);
        abort_unless(DB::selectOne('SELECT 1 AS found FROM school.enrollment WHERE tenant_id = ? AND id = ?', [$this->school->id(), $enrollment]), 404);
        $id = DB::selectOne('SELECT school.request_refund(?, ?, ?, ?::numeric, ?, ?) AS id', [$enrollment, $data['kind'],
            $data['kind'] === 'carry_over' ? $data['target_enrollment_id'] : null, $data['amount'], $data['reason'], $data['idempotency_key']])->id;
        return response()->json(['data' => $this->refund($id)], 201);
    }

    public function approve(Request $request, string $refund)
    {
        $current = $this->refund($refund);
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'nullable|string|min:3|max:500']);
        return $this->change($current, $data['version'], "status = 'approved', decision_reason = ?", [$data['reason'] ?? null]);
    }

    public function reject(Request $request, string $refund)
    {
        $current = $this->refund($refund);
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:500']);
        return $this->change($current, $data['version'], "status = 'rejected', decision_reason = ?", [$data['reason']]);
    }

    /** AC2: once approved only; a refund with its means and date, a carry-over by a payment on the other enrolment. */
    public function execute(Request $request, string $refund)
    {
        $current = $this->refund($refund);
        $data = $request->validate(['version' => 'required|integer|min:1',
            'method_code' => ($current['kind'] === 'refund' ? 'required' : 'nullable').'|string|max:20',
            'paid_on' => ($current['kind'] === 'refund' ? 'required' : 'nullable').'|date_format:Y-m-d|before_or_equal:today',
            'external_ref' => 'nullable|string|max:100']);
        return $this->change($current, $data['version'], "status = 'executed', method_code = ?, paid_on = ?::date, external_ref = ?",
            [$data['method_code'] ?? null, $data['paid_on'] ?? null, $data['external_ref'] ?? null]);
    }

    private function change(array $current, int $version, string $set, array $bindings): array
    {
        if ($version !== (int) $current['version']) throw Refusal::of('stale_version');
        $changed = DB::update("UPDATE school.refund SET $set, version = ? WHERE tenant_id = ? AND id = ?", [...$bindings, $version, $this->school->id(), $current['id']]);
        if (!$changed) throw Refusal::permissionDenied();
        return ['data' => $this->refund($current['id'])];
    }

    private function refund(string $id): array
    {
        abort_unless(Str::isUuid($id), 404);
        $row = DB::selectOne(self::REFUNDS.' WHERE r.tenant_id = ? AND r.id = ?', [$this->school->id(), $id]);
        abort_unless($row, 404);
        return self::shape($row);
    }

    /** @return list<array> an enrolment's refunds and carry-overs that the reader may see, newest first */
    public static function of(string $tenant, string $enrollment): array
    {
        return array_map(fn ($row) => self::shape($row), DB::select(self::REFUNDS.' WHERE r.tenant_id = ? AND r.enrollment_id = ? ORDER BY r.created_at DESC, r.id', [$tenant, $enrollment]));
    }

    private static function shape(object $row): array
    {
        $data = (array) $row;
        $data['enrollment'] = $data['enrollment'] === null ? null : json_decode($data['enrollment'], true);
        return $data;
    }
}
