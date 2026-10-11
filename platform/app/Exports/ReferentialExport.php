<?php

namespace App\Exports;

use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;

/** The school's referential tree, level by level, as the referential screen shows it. */
final class ReferentialExport implements Dataset
{
    public function __construct(private CurrentSchool $school) {}

    public function permission(): string { return 'referential.node.read'; }

    public function title(string $locale): string { return __('exports.referential', [], $locale); }

    public function columns(string $locale): array
    {
        return array_map(fn ($key) => ['key' => $key, 'label' => __("exports.columns.$key", [], $locale)],
            ['type', 'code', 'label_fr', 'label_ar', 'parent', 'archived']);
    }

    public function filters(): array
    {
        return ['type' => 'nullable|string|max:40', 'include_archived' => 'boolean'];
    }

    public function count(array $filters): int
    {
        [$where, $bindings] = $this->where($filters);
        return (int) DB::selectOne("SELECT count(*) AS n FROM school.ref_node n WHERE $where", $bindings)->n;
    }

    public function rows(array $filters, string $locale): iterable
    {
        [$where, $bindings] = $this->where($filters);
        $sql = "SELECT t.labels ->> ? AS type_label, t.labels ->> 'fr' AS type_fr, n.code, n.labels ->> 'fr' AS label_fr, n.labels ->> 'ar' AS label_ar,
                p.code AS parent, n.archived_at IS NOT NULL AS archived
            FROM school.ref_node n JOIN school.node_type t ON t.tenant_id = n.tenant_id AND t.code = n.type_code
            LEFT JOIN school.ref_node p ON p.tenant_id = n.tenant_id AND p.id = n.parent_id
            WHERE $where ORDER BY t.level_order, p.code NULLS FIRST, n.sort_order, n.code";
        foreach (DB::cursor($sql, [$locale, ...$bindings]) as $row) {
            yield ['type' => $row->type_label ?? $row->type_fr, 'code' => $row->code, 'label_fr' => $row->label_fr, 'label_ar' => $row->label_ar,
                'parent' => $row->parent, 'archived' => __($row->archived ? 'exports.yes' : 'exports.no', [], $locale)];
        }
    }

    private function where(array $filters): array
    {
        $where = 'n.tenant_id = ?';
        $bindings = [$this->school->id()];
        if (isset($filters['type'])) { $where .= ' AND n.type_code = ?'; $bindings[] = $filters['type']; }
        if (empty($filters['include_archived'])) $where .= ' AND n.archived_at IS NULL';
        return [$where, $bindings];
    }
}
