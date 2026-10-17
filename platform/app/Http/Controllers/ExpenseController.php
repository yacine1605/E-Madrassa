<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use App\Support\Files;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-DEP-02: the school's expenses. Entered once per key with its reason (BR-05), corrected by its
 * author while a draft, submitted, then validated or rejected with its reason by someone else
 * (AC1), by the direction beyond the school's threshold; never changed once decided (AC2). Its
 * documents are added by its author while a draft and read only with `expense.attachment.read`
 * (AC3). Validated, it is corrected or cancelled by its linked negative entry, decided by someone
 * else (FR-DEP-03). An expense of the payroll is its payout's (`payroll_payout_id`), paid and taken
 * back with it alone (FR-REM-04). Amounts are strings with two decimals (BR-04). The rules are SQL 055
 * to 057 and 060's.
 */
final class ExpenseController
{
    private const EXPENSES = "SELECT x.id, x.expense_date::text AS expense_date, x.amount::text AS amount, x.category_id, c.code AS category_code, c.labels AS category_labels,
        x.label, x.beneficiary, x.reason, x.program_id, (SELECT p.code FROM school.program p WHERE p.tenant_id = x.tenant_id AND p.id = x.program_id) AS program,
        x.status, x.needs_approval, x.submitted_at, x.decided_at, x.decision_reason, x.version, x.created_by, x.created_at,
        (SELECT u.name FROM public.users u WHERE u.id = x.created_by) AS author, (SELECT u.name FROM public.users u WHERE u.id = x.decided_by) AS decider,
        y.committed::text AS committed, y.paid::text AS paid, (y.committed - y.paid)::numeric(14, 2)::text AS left_to_pay,
        school.expense_payment_state(x.status, y.committed, y.paid) AS payment_state, x.payroll_payout_id,
        (SELECT r.period::text FROM school.payroll_payout o JOIN school.payroll_run r ON r.tenant_id = o.tenant_id AND r.id = o.run_id
          WHERE o.tenant_id = x.tenant_id AND o.id = x.payroll_payout_id) AS payroll_period
        FROM school.expense x JOIN school.expense_category c ON c.tenant_id = x.tenant_id AND c.id = x.category_id
        CROSS JOIN LATERAL (SELECT school.expense_paid(x.tenant_id, x.id) AS paid, school.expense_committed(x.tenant_id, x.id) AS committed) y";
    public const RULES = ['status' => 'nullable|in:draft,submitted,validated,rejected', 'payment' => 'nullable|in:unpaid,partially_paid,paid,cancelled', 'category' => 'nullable|uuid',
        'program' => 'nullable|uuid', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'search' => 'nullable|string|max:100'];
    public const PAYMENTS = "SELECT y.id, y.expense_id, y.kind, y.reverses_payment_id, y.amount::text AS amount, y.paid_on::text AS paid_on, y.method_code, y.external_ref,
        y.reason, y.status, y.decided_at, y.decision_reason, y.version, y.created_by, y.created_at, x.beneficiary, x.expense_date::text AS expense_date,
        x.payroll_payout_id, (SELECT u.name FROM public.users u WHERE u.id = y.created_by) AS author, (SELECT u.name FROM public.users u WHERE u.id = y.decided_by) AS decider
        FROM school.expense_payment y JOIN school.expense x ON x.tenant_id = y.tenant_id AND x.id = y.expense_id";
    public const CORRECTIONS = "SELECT k.id, k.expense_id, k.kind, k.amount::text AS amount, k.corrected_on::text AS corrected_on, k.reason, k.status, k.decided_at,
        k.decision_reason, k.version, k.created_by, k.created_at, x.beneficiary, x.expense_date::text AS expense_date, x.amount::text AS expense_amount,
        (SELECT u.name FROM public.users u WHERE u.id = k.created_by) AS author, (SELECT u.name FROM public.users u WHERE u.id = k.decided_by) AS decider
        FROM school.expense_correction k JOIN school.expense x ON x.tenant_id = k.tenant_id AND x.id = k.expense_id";
    // Positive, two decimals at most.
    private const AMOUNT = ['required', 'string', 'regex:/^(?!0+(\.0+)?$)(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/'];
    private const FIELDS = ['expense_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'category_id' => 'required|uuid', 'label' => 'nullable|string|max:200',
        'beneficiary' => 'required|string|min:2|max:200', 'reason' => 'required|string|min:3|max:500', 'program_id' => 'nullable|uuid'];

