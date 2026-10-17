<?php

namespace App\Exports;

use App\Http\Controllers\FinanceDashboardController;
use Illuminate\Support\Facades\DB;

/**
 * FR-TDB-03: the finance dashboard as its screen shows it, month by month, for whoever reads it
 * for the whole school (SQL 069 refuses anyone else); the figures of a module the school does not
 * use left out (BR-20). Amounts as the base gives them: exact, two decimals (BR-04).
 */
final class FinanceDashboardExport implements Dataset
{
    private const MODULES = ['student_finance' => ['billed', 'payments', 'reversals', 'refunds', 'net_receipts'], 'expenses' => ['expenses_committed', 'expenses_paid'],
        'payroll' => ['salaries_committed', 'salaries_paid'], 'result' => ['net_result']];

    public function __construct(private FinanceDashboardController $dashboard) {}

    public function permission(): string { return 'dashboard.finance.read'; }

    public function title(string $locale): string { return __('dashboard.finance', [], $locale); }

    public function columns(string $locale): array
    {
        $modules = collect(DB::select('SELECT key FROM unnest(ARRAY[?, ?, ?]) AS key WHERE school.module_enabled(key)', ['student_finance', 'expenses', 'payroll']))
            ->pluck('key')->all();
        // The management result rests on the net receipts.
        if (in_array('student_finance', $modules, true)) $modules[] = 'result';
        $keys = array_merge(...array_values(array_intersect_key(self::MODULES, array_flip($modules))), ...[[]]);
        return [['key' => 'month', 'label' => __('dashboard.month', [], $locale)],
            ...array_map(fn (string $key) => ['key' => $key, 'label' => __('dashboard.indicators.'.$key, [], $locale)], $keys)];
    }

    public function filters(): array
    {
        return ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'];
    }

    public function count(array $filters): int
    {
        return count($this->figures($filters)['data']);
    }

    public function rows(array $filters, string $locale): iterable
    {
        foreach ($this->figures($filters)['data'] as $month) {
            yield ['month' => substr($month['month'], 0, 7)] + $month;
        }
    }

    private function figures(array $filters): array
    {
        return $this->dashboard->figures($filters['from'] ?? now()->subMonths(5)->toDateString(), $filters['to'] ?? now()->toDateString());
    }
}
