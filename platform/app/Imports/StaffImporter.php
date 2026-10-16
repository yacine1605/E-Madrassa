<?php

namespace App\Imports;

use App\Http\Controllers\StaffController;
use App\Support\CurrentSchool;
use App\Support\PupilRecords;
use Illuminate\Support\Facades\DB;

/**
 * Members of staff by the reference of the school's file (FR-IMP-01, FR-STF-01): created with
 * their number, given or attributed, or updated column by column; a column the file leaves out
 * keeps its value. Recorded on the site the `site` column names by its code, else the school's
 * default site. Active when created; a departure is recorded on screen. Undone while nothing
 * depends on them (SQL 036).
 */
final class StaffImporter implements Importer
{
    use Columns;

    private const FIELDS = [...PupilRecords::NAMES, 'staff_type', 'phone', 'email', 'hire_date'];
    private const SELECT = 'SELECT id, staff_number, last_name_ar, first_name_ar, last_name_lat, first_name_lat, staff_type, phone, email, hire_date,
        custom, site_id FROM school.staff';

    public function __construct(private CurrentSchool $school) {}

    public function permission(): string { return 'staff.profile.create'; }

    public function rules(): array
    {
        return ['external_ref' => 'required|string|max:100', 'staff_number' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,30}$/'],
            ...array_fill_keys(PupilRecords::NAMES, 'nullable|string|max:80'), 'staff_type' => 'required|in:'.implode(',', StaffController::TYPES),
            'phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:200', 'hire_date' => ['nullable', 'string', self::DATE],
            'site' => 'nullable|string|max:40', ...$this->customRules('staff')];
    }

    public function key(array $row): string { return trim((string) $row['external_ref']); }

    public function apply(array $row, array $options = []): array
    {
        $number = $this->text($row['staff_number'] ?? null);
        $current = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND external_ref = ?', [$this->school->id(), $this->key($row)]);
        if ($current) {
            if ($number !== null && $number !== $current->staff_number) {
                throw new RowRefused('code_immutable', __('errors.import_staff_number_differs', ['number' => $current->staff_number]));
            }
            $before = $this->state($current);
            $changes = $this->changes($before, $this->wanted($row, $before));
            if (!$changes['after']) return ['outcome' => 'unchanged', 'target_type' => 'staff', 'target_id' => $current->id, 'previous' => null];
            $this->write($current->id, $changes['after']);
            return ['outcome' => 'updated', 'target_type' => 'staff', 'target_id' => $current->id, 'previous' => $changes];
        }
        $values = $this->wanted($row, $this->state(null));
        // Without a site, the database's default: the school's default site.
        if ($values['site_id'] === null) unset($values['site_id']);
        if ($number !== null && DB::selectOne('SELECT 1 FROM school.staff WHERE tenant_id = ? AND staff_number = ?', [$this->school->id(), $number])) {
            throw new RowRefused('duplicate', __('errors.import_staff_number_taken', ['number' => $number]));
        }
        $id = DB::table('school.staff')->insertGetId(['tenant_id' => $this->school->id(), 'external_ref' => $this->key($row),
            ...($number === null ? [] : ['staff_number' => $number]), ...$this->columns($values)], 'id');
        return ['outcome' => 'created', 'target_type' => 'staff', 'target_id' => $id, 'previous' => ['created' => ['staff' => $id], 'after' => $values]];
    }

    public function undo(string $outcome, string $targetId, ?array $previous, array $row): void
    {
        // A record corrected on screen since keeps that correction: the import stays.
        $current = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ? FOR UPDATE', [$this->school->id(), $targetId]);
        if (!$current || !$this->holds($this->state($current), $previous['after'])) {
            throw new DependentOperation(0, __('errors.import_record_changed'));
        }
        if ($outcome === 'created') DB::select('SELECT school.discard_staff(?)', [$targetId]);
        else $this->write($targetId, $previous['before']);
    }

    /** The values a member holds, as the import compares them. */
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
            $state[$field] = match ($field) {
                'hire_date' => $this->date($value, $field),
                'email' => $value === null ? null : mb_strtolower($value),
                default => $value,
            };
        }
        if (($code = $this->text($row['site'] ?? null)) !== null) $state['site_id'] = $this->site($code);
        $state['custom'] = $this->patched($state['custom'], $this->customValues('staff', $row));
        $this->named($state);
        return $state;
    }

    private function write(string $id, array $values): void
    {
        DB::table('school.staff')->where('tenant_id', $this->school->id())->where('id', $id)->update($this->columns($values));
    }

    private function columns(array $values): array
    {
        if (array_key_exists('custom', $values)) $values['custom'] = json_encode((object) $values['custom'], JSON_THROW_ON_ERROR);
        return $values;
    }
}
