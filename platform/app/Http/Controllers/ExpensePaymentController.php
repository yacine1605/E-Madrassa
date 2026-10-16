<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-DEP-02: the payments of the school's expenses, the latest first; the reversals waiting for
 * their validation (`pending_validation`). A payment never changes (TST-11): it is taken back by
 * its linked reversal, with its reason, once per key, validated or rejected by someone else than
 * whoever asked for it (BR-08), the original kept. The rules are SQL 056's.
 */
final class ExpensePaymentController
{
    public function __construct(private CurrentSchool $school) {}

    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['status' => 'nullable|in:validated,reversed,pending_validation,rejected', 'kind' => 'nullable|in:payment,reversal',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'search' => 'nullable|string|max:100']);
        $request->merge(['paginate' => true]);
        $sql = ExpenseController::PAYMENTS.' WHERE y.tenant_id = ?';
        $bindings = [$this->school->id()];
        foreach (['status' => 'y.status = ?', 'kind' => 'y.kind = ?', 'from' => 'y.paid_on >= ?::date', 'to' => 'y.paid_on <= ?::date'] as $key => $clause) {
            if (isset($data[$key])) { $sql .= ' AND '.$clause; $bindings[] = $data[$key]; }
        }
        if (isset($data['search']) && trim($data['search']) !== '') {
            $sql .= ' AND '.Paging::search(['x.beneficiary', 'y.external_ref', 'y.reason']);
            $bindings[] = $data['search'];
        }
        return Paging::select($request, $sql, $bindings, 'y.created_at DESC, y.id', fn ($row) => (array) $row);
    }

    /** Its linked reversal, its whole amount, dated today, with its reason; once per key. */
    public function reverse(Request $request, string $payment)
    {
        $current = $this->payment($payment);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500', 'idempotency_key' => 'required|uuid']);
        $id = DB::selectOne('SELECT school.reverse_expense_payment(?, ?, ?) AS id', [$current['id'], $data['reason'], $data['idempotency_key']])->id;
        return response()->json(['data' => $this->payment($id)], 201);
    }

    /** BR-08: by someone else than whoever asked for it. */
    public function validateReversal(Request $request, string $payment)
    {
        $current = $this->payment($payment);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        return $this->decide($current, $data['version'], 'validated', null);
    }

    public function reject(Request $request, string $payment)
    {
        $current = $this->payment($payment);
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:500']);
        return $this->decide($current, $data['version'], 'rejected', $data['reason']);
    }

    private function decide(array $current, int $version, string $status, ?string $reason): array
    {
        if ($version !== (int) $current['version']) throw Refusal::of('stale_version');
        $changed = DB::update('UPDATE school.expense_payment SET status = ?, decision_reason = ?, version = ? WHERE tenant_id = ? AND id = ?',
            [$status, $reason, $version, $this->school->id(), $current['id']]);
        if (!$changed) throw Refusal::permissionDenied();
        return ['data' => $this->payment($current['id'])];
    }

    private function payment(string $id): array
    {
        abort_unless(Str::isUuid($id), 404);
        $row = DB::selectOne(ExpenseController::PAYMENTS.' WHERE y.tenant_id = ? AND y.id = ?', [$this->school->id(), $id]);
        abort_unless($row, 404);
        return (array) $row;
    }
}
