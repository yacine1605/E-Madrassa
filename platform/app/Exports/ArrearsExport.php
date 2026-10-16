<?php

namespace App\Exports;

use App\Http\Controllers\ArrearsController;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;

/** FR-FIN-06: the open balances and overdue instalments, as the arrears' screen lists them, for whoever reads the payments (« export contrôlé »). */
final class ArrearsExport implements Dataset
{
    public function __construct(private CurrentSchool $school) {}

    public function permission(): string { return 'finance.payment.read'; }

    public function title(string $locale): string { return __('exports.arrears', [], $locale); }

    public function columns(string $locale): array
    {
        return array_map(fn ($key) => ['key' => $key, 'label' => __("exports.columns.$key", [], $locale)],
            ['matricule', 'pupil', 'program', 'group', 'due', 'paid', 'balance', 'overdue', 'overdue_since', 'next_due_date']);
    }

    public function filters(): array
    {
        return ArrearsController::RULES;
    }

    public function count(array $filters): int
    {
        [$sql, $bindings] = ArrearsController::query($this->school->id(), $filters);
        return (int) DB::selectOne("SELECT count(*) AS n FROM ($sql) listed", $bindings)->n;
    }

    public function rows(array $filters, string $locale): iterable
    {
        [$sql, $bindings] = ArrearsController::query($this->school->id(), $filters);
        foreach (DB::cursor($sql.' ORDER BY a.overdue_since NULLS LAST, a.balance DESC, s.matricule', $bindings) as $row) {
            $latin = trim($row->last_name_lat.' '.$row->first_name_lat);
            $arabic = trim($row->last_name_ar.' '.$row->first_name_ar);
            // Amounts as the base gives them: exact, two decimals (BR-04).
            yield ['matricule' => $row->matricule, 'pupil' => implode(' · ', array_filter($locale === 'ar' ? [$arabic, $latin] : [$latin, $arabic])),
                'program' => $row->program, 'group' => $row->group_code, 'due' => $row->due, 'paid' => $row->paid, 'balance' => $row->balance,
                'overdue' => $row->overdue, 'overdue_since' => $row->overdue_since, 'next_due_date' => $row->next_due_date];
        }
    }
}
