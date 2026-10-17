<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-REM-04: the payouts of a validated pay, at once or in parts, never beyond its net, once per key
 * (BR-05). Each writes in its transaction the expense of the payroll's category `salaries` and its
 * payment (BR-06), so that the month's `salaries` expenses are its payouts (TST-13). A payout never
 * changes: it is taken back by its linked reversal, with its reason, validated or rejected by
 * someone else than whoever asked for it (BR-08), which cancels its expense. The rules are SQL 060's.
 */
final class PayrollPayoutController
{
    private const PAYOUTS = "SELECT o.id, o.run_id, o.kind, o.reverses_payout_id, o.amount::text AS amount, o.paid_on::text AS paid_on, o.method_code, o.external_ref,
        o.reason, o.status, o.decided_at, o.decision_reason, o.version, o.created_by, o.created_at,
        (SELECT u.name FROM public.users u WHERE u.id = o.created_by) AS author, (SELECT u.name FROM public.users u WHERE u.id = o.decided_by) AS decider
        FROM school.payroll_payout o";
    // Positive, two decimals at most.
    private const AMOUNT = ['required', 'string', 'regex:/^(?!0+(\.0+)?$)(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/'];

    public function __construct(private CurrentSchool $school) {}

    /** @return list<array> a pay's payouts and their reversals, the latest first */
    public static function of(string $tenant, string $run): array
    {
        return array_map(fn ($row) => (array) $row, DB::select(self::PAYOUTS.' WHERE o.tenant_id = ? AND o.run_id = ? ORDER BY o.created_at DESC, o.id', [$tenant, $run]));
    }

    /** AC1: a validated pay paid, never beyond what is left of its net; once per key; its `salaries` expense written with it. */
    public function pay(Request $request, string $run)
    {
        abort_unless(Str::isUuid($run), 404);
        abort_unless(DB::selectOne('SELECT 1 AS found FROM school.payroll_run WHERE tenant_id = ? AND id = ?', [$this->school->id(), $run]), 404);
        $data = $request->validate(['amount' => self::AMOUNT, 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'method_code' => 'required|string|regex:/^[a-z_]{1,20}$/', 'external_ref' => 'nullable|string|max:100', 'idempotency_key' => 'required|uuid']);
        $id = DB::selectOne('SELECT school.record_payroll_payout(?, ?::numeric, ?::date, ?, ?, ?) AS id', [$run, $data['amount'], $data['paid_on'],
            $data['method_code'], $data['external_ref'] ?? null, $data['idempotency_key']])->id;
        return response()->json(['data' => $this->payout($id)], 201);
    }

    /** Its linked reversal, its whole amount, dated today, with its reason; once per key. */
    public function reverse(Request $request, string $payout)
    {
        $current = $this->payout($payout);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500', 'idempotency_key' => 'required|uuid']);
        $id = DB::selectOne('SELECT school.reverse_payroll_payout(?, ?, ?) AS id', [$current['id'], $data['reason'], $data['idempotency_key']])->id;
        return response()->json(['data' => $this->payout($id)], 201);
    }

    /** BR-08: by someone else than whoever asked for it; the payout's expense cancelled with it. */
    public function validateReversal(Request $request, string $payout)
    {
        $current = $this->payout($payout);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        return $this->decide($current, $data['version'], 'validated', null);
    }

    public function reject(Request $request, string $payout)
    {
        $current = $this->payout($payout);
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:500']);
        return $this->decide($current, $data['version'], 'rejected', $data['reason']);
    }

    private function decide(array $current, int $version, string $status, ?string $reason): array
    {
        if ($version !== (int) $current['version']) throw Refusal::of('stale_version');
        $changed = DB::update('UPDATE school.payroll_payout SET status = ?, decision_reason = ?, version = ? WHERE tenant_id = ? AND id = ?',
            [$status, $reason, $version, $this->school->id(), $current['id']]);
        if (!$changed) throw Refusal::permissionDenied();
        return ['data' => $this->payout($current['id'])];
    }

    private function payout(string $id): array
    {
        abort_unless(Str::isUuid($id), 404);
        $row = DB::selectOne(self::PAYOUTS.' WHERE o.tenant_id = ? AND o.id = ?', [$this->school->id(), $id]);
        abort_unless($row, 404);
        return (array) $row;
    }
}