    public function __construct(private CurrentSchool $school, private Files $files) {}

    /** The expenses, the latest first: by state (the validations waiting: `submitted`), category, programme, period, beneficiary. */
    public function index(Request $request)
    {
        $data = $request->validate(Paging::RULES + self::RULES);
        $request->merge(['paginate' => true]);
        [$sql, $bindings] = self::query($this->school->id(), $data);
        return Paging::select($request, $sql, $bindings, self::ORDER, fn ($row) => self::shape($row));
    }

    public const ORDER = 'x.expense_date DESC, x.created_at DESC, x.id';

    /** @return array{0: string, 1: array} the expenses asked for, as the screen and the export list them; by payment state, the validated ones */
    public static function query(string $tenant, array $filters): array
    {
        $sql = self::EXPENSES.' WHERE x.tenant_id = ?';
        $bindings = [$tenant];
        foreach (['status' => 'x.status = ?', 'payment' => 'school.expense_payment_state(x.status, y.committed, y.paid) = ?', 'category' => 'x.category_id = ?',
            'program' => 'x.program_id = ?', 'from' => 'x.expense_date >= ?::date', 'to' => 'x.expense_date <= ?::date'] as $key => $clause) {
            if (isset($filters[$key])) { $sql .= ' AND '.$clause; $bindings[] = $filters[$key]; }
        }
        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $sql .= ' AND '.Paging::search(['x.beneficiary', 'x.label', 'x.reason']);
            $bindings[] = $filters['search'];
        }
        return [$sql, $bindings];
    }

    /** An expense's payments and their reversals, the latest first. */
    public function payments(string $expense)
    {
        $current = $this->expense($expense);
        return ['data' => array_map(fn ($row) => (array) $row, DB::select(self::PAYMENTS.' WHERE y.tenant_id = ? AND y.expense_id = ? ORDER BY y.created_at DESC, y.id',
            [$this->school->id(), $current['id']]))];
    }

    /** A validated expense paid, at once or in parts, never beyond what is left; once per key (BR-05). */
    public function pay(Request $request, string $expense)
    {
        $current = $this->expense($expense);
        $data = $request->validate(['amount' => self::AMOUNT, 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'method_code' => 'required|string|regex:/^[a-z_]{1,20}$/',
            'external_ref' => 'nullable|string|max:100', 'idempotency_key' => 'required|uuid']);
        $id = DB::selectOne('SELECT school.record_expense_payment(?, ?::numeric, ?::date, ?, ?, ?) AS id', [$current['id'], $data['amount'], $data['paid_on'],
            $data['method_code'], $data['external_ref'] ?? null, $data['idempotency_key']])->id;
        return response()->json(['data' => (array) DB::selectOne(self::PAYMENTS.' WHERE y.tenant_id = ? AND y.id = ?', [$this->school->id(), $id]),
            'expense' => $this->expense($current['id'])], 201);
    }

    /** An expense's corrections, the latest first. */
    public function corrections(string $expense)
    {
        $current = $this->expense($expense);
        return ['data' => array_map(fn ($row) => (array) $row, DB::select(self::CORRECTIONS.' WHERE k.tenant_id = ? AND k.expense_id = ? ORDER BY k.created_at DESC, k.id',
            [$this->school->id(), $current['id']]))];
    }

    /** FR-DEP-03: part of a validated expense taken off (`correction`) or all that is committed of it (`cancellation`), with its reason; once per key. */
    public function correct(Request $request, string $expense)
    {
        $current = $this->expense($expense);
        $data = $request->validate(['kind' => 'required|in:correction,cancellation', 'amount' => array_merge(['required_if:kind,correction', 'nullable', 'prohibited_if:kind,cancellation'],
            array_slice(self::AMOUNT, 1)), 'reason' => 'required|string|min:3|max:500', 'idempotency_key' => 'required|uuid']);
        $id = DB::selectOne('SELECT school.correct_expense(?, ?, ?::numeric, ?, ?) AS id', [$current['id'], $data['kind'], $data['amount'] ?? null, $data['reason'],
            $data['idempotency_key']])->id;
        return response()->json(['data' => (array) DB::selectOne(self::CORRECTIONS.' WHERE k.tenant_id = ? AND k.id = ?', [$this->school->id(), $id]),
            'expense' => $this->expense($current['id'])], 201);
    }

    public function show(string $expense)
    {
        $row = $this->expense($expense);
        // AC3: its documents, for whoever reads them only.
        $row['documents'] = app(\App\Support\Permissions::class)->can('expense.attachment.read') ? array_map(fn ($file) => (array) $file, DB::select(
            'SELECT id, original_name, size_bytes, created_at FROM school.file_object WHERE tenant_id = ? AND owner_type = ? AND owner_id = ? AND archived_at IS NULL
             ORDER BY created_at', [$this->school->id(), 'expense', $row['id']])) : null;
        return ['data' => $row];
    }

    /** A draft, once per key; the same key again answers the first one (BR-05). */
    public function store(Request $request)
    {
        $data = $request->validate(['amount' => self::AMOUNT, 'idempotency_key' => 'required|uuid'] + self::FIELDS);
        $id = DB::selectOne('SELECT school.record_expense(?::date, ?::numeric, ?, ?, ?, ?, ?, ?) AS id', [$data['expense_date'], $data['amount'], $data['category_id'],
            $data['label'] ?? null, $data['beneficiary'], $data['reason'], $data['program_id'] ?? null, $data['idempotency_key']])->id;
        return response()->json(['data' => $this->expense($id)], 201);
    }

    /** Its author corrects a draft. */
    public function update(Request $request, string $expense)
    {
        $current = $this->expense($expense);
        $data = $request->validate(['amount' => self::AMOUNT, 'version' => 'required|integer|min:1'] + self::FIELDS);
        return $this->change($current, $data['version'], 'expense_date = ?::date, amount = ?::numeric, category_id = ?, label = ?, beneficiary = ?, reason = ?, program_id = ?',
            [$data['expense_date'], $data['amount'], $data['category_id'], $data['label'] ?? null, $data['beneficiary'], $data['reason'], $data['program_id'] ?? null]);
    }

    /** Its author submits it; it waits as it is, the direction's beyond the threshold. */
    public function submit(Request $request, string $expense)
    {
        $current = $this->expense($expense);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        return $this->change($current, $data['version'], "status = 'submitted'", []);
    }

    /** AC1: validated by someone else than its author. */
    public function validateExpense(Request $request, string $expense)
    {
        $current = $this->expense($expense);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        return $this->change($current, $data['version'], "status = 'validated'", []);
    }

    public function reject(Request $request, string $expense)
    {
        $current = $this->expense($expense);
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:500']);
        return $this->change($current, $data['version'], "status = 'rejected', decision_reason = ?", [$data['reason']]);
    }

    /** A document added by its author while a draft (FR-FIC-01: its real type checked). */
    public function attach(Request $request, string $expense)
    {
        $current = $this->expense($expense);
        $request->validate(['attachment' => 'required|file|max:51200']);
        $this->files->upload($request->file('attachment'), 'finance_attachment', 'expense', $current['id']);
        return response()->json($this->show($current['id']), 201);
    }

    /** AC3: a document, for `expense.attachment.read` only, even with its address. */
    public function document(string $expense, string $file)
    {
        $current = $this->expense($expense);
        abort_unless(Str::isUuid($file), 404);
        $row = DB::selectOne('SELECT storage_key, original_name, media_type, size_bytes FROM school.file_object
            WHERE tenant_id = ? AND id = ? AND owner_type = ? AND owner_id = ? AND archived_at IS NULL AND purged_at IS NULL',
            [$this->school->id(), $file, 'expense', $current['id']]) ?? abort(404);
        return $this->files->download($row->storage_key, $row->original_name, $row->media_type, (int) $row->size_bytes);
    }

    private function change(array $current, int $version, string $set, array $bindings): array
    {
        if ($version !== (int) $current['version']) throw Refusal::of('stale_version');
        $changed = DB::update("UPDATE school.expense SET $set, version = ? WHERE tenant_id = ? AND id = ?", [...$bindings, $version, $this->school->id(), $current['id']]);
        if (!$changed) throw Refusal::permissionDenied();
        return ['data' => $this->expense($current['id'])];
    }

    private function expense(string $id): array
    {
        abort_unless(Str::isUuid($id), 404);
        $row = DB::selectOne(self::EXPENSES.' WHERE x.tenant_id = ? AND x.id = ?', [$this->school->id(), $id]);
        abort_unless($row, 404);
        return self::shape($row);
    }

    private static function shape(object $row): array
    {
        $data = (array) $row;
        $data['category_labels'] = json_decode($data['category_labels'], true);
        return $data;
    }
}
