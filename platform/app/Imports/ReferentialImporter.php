<?php

namespace App\Imports;

use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;

/**
 * The referential's nodes, by type and code: the parent is found by its code among the nodes of
 * the parent type, never created (FR-IMP-01: an unknown level is refused with its line and cause).
 */
final class ReferentialImporter implements Importer
{
    public function __construct(private CurrentSchool $school) {}

    public function permission(): string { return 'referential.node.manage'; }

    public function rules(): array
    {
        return ['type' => 'required|string|max:40', 'code' => ['required', 'string', 'regex:/^[A-Z0-9_]{1,20}$/'],
            'parent_code' => ['nullable', 'string', 'regex:/^[A-Z0-9_]{1,20}$/'],
            'label_fr' => 'required_without:label_ar|nullable|string|max:150', 'label_ar' => 'required_without:label_fr|nullable|string|max:150',
            'label_en' => 'nullable|string|max:150', 'sort_order' => 'nullable|integer|min:0|max:100000'];
    }

    public function key(array $row): string { return $row['type'].':'.$row['code']; }

    public function apply(array $row, array $options = []): array
    {
        $tenant = $this->school->id();
        $kind = DB::selectOne('SELECT parent_code FROM school.node_type WHERE tenant_id = ? AND code = ?', [$tenant, $row['type']])
            ?? throw new RowRefused('reference_unknown', 'Type inconnu dans le référentiel : '.$row['type'].'.');
        $current = DB::selectOne('SELECT id, labels, sort_order FROM school.ref_node WHERE tenant_id = ? AND type_code = ? AND code = ?', [$tenant, $row['type'], $row['code']]);
        // A column the file leaves out keeps the current value; an empty cell clears a label.
        $labels = $this->labels($row, $current ? json_decode($current->labels, true) : []);
        $sort = array_key_exists('sort_order', $row) && $row['sort_order'] !== null ? (int) $row['sort_order'] : (int) ($current->sort_order ?? 0);
        if ($current) {
            $previous = ['labels' => json_decode($current->labels, true), 'sort_order' => (int) $current->sort_order];
            if ($previous === ['labels' => $labels, 'sort_order' => $sort]) return ['outcome' => 'unchanged', 'target_type' => 'ref_node', 'target_id' => $current->id, 'previous' => null];
            DB::update('UPDATE school.ref_node SET labels = ?::jsonb, sort_order = ? WHERE tenant_id = ? AND id = ?', [json_encode($labels), $sort, $tenant, $current->id]);
            return ['outcome' => 'updated', 'target_type' => 'ref_node', 'target_id' => $current->id, 'previous' => $previous];
        }
        $parent = null;
        if ($kind->parent_code !== null) {
            if (empty($row['parent_code'])) throw new RowRefused('reference_missing', 'Le parent est obligatoire pour ce type.');
            $parent = DB::selectOne('SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = ? AND code = ? AND archived_at IS NULL',
                [$tenant, $kind->parent_code, $row['parent_code']])?->id
                ?? throw new RowRefused('reference_unknown', 'Parent inconnu dans le référentiel : '.$row['parent_code'].'.');
        }
        $id = DB::selectOne('INSERT INTO school.ref_node (tenant_id, type_code, parent_id, code, labels, sort_order) VALUES (?, ?, ?, ?, ?::jsonb, ?) RETURNING id',
            [$tenant, $row['type'], $parent, $row['code'], json_encode($labels), $sort])->id;
        return ['outcome' => 'created', 'target_type' => 'ref_node', 'target_id' => $id, 'previous' => null];
    }

    public function undo(string $outcome, string $targetId, ?array $previous, array $row): void
    {
        $tenant = $this->school->id();
        $current = DB::selectOne('SELECT labels, archived_at, (SELECT count(*) FROM school.ref_node c WHERE c.tenant_id = n.tenant_id AND c.parent_id = n.id AND c.archived_at IS NULL) AS children
            FROM school.ref_node n WHERE tenant_id = ? AND id = ?', [$tenant, $targetId]);
        if (!$current || $current->archived_at !== null) throw new DependentOperation(0, 'Cet élément a changé depuis l’import.');
        if ($outcome === 'created') {
            if ($current->children > 0) throw new DependentOperation(0, 'Des éléments ont été ajoutés sous cet élément depuis l’import.');
            DB::update('UPDATE school.ref_node SET archived_at = now() WHERE tenant_id = ? AND id = ?', [$tenant, $targetId]);
            return;
        }
        if (json_decode($current->labels, true) != $this->labels($row, $previous['labels'])) throw new DependentOperation(0, 'Cet élément a été renommé depuis l’import.');
        DB::update('UPDATE school.ref_node SET labels = ?::jsonb, sort_order = ? WHERE tenant_id = ? AND id = ?',
            [json_encode($previous['labels']), $previous['sort_order'], $tenant, $targetId]);
    }

    /** The row's labels over the current ones, language by language. */
    private function labels(array $row, array $current): array
    {
        foreach (['fr', 'ar', 'en'] as $language) {
            if (!array_key_exists("label_$language", $row)) continue;
            $label = $row["label_$language"];
            if ($label === null || trim($label) === '') unset($current[$language]);
            else $current[$language] = trim($label);
        }
        ksort($current);
        return $current;
    }
}
