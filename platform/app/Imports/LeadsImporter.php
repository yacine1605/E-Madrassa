<?php

namespace App\Imports;

use App\Http\Controllers\LeadController;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;

/**
 * Prospects by the reference of the school's file (FR-IMP-01, FR-CRM-01; section 3.3: the front
 * office imports « élèves, CRM »): created with their contact, source and first child, or updated
 * column by column; a column the file leaves out keeps its value. Recorded on the site the `site`
 * column names by its code, else the school's default site; at the first step (`new`). A phone
 * already known among prospects, pupils and guardians is refused unless the row gives a reason
 * (`duplicate_reason`), which the journal keeps. A closed prospect is changed on screen only.
 * Undone while nothing has been built on it since (SQL 043).
 */
final class LeadsImporter implements Importer
{
    use Columns;

    private const FIELDS = ['last_name', 'first_name', 'phone', 'email', 'relationship', 'source'];
    private const SELECT = 'SELECT id, last_name, first_name, phone, email, relationship, source, custom, site_id FROM school.lead';

    public function __construct(private CurrentSchool $school) {}

    public function permission(): string { return 'crm.lead.create'; }

    public function rules(): array
    {
        return ['external_ref' => 'required|string|max:100', 'last_name' => 'nullable|string|max:80', 'first_name' => 'nullable|string|max:80',
            'phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:200', 'relationship' => 'nullable|in:father,mother,guardian,self,other',
            'source' => 'nullable|string|max:30', 'site' => 'nullable|string|max:40', 'child_first_name' => 'nullable|string|max:80',
            'child_last_name' => 'nullable|string|max:80', 'child_birth_date' => ['nullable', 'string', self::DATE], 'child_level' => 'nullable|string|max:40',
            ...$this->customRules('lead'), 'duplicate_reason' => 'nullable|string|min:3|max:500'];
    }

    public function key(array $row): string { return trim((string) $row['external_ref']); }

    public function apply(array $row, array $options = []): array
    {
        $current = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND external_ref = ?', [$this->school->id(), $this->key($row)]);
        if ($current) {
            $before = $this->state($current);
            $changes = $this->changes($before, $this->wanted($row, $before));
            if (!$changes['after']) return ['outcome' => 'unchanged', 'target_type' => 'lead', 'target_id' => $current->id, 'previous' => null];
            $this->write($current->id, $changes['after']);
            return ['outcome' => 'updated', 'target_type' => 'lead', 'target_id' => $current->id, 'previous' => $changes];
        }
        $values = $this->wanted($row, $this->state(null));
        if ($values['source'] === null) throw new RowRefused('invalid', __('errors.import_value_invalid', ['column' => 'source']));
        if ($values['site_id'] === null) unset($values['site_id']);
        // The same phone already known: a family coming back, a brother's father.
        $reason = $this->text($row['duplicate_reason'] ?? null);
        $matches = $values['phone'] === null ? [] : app(LeadController::class)->phoneMatches($values['phone']);
        if ($matches && $reason === null) {
            throw new RowRefused('lead_duplicate', __('errors.import_lead_duplicate', ['names' => implode(', ', array_column($matches, 'name'))]));
        }
        DB::select("SELECT set_config('app.reason', ?, true)", [$matches ? $reason : '']);
        $id = DB::table('school.lead')->insertGetId(['tenant_id' => $this->school->id(), 'external_ref' => $this->key($row), ...$this->columns($values)], 'id');
        if (($first = $this->text($row['child_first_name'] ?? null)) !== null) {
            DB::table('school.lead_child')->insert(['tenant_id' => $this->school->id(), 'lead_id' => $id, 'first_name' => $first,
                'last_name' => $this->text($row['child_last_name'] ?? null), 'birth_date' => $this->date($this->text($row['child_birth_date'] ?? null), 'child_birth_date'),
                'level_id' => $this->level($this->text($row['child_level'] ?? null))]);
        }
        DB::select("SELECT set_config('app.reason', '', true)");
        return ['outcome' => 'created', 'target_type' => 'lead', 'target_id' => $id, 'previous' => ['created' => ['lead' => $id], 'after' => $values]];
    }

    public function undo(string $outcome, string $targetId, ?array $previous, array $row): void
    {
        // A prospect corrected on screen since keeps that correction: the import stays.
        $current = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ? FOR UPDATE', [$this->school->id(), $targetId]);
        if (!$current || !$this->holds($this->state($current), $previous['after'])) {
            throw new DependentOperation(0, __('errors.import_record_changed'));
        }
        if ($outcome === 'created') DB::select('SELECT school.discard_lead(?)', [$targetId]);
        else $this->write($targetId, $previous['before']);
    }

    /** The values a prospect holds, as the import compares them. */
    private function state(?object $current): array
    {
        $state = array_fill_keys(self::FIELDS, null);
        if ($current) foreach (self::FIELDS as $field) $state[$field] = $current->$field;
        $custom = $current ? json_decode($current->custom, true, 32, JSON_THROW_ON_ERROR) : [];
        ksort($custom);
        return [...$state, 'site_id' => $current?->site_id, 'custom' => $custom];
    }

    /** The row's columns over the current values, as the database stores them. */
    private function wanted(array $row, array $state): array
    {
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $row)) continue;
            $value = $this->text($row[$field]);
            // A source is the school's code, in small letters (« social_media »); a file may not be.
            $state[$field] = match ($field) {
                'email', 'source', 'relationship' => $value === null ? null : mb_strtolower($value),
                default => $value,
            };
        }
        if (($code = $this->text($row['site'] ?? null)) !== null) $state['site_id'] = $this->site($code);
        $state['custom'] = $this->patched($state['custom'], $this->customValues('lead', $row));
        if ($state['last_name'] === null && $state['first_name'] === null) throw new RowRefused('name_required', __('errors.name_required'));
        if ($state['phone'] === null && $state['email'] === null) throw new RowRefused('invalid', __('errors.import_value_invalid', ['column' => 'phone']));
        return $state;
    }

    /** The level a child aims at, by its code in the referential. */
    private function level(?string $code): ?string
    {
        if ($code === null) return null;
        $code = mb_strtoupper($code);
        return DB::selectOne('SELECT id FROM school.ref_node WHERE tenant_id = ? AND code = ? AND archived_at IS NULL', [$this->school->id(), $code])?->id
            ?? throw new RowRefused('reference_unknown', __('errors.import_node_unknown', ['code' => $code]));
    }

    private function write(string $id, array $values): void
    {
        DB::table('school.lead')->where('tenant_id', $this->school->id())->where('id', $id)->update($this->columns($values));
    }

    private function columns(array $values): array
    {
        if (array_key_exists('custom', $values)) $values['custom'] = json_encode((object) $values['custom'], JSON_THROW_ON_ERROR);
        return $values;
    }
}
