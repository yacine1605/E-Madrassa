<?php

namespace App\Exports;

use App\Http\Controllers\OperationsDashboardController;
use App\Support\Locales;
use Illuminate\Support\Facades\DB;

/**
 * FR-TDB-03: the operational dashboard as its screen shows it, group by group, within the reader's
 * scope (SQL 070: the whole school, their sites or a teacher's own groups); the figures of a module
 * the school does not use left out (BR-20). Rates in percent, to one decimal.
 */
final class OperationsDashboardExport implements Dataset
{
    private const MODULES = ['enrollment_groups' => ['capacity', 'members', 'fill_rate'], 'scheduling_manual' => ['sessions_held'],
        'attendance' => ['teacher_validated', 'teacher_validated_rate', 'teacher_missing', 'roll_missing', 'marks', 'absent', 'absence_rate', 'late']];

    public function __construct(private OperationsDashboardController $dashboard) {}

    public function permission(): string { return 'dashboard.operations.read'; }

    public function title(string $locale): string { return __('dashboard.operations', [], $locale); }

    public function columns(string $locale): array
    {
        $modules = collect(DB::select('SELECT key FROM unnest(ARRAY[?, ?, ?]) AS key WHERE school.module_enabled(key)', ['enrollment_groups', 'scheduling_manual', 'attendance']))
            ->pluck('key')->all();
        $keys = array_merge(['group', 'program'], ...array_values(array_intersect_key(self::MODULES, array_flip($modules))));
        return array_map(fn (string $key) => ['key' => $key, 'label' => __('dashboard.columns.'.$key, [], $locale)], $keys);
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
        foreach ($this->figures($filters)['data'] as $group) {
            yield ['group' => implode(' · ', array_filter([$group['code'], $group['labels'] ? ($group['labels'][$locale] ?? Locales::label($group['labels'])) : null])),
                'program' => $group['program_code']] + $group;
        }
    }

    private function figures(array $filters): array
    {
        return $this->dashboard->figures($filters['from'] ?? now()->startOfMonth()->toDateString(), $filters['to'] ?? now()->toDateString());
    }
}
