<?php

namespace App\Exports;

use App\Http\Controllers\ExpenseController;
use App\Support\CurrentSchool;
use App\Support\Locales;
use Illuminate\Support\Facades\DB;

/** FR-DEP-02, FR-DEP-03: the expenses as their screen lists them, what is committed of them once corrected, by period, category, beneficiary, state, for whoever reads them (« filtres et exports »). */
final class ExpensesExport implements Dataset
{
    public function __construct(private CurrentSchool $school) {}

    public function permission(): string { return 'expense.expense.read'; }

    public function title(string $locale): string { return __('exports.expenses', [], $locale); }

    public function columns(string $locale): array
    {
        return array_map(fn ($key) => ['key' => $key, 'label' => __("exports.columns.$key", [], $locale)],
            ['expense_date', 'category', 'label', 'beneficiary', 'reason', 'program', 'amount', 'committed', 'expense_status', 'paid', 'left_to_pay', 'payment_state', 'author', 'decider']);
    }

    public function filters(): array
    {
        return ExpenseController::RULES;
    }

    public function count(array $filters): int
    {
        [$sql, $bindings] = ExpenseController::query($this->school->id(), $filters);
        return (int) DB::selectOne("SELECT count(*) AS n FROM ($sql) listed", $bindings)->n;
    }

    public function rows(array $filters, string $locale): iterable
    {
        [$sql, $bindings] = ExpenseController::query($this->school->id(), $filters);
        foreach (DB::cursor($sql.' ORDER BY '.ExpenseController::ORDER, $bindings) as $row) {
            $labels = json_decode($row->category_labels, true);
            // Amounts as the base gives them: exact, two decimals (BR-04).
            yield ['expense_date' => $row->expense_date, 'category' => $labels[$locale] ?? Locales::label($labels), 'label' => $row->label,
                'beneficiary' => $row->beneficiary, 'reason' => $row->reason, 'program' => $row->program, 'amount' => $row->amount,
                'committed' => $row->status === 'validated' ? $row->committed : null,
                'expense_status' => __('expenses.states.'.$row->status, [], $locale), 'paid' => $row->status === 'validated' ? $row->paid : null,
                'left_to_pay' => $row->status === 'validated' ? $row->left_to_pay : null,
                'payment_state' => $row->payment_state ? __('expenses.payment_states.'.$row->payment_state, [], $locale) : null,
                'author' => $row->author, 'decider' => $row->decider];
        }
    }
}
