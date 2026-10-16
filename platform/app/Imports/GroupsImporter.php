<?php

namespace App\Imports;

use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;

/**
 * Groups and classes by their year and code (FR-IMP-01, FR-GRP-01): created with their type,
 * programme and site by codes, places, dates and state, their lead teacher (by the staff's file
 * reference or number) and main room (by its code on the group's site), or updated column by
 * column; a column the file leaves out keeps its value. A group's type, programme, site and first
 * day never change. The database applies the screen's rules: places never below the members
 * (FR-GRP-01 AC2), a lead teacher still with the school, a main room seating the group. Undone
 * while nobody has been placed in it (SQL 045).
 */
final class GroupsImporter implements Importer
{
    use Columns;

    private const FIELDS = ['labels', 'capacity', 'valid_to', 'status', 'lead_teacher_id', 'main_room_id'];
    private const SELECT = 'SELECT id, group_type, program_id, site_id, valid_from, labels, capacity, valid_to, status, lead_teacher_id, main_room_id
        FROM school.study_group';

    public function __construct(private CurrentSchool $school) {}

    public function permission(): string { return 'group.group.manage'; }

    public function rules(): array
    {
        return ['year' => 'required|string|max:40', 'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,30}$/'],
            'group_type' => 'required|in:class,support,option,session', 'program' => 'nullable|string|max:30', 'site' => 'nullable|string|max:40',
            'label_fr' => 'nullable|string|max:150', 'label_ar' => 'nullable|string|max:150', 'capacity' => 'required|integer|min:1',
            'valid_from' => ['nullable', 'string', self::DATE], 'valid_to' => ['nullable', 'string', self::DATE], 'status' => 'nullable|in:draft,active,closed',
            'lead_teacher' => 'nullable|string|max:100', 'main_room' => 'nullable|string|max:20'];
    }

    public function key(array $row): string { return trim((string) $row['year']).' / '.mb_strtoupper(trim((string) $row['code'])); }

    public function apply(array $row, array $options = []): array
    {
        $tenant = $this->school->id();
        $year = DB::selectOne('SELECT id, start_date FROM school.academic_year WHERE tenant_id = ? AND code = ?', [$tenant, trim($row['year'])])
            ?? throw new RowRefused('reference_unknown', __('errors.import_year_unknown', ['code' => trim($row['year'])]));
        $code = mb_strtoupper(trim($row['code']));
        $program = null;
        if (($programCode = $this->text($row['program'] ?? null)) !== null) {
            $programCode = mb_strtoupper($programCode);
            $program = DB::selectOne('SELECT id FROM school.program WHERE tenant_id = ? AND year_id = ? AND code = ?', [$tenant, $year->id, $programCode])?->id
                ?? throw new RowRefused('reference_unknown', __('errors.import_program_unknown', ['code' => $programCode]));
        }
        $site = ($siteCode = $this->text($row['site'] ?? null)) !== null ? $this->site($siteCode)
            : DB::selectOne('SELECT id FROM school.site WHERE tenant_id = ? AND is_default', [$tenant])->id;
        $current = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND year_id = ? AND code = ?', [$tenant, $year->id, $code]);
        if ($current) {
            foreach (['group_type' => $row['group_type'], 'program_id' => $program, 'site_id' => $site] as $column => $value) {
                if ($column === 'program_id' && $value === null) continue;
                if ($column === 'site_id' && $this->text($row['site'] ?? null) === null) continue;
                if ($current->$column !== $value) throw new RowRefused('code_immutable', __('errors.import_code_differs',
                    ['column' => ['group_type' => 'group_type', 'program_id' => 'program', 'site_id' => 'site'][$column]]));
            }
            $before = $this->state($current);
            $changes = $this->changes($before, $this->wanted($row, $before, $current->site_id));
            if (!$changes['after']) return ['outcome' => 'unchanged', 'target_type' => 'study_group', 'target_id' => $current->id, 'previous' => null];
            $this->write($current->id, $changes['after']);
            return ['outcome' => 'updated', 'target_type' => 'study_group', 'target_id' => $current->id, 'previous' => $changes];
        }
        $values = $this->wanted($row, $this->state(null), $site);
        if ($values['labels'] === []) throw new RowRefused('name_required', __('errors.import_value_invalid', ['column' => 'label_fr']));
        $from = $this->date($this->text($row['valid_from'] ?? null), 'valid_from') ?? $year->start_date;
        $id = DB::table('school.study_group')->insertGetId(['tenant_id' => $tenant, 'year_id' => $year->id, 'code' => $code, 'group_type' => $row['group_type'],
            'program_id' => $program, 'site_id' => $site, 'valid_from' => $from, ...$this->columns(array_filter($values, fn ($value) => $value !== null))], 'id');
        // As the database wrote it, its defaults included: what an undo finds unchanged.
        $values = $this->state(DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?', [$tenant, $id]));
        return ['outcome' => 'created', 'target_type' => 'study_group', 'target_id' => $id, 'previous' => ['created' => ['study_group' => $id], 'after' => $values]];
    }

    public function undo(string $outcome, string $targetId, ?array $previous, array $row): void
    {
        $current = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ?', [$this->school->id(), $targetId]);
        if (!$current || !$this->holds($this->state($current), $previous['after'])) throw new DependentOperation(0, __('errors.import_record_changed'));
        if ($outcome === 'created') DB::select('SELECT school.discard_group(?)', [$targetId]);
        else $this->write($targetId, $previous['before']);
    }

    private function state(?object $current): array
    {
        $state = array_fill_keys(self::FIELDS, null);
        if ($current) foreach (self::FIELDS as $field) $state[$field] = $current->$field;
        $state['labels'] = $current ? json_decode($current->labels, true, 4, JSON_THROW_ON_ERROR) : [];
        ksort($state['labels']);
        if ($state['capacity'] !== null) $state['capacity'] = (int) $state['capacity'];
        return $state;
    }

    private function wanted(array $row, array $state, string $site): array
    {
        foreach (['fr', 'ar'] as $language) {
            if (!array_key_exists("label_$language", $row)) continue;
            $label = $this->text($row["label_$language"]);
            if ($label === null) unset($state['labels'][$language]);
            else $state['labels'][$language] = $label;
        }
        ksort($state['labels']);
        $state['capacity'] = (int) $row['capacity'];
        if (array_key_exists('valid_to', $row)) $state['valid_to'] = $this->date($this->text($row['valid_to']), 'valid_to');
        if (($status = $this->text($row['status'] ?? null)) !== null) $state['status'] = $status;
        if (array_key_exists('lead_teacher', $row)) $state['lead_teacher_id'] = $this->teacher($this->text($row['lead_teacher']));
        if (array_key_exists('main_room', $row)) $state['main_room_id'] = $this->room($this->text($row['main_room']), $site);
        return $state;
    }

    /** A member of staff by the reference of the staff's file, else by their number. */
    private function teacher(?string $reference): ?string
    {
        if ($reference === null) return null;
        return DB::selectOne('SELECT id FROM school.staff WHERE tenant_id = ? AND (external_ref = ? OR staff_number = ?)
            ORDER BY (external_ref IS NOT DISTINCT FROM ?) DESC LIMIT 1', [$this->school->id(), $reference, $reference, $reference])?->id
            ?? throw new RowRefused('reference_unknown', __('errors.import_teacher_unknown', ['reference' => $reference]));
    }

    /** A room of the group's site, by its code. */
    private function room(?string $code, string $site): ?string
    {
        if ($code === null) return null;
        return DB::selectOne('SELECT id FROM school.room WHERE tenant_id = ? AND site_id = ? AND code = ?', [$this->school->id(), $site, $code])?->id
            ?? throw new RowRefused('reference_unknown', __('errors.import_room_unknown', ['code' => $code]));
    }

    private function write(string $id, array $values): void
    {
        DB::table('school.study_group')->where('tenant_id', $this->school->id())->where('id', $id)->update($this->columns($values));
    }

    private function columns(array $values): array
    {
        if (array_key_exists('labels', $values)) $values['labels'] = json_encode((object) $values['labels'], JSON_THROW_ON_ERROR);
        return $values;
    }
}
