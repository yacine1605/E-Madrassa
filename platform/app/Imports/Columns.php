<?php

namespace App\Imports;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cells of the lot 2 imports as a school's files hold them: dates as `2012-03-14` or `14/03/2012`,
 * yes/no in French, English or Arabic, the school's own fields (FR-REF-04) as `custom_<key>`
 * columns, a pupil by the reference of the pupils' file or by their matricule. Definitions and
 * permissions are read once per import run, by the person who runs it.
 */
trait Columns
{
    private const DATE = 'regex:/^(\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{4})$/';
    // Spellings a cell may hold, not texts for people: "نعم" and "لا" by their code points.
    private const YES_NO = '/^(1|0|oui|non|vrai|faux|true|false|yes|no|\x{0646}\x{0639}\x{0645}|\x{0644}\x{0627})$/iu';
    private const BOOLEAN = 'regex:'.self::YES_NO;
    private const TRUE = ['1', 'oui', 'vrai', 'true', 'yes', "\u{0646}\u{0639}\u{0645}"];

    /** @var array<string, array<string, object>> */
    private array $fields = [];
    private ?bool $sensitive = null;

    private function date(?string $value, string $column): ?string
    {
        if ($value === null) return null;
        $format = str_contains($value, '/') ? '!d/m/Y' : '!Y-m-d';
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
            throw new RowRefused('invalid', __('errors.import_date_invalid', ['column' => $column]));
        }
        return $date->format('Y-m-d');
    }

    private function boolean(?string $value): ?bool
    {
        return $value === null ? null : in_array(mb_strtolower(trim($value)), self::TRUE, true);
    }

    /** A cell's text, trimmed; an empty one is no value. */
    private function text(?string $value): ?string
    {
        return $value === null || ($value = trim($value)) === '' ? null : $value;
    }

    /** @return array<string, object> the school's own fields of this entity, by their column */
    private function customFields(string $entity): array
    {
        return $this->fields[$entity] ??= collect(DB::select('SELECT key, field_type, is_sensitive FROM school.custom_field
            WHERE tenant_id = school.tenant() AND entity = ? AND archived_at IS NULL ORDER BY sort_order, key', [$entity]))
            ->keyBy(fn (object $field) => 'custom_'.$field->key)->all();
    }

    private function customRules(string $entity): array
    {
        return array_fill_keys(array_keys($this->customFields($entity)), 'nullable|string|max:1000');
    }

    /**
     * The school's own values the row brings, by key: null clears one. A sensitive field is
     * written only by whoever may read it (FR-REF-04).
     *
     * @return array<string, mixed>
     */
    private function customValues(string $entity, array $row): array
    {
        $values = [];
        foreach ($this->customFields($entity) as $column => $field) {
            if (!array_key_exists($column, $row)) continue;
            $this->sensitive ??= (bool) DB::selectOne("SELECT school.can_everywhere('student.sensitive.read') AS granted")->granted;
            if ($field->is_sensitive && !$this->sensitive) throw new RowRefused('permission_denied', __('errors.import_column_denied', ['column' => $column]));
            $value = $this->text($row[$column]);
            $values[$field->key] = $value === null ? null : match ($field->field_type) {
                'number' => is_numeric($value) ? $value + 0 : throw new RowRefused('custom_value_invalid', __('errors.import_value_invalid', ['column' => $column])),
                'boolean' => preg_match(self::YES_NO, $value) ? $this->boolean($value)
                    : throw new RowRefused('custom_value_invalid', __('errors.import_value_invalid', ['column' => $column])),
                'date' => $this->date($value, $column),
                default => $value,
            };
        }
        return $values;
    }

    /** The values over the current ones: null removes a value. */
    private function patched(array $current, array $values): array
    {
        foreach ($values as $key => $value) {
            if ($value === null) unset($current[$key]);
            else $current[$key] = $value;
        }
        ksort($current);
        return $current;
    }

    /** A pupil by the reference of the pupils' file, else by matricule (FR-IMP-01: never created by another import). */
    private function pupil(string $reference): object
    {
        return DB::selectOne('SELECT id, status FROM school.student WHERE tenant_id = school.tenant() AND (external_ref = ? OR matricule = ?)
            ORDER BY (external_ref IS NOT DISTINCT FROM ?) DESC LIMIT 1', [$reference, $reference, $reference])
            ?? throw new RowRefused('reference_unknown', __('errors.import_student_unknown', ['reference' => $reference]));
    }

    /** A full name in Arabic or in Latin letters (FR-ELV-01), as the database requires it. */
    private function named(array $values): void
    {
        if (!(($values['last_name_ar'] ?? null) && ($values['first_name_ar'] ?? null)) && !(($values['last_name_lat'] ?? null) && ($values['first_name_lat'] ?? null))) {
            throw new RowRefused('name_required', __('errors.person_name_required'));
        }
    }

    /** Whether a record still holds these values, whatever the order the import's report kept them in. */
    private function holds(array $current, array $values): bool
    {
        $sorted = function (array $array) use (&$sorted) {
            ksort($array);
            return array_map(fn ($value) => is_array($value) ? $sorted($value) : $value, $array);
        };
        return $sorted(array_intersect_key($current, $values)) === $sorted($values);
    }

    /** What changed, and what it was: an update is undone only while it is still in place. */
    private function changes(array $before, array $after): array
    {
        $keys = array_keys(array_filter($after, fn ($value, $key) => $value !== ($before[$key] ?? null), ARRAY_FILTER_USE_BOTH));
        return ['before' => array_intersect_key($before, array_flip($keys)), 'after' => array_intersect_key($after, array_flip($keys))];
    }
}
