<?php

namespace App\Imports;

use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;

/**
 * Programmes by their year and code (FR-IMP-01, FR-PRG-01): created with their level from the
 * referential, names, duration, price and state, or updated column by column; a column the file
 * leaves out keeps its value, and a new price makes a new version (BR-11), the enrolments made
 * keeping theirs. A programme's level never changes. Undone while nothing uses it (SQL 045).
 */
final class ProgramsImporter implements Importer
{
    use Columns;

    private const FIELDS = ['labels', 'description', 'duration_value', 'duration_unit', 'base_price', 'status'];
    private const SELECT = 'SELECT id, node_id, labels, description, duration_value, duration_unit, base_price, status FROM school.program';

    public function __construct(private CurrentSchool $school) {}

    public function permission(): string { return 'program.program.manage'; }

    public function rules(): array
    {
        return ['year' => 'required|string|max:40', 'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,30}$/'], 'level' => 'required|string|max:40',
            'label_fr' => 'nullable|string|max:150', 'label_ar' => 'nullable|string|max:150', 'description' => 'nullable|string|max:2000',
            'duration_value' => 'nullable|integer|min:1', 'duration_unit' => 'nullable|in:hours,days,weeks,months,years',
            'base_price' => ['nullable', 'regex:/^(0|[1-9][0-9]{0,11})([.,][0-9]{1,2})?$/'], 'status' => 'nullable|in:draft,active,inactive'];
    }

    public function key(array $row): string { return trim((string) $row['year']).' / '.mb_strtoupper(trim((string) $row['code'])); }

    public function apply(array $row, array $options = []): array
    {
        $tenant = $this->school->id();
        $year = DB::selectOne('SELECT id FROM school.academic_year WHERE tenant_id = ? AND code = ?', [$tenant, trim($row['year'])])?->id
            ?? throw new RowRefused('reference_unknown', __('errors.import_year_unknown', ['code' => trim($row['year'])]));
        $code = mb_strtoupper(trim($row['code']));
        $level = mb_strtoupper(trim($row['level']));
        $node = DB::selectOne('SELECT id FROM school.ref_node WHERE tenant_id = ? AND code = ? AND archived_at IS NULL', [$tenant, $level])?->id
            ?? throw new RowRefused('reference_unknown', __('errors.import_node_unknown', ['code' => $level]));
        $current = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND year_id = ? AND code = ?', [$tenant, $year, $code]);
        if ($current) {
            if ($current->node_id !== $node) throw new RowRefused('code_immutable', __('errors.import_code_differs', ['column' => 'level']));
            $before = $this->state($current);
            $changes = $this->changes($before, $this->wanted($row, $before));
            if (!$changes['after']) return ['outcome' => 'unchanged', 'target_type' => 'program', 'target_id' => $current->id, 'previous' => null];
            $this->write($current->id, $changes['after']);
            return ['outcome' => 'updated', 'target_type' => 'program', 'target_id' => $current->id, 'previous' => $changes];
        }
        $values = $this->wanted($row, $this->state(null));
        if ($values['labels'] === []) throw new RowRefused('name_required', __('errors.import_value_invalid', ['column' => 'label_fr']));
        $id = DB::table('school.program')->insertGetId(['tenant_id' => $tenant, 'year_id' => $year, 'node_id' => $node, 'code' => $code,
            ...$this->columns(array_filter($values, fn ($value) => $value !== null))], 'id');
        // As the database wrote it, its defaults included: what an undo finds unchanged.
        $values = $this->state(DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?', [$tenant, $id]));
        return ['outcome' => 'created', 'target_type' => 'program', 'target_id' => $id, 'previous' => ['created' => ['program' => $id], 'after' => $values]];
    }

    public function undo(string $outcome, string $targetId, ?array $previous, array $row): void
    {
        $current = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?', [$this->school->id(), $targetId]);
        if (!$current || !$this->holds($this->state($current), $previous['after'])) throw new DependentOperation(0, __('errors.import_record_changed'));
        if ($outcome === 'created') DB::select('SELECT school.discard_program(?)', [$targetId]);
        else $this->write($targetId, $previous['before']);
    }

    private function state(?object $current): array
    {
        $state = array_fill_keys(self::FIELDS, null);
        if ($current) foreach (self::FIELDS as $field) $state[$field] = $current->$field;
        $state['labels'] = $current ? json_decode($current->labels, true, 4, JSON_THROW_ON_ERROR) : [];
        ksort($state['labels']);
        if ($state['duration_value'] !== null) $state['duration_value'] = (int) $state['duration_value'];
        return $state;
    }

    private function wanted(array $row, array $state): array
    {
        foreach (['fr', 'ar'] as $language) {
            if (!array_key_exists("label_$language", $row)) continue;
            $label = $this->text($row["label_$language"]);
            if ($label === null) unset($state['labels'][$language]);
            else $state['labels'][$language] = $label;
        }
        ksort($state['labels']);
        foreach (['description', 'duration_unit'] as $field) if (array_key_exists($field, $row)) $state[$field] = $this->text($row[$field]);
        // An empty state or price keeps the current one: both are required.
        if (($status = $this->text($row['status'] ?? null)) !== null) $state['status'] = $status;
        if (array_key_exists('duration_value', $row)) $state['duration_value'] = ($value = $this->text($row['duration_value'])) === null ? null : (int) $value;
        // A price as the school writes it (« 120000,50 »), kept as the database keeps it.
        if (array_key_exists('base_price', $row) && ($price = $this->text($row['base_price'])) !== null) $state['base_price'] = number_format((float) str_replace(',', '.', $price), 2, '.', '');
        if (($state['duration_value'] === null) !== ($state['duration_unit'] === null)) throw new RowRefused('invalid', __('errors.duration_pair_required'));
        return $state;
    }

    private function write(string $id, array $values): void
    {
        DB::table('school.program')->where('tenant_id', $this->school->id())->where('id', $id)->update($this->columns($values));
    }

    private function columns(array $values): array
    {
        if (array_key_exists('labels', $values)) $values['labels'] = json_encode((object) $values['labels'], JSON_THROW_ON_ERROR);
        return $values;
    }
}
