<?php

namespace App\Imports;

use App\Support\CurrentSchool;
use App\Support\PupilRecords;
use Illuminate\Support\Facades\DB;

/**
 * Pupils by the reference of the school's file (FR-IMP-01, FR-ELV-01): created with their
 * matricule, given or generated, or updated column by column; a column the file leaves out keeps
 * its value. A probable duplicate (same name and birth date, FR-ELV-04) is refused unless the row
 * gives the reason to create it anyway, which the journal keeps. Undone while nothing depends on it.
 */
final class StudentsImporter implements Importer
{
    use Columns;

    private const FIELDS = [...PupilRecords::NAMES, 'birth_date', 'birth_place', 'gender', 'phone', 'email', 'address', 'origin_school'];
    private const SELECT = 'SELECT id, matricule, last_name_ar, first_name_ar, last_name_lat, first_name_lat, birth_date, birth_place, gender,
        phone, email, address, origin_school, custom FROM school.student';

    public function __construct(private CurrentSchool $school, private PupilRecords $records) {}

    public function permission(): string { return 'student.record.create'; }

    public function rules(): array
    {
        return ['external_ref' => 'required|string|max:100', 'matricule' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,30}$/'],
            ...array_fill_keys(PupilRecords::NAMES, 'nullable|string|max:80'), 'birth_date' => ['required', 'string', self::DATE],
            'birth_place' => 'nullable|string|max:120', 'gender' => ['nullable', 'regex:/^[mfMF]$/'], 'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:200', 'address' => 'nullable|string|max:300', 'origin_school' => 'nullable|string|max:200',
            ...$this->customRules('student'), 'duplicate_reason' => 'nullable|string|min:3|max:500'];
    }

    public function key(array $row): string { return trim((string) $row['external_ref']); }

    public function apply(array $row, array $options = []): array
    {
        $matricule = $this->text($row['matricule'] ?? null);
        $current = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND external_ref = ?', [$this->school->id(), $this->key($row)]);
        if ($current) {
            if ($matricule !== null && $matricule !== $current->matricule) {
                throw new RowRefused('code_immutable', __('errors.import_matricule_differs', ['matricule' => $current->matricule]));
            }
            $before = $this->state($current);
            $after = $this->wanted($row, $before);
            $changes = $this->changes($before, $after);
            if (!$changes['after']) return ['outcome' => 'unchanged', 'target_type' => 'student', 'target_id' => $current->id, 'previous' => null];
            $this->write($current->id, $changes['after']);
            return ['outcome' => 'updated', 'target_type' => 'student', 'target_id' => $current->id, 'previous' => $changes];
        }
        $values = $this->wanted($row, $this->state(null));
        if ($matricule !== null && DB::selectOne('SELECT 1 FROM school.student WHERE tenant_id = ? AND matricule = ?', [$this->school->id(), $matricule])) {
            throw new RowRefused('duplicate', __('errors.import_matricule_taken', ['matricule' => $matricule]));
        }
        // As on screen: the warning and the write together, against simultaneous admissions too.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 31))', [$this->school->id()]);
        $matches = $this->records->duplicates([...$values, 'phone' => null]);
        $reason = $this->text($row['duplicate_reason'] ?? null);
        if ($matches && $reason === null) {
            throw new RowRefused('student_duplicate', __('errors.import_student_duplicate', ['matricules' => implode(', ', array_column($matches, 'matricule'))]));
        }
        DB::select("SELECT set_config('app.reason', ?, true)", [$matches ? $reason : '']);
        $id = DB::table('school.student')->insertGetId(['tenant_id' => $this->school->id(), 'external_ref' => $this->key($row),
            ...($matricule === null ? [] : ['matricule' => $matricule]), ...$this->columns($values)], 'id');
        // The reason belongs to this pupil alone, not to the rows imported after it.
        DB::select("SELECT set_config('app.reason', '', true)");
        return ['outcome' => 'created', 'target_type' => 'student', 'target_id' => $id, 'previous' => ['created' => ['student' => $id], 'after' => $values]];
    }

    public function undo(string $outcome, string $targetId, ?array $previous, array $row): void
    {
        // A record corrected on screen since keeps that correction: the import stays.
        $current = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ? FOR UPDATE', [$this->school->id(), $targetId]);
        if (!$current || !$this->holds($this->state($current), $previous['after'])) {
            throw new DependentOperation(0, __('errors.import_record_changed'));
        }
        if ($outcome === 'created') DB::select('SELECT school.discard_student(?)', [$targetId]);
        else $this->write($targetId, $previous['before']);
    }

    /** The values a pupil holds, as the import compares them. */
    private function state(?object $current): array
    {
        $state = array_fill_keys(self::FIELDS, null);
        if ($current) foreach (self::FIELDS as $field) $state[$field] = $current->$field;
        $custom = $current ? json_decode($current->custom, true, 32, JSON_THROW_ON_ERROR) : [];
        ksort($custom);
        return [...$state, 'custom' => $custom];
    }

    /** The row's columns over the current values, as the database stores them. */
    private function wanted(array $row, array $state): array
    {
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $row)) continue;
            $value = $this->text($row[$field]);
            $state[$field] = match ($field) {
                'birth_date' => $this->date($value, $field),
                'email', 'gender' => $value === null ? null : mb_strtolower($value),
                default => $value,
            };
        }
        $state['custom'] = $this->patched($state['custom'], $this->customValues('student', $row));
        $this->named($state);
        return $state;
    }

    private function write(string $id, array $values): void
    {
        DB::table('school.student')->where('tenant_id', $this->school->id())->where('id', $id)->update($this->columns($values));
    }

    private function columns(array $values): array
    {
        if (array_key_exists('custom', $values)) $values['custom'] = json_encode((object) $values['custom'], JSON_THROW_ON_ERROR);
        return $values;
    }
}
