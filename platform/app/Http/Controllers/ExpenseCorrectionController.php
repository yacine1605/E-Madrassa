<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-DEP-03: the corrections of the school's expenses, the latest first; those waiting for their
 * validation (`pending_validation`). A validated expense is never changed nor erased: it is
 * corrected or cancelled by its linked negative entry, with its reason, validated or rejected by
 * someone else than whoever asked for it (BR-08), the original kept, never below what is paid of
 * it. The rules are SQL 057's.
 */
final class ExpenseCorrectionController
{
    public function __construct(private CurrentSchool $school) {}

    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + ['status' => 'nullable|in:pending_validation,validated,rejected', 'kind' => 'nullable|in:correction,cancellation',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'search' => 'nullable|string|max:100']);
        $request->merge(['paginate' => true]);
        $sql = ExpenseController::CORRECTIONS.' WHERE k.tenant_id = ?';
        $bindings = [$this->school->id()];
        foreach (['status' => 'k.status = ?', 'kind' => 'k.kind = ?', 'from' => 'k.corrected_on >= ?::date', 'to' => 'k.corrected_on <= ?::date'] as $key => $clause) {
            if (isset($data[$key])) { $sql .= ' AND '.$clause; $bindings[] = $data[$key]; }
        }
        if (isset($data['search']) && trim($data['search']) !== '') {
            $sql .= ' AND '.Paging::search(['x.beneficiary', 'k.reason']);
            $bindings[] = $data['search'];
        }
        return Paging::select($request, $sql, $bindings, 'k.created_at DESC, k.id', fn ($row) => (array) $row);
    }

    /** BR-08: by someone else than whoever asked for it. */
    public function validateCorrection(Request $request, string $correction)
    {
        $current = $this->correction($correction);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        return $this->decide($current, $data['version'], 'validated', null);
    }

    public function reject(Request $request, string $correction)
    {
        $current = $this->correction($correction);
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:500']);
        return $this->decide($current, $data['version'], 'rejected', $data['reason']);
    }

    private function decide(array $current, int $version, string $status, ?string $reason): array
    {
        if ($version !== (int) $current['version']) throw Refusal::of('stale_version');
        $changed = DB::update('UPDATE school.expense_correction SET status = ?, decision_reason = ?, version = ? WHERE tenant_id = ? AND id = ?',
            [$status, $reason, $version, $this->school->id(), $current['id']]);
        if (!$changed) throw Refusal::permissionDenied();
        return ['data' => $this->correction($current['id'])];
    }

    private function correction(string $id): array
    {
        abort_unless(Str::isUuid($id), 404);
        $row = DB::selectOne(ExpenseController::CORRECTIONS.' WHERE k.tenant_id = ? AND k.id = ?', [$this->school->id(), $id]);
        abort_unless($row, 404);
        return (array) $row;
    }
}
