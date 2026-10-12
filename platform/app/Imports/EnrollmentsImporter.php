<?php

namespace App\Imports;

use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enrolments (FR-IMP-01, FR-INS-01): a pupil, by the reference of the pupils' file or their
 * matricule, in a programme of a year, by codes; with their class, and validated if the file
 * says so. The database applies the rules of an enrolment made on screen: active programme,
 * pupil neither blocked nor former, a minor's primary contact, the quota, the class's places
 * (BR-07), the price of the day (BR-11). One enrolment per pupil and programme: an existing one
 * is left as it is (BR-05, TST-14); its changes are made on screen.
 */
final class EnrollmentsImporter implements Importer
{
    use Columns;

    private const CURRENT = "SELECT e.id, e.status, (SELECT m.group_id FROM school.group_membership m WHERE m.tenant_id = e.tenant_id
        AND m.enrollment_id = e.id AND m.is_main AND m.valid_to IS NULL) AS group_id FROM school.enrollment e";

    public function __construct(private CurrentSchool $school) {}

    public function permission(): string { return 'enrollment.enrollment.create'; }

    public function rules(): array
    {
        return ['student_ref' => 'required|string|max:100', 'year' => 'required|string|max:40', 'program' => 'required|string|max:30',
            'group' => 'nullable|string|max:30', 'status' => 'nullable|in:pending,validated'];
    }

    public function key(array $row): string { return implode(' / ', array_map(fn ($column) => trim((string) $row[$column]), ['student_ref', 'year', 'program'])); }

    public function apply(array $row, array $options = []): array
    {
        $tenant = $this->school->id();
        $pupil = $this->pupil(trim($row['student_ref']));
        $year = DB::selectOne('SELECT id FROM school.academic_year WHERE tenant_id = ? AND code = ?', [$tenant, trim($row['year'])])?->id
            ?? throw new RowRefused('reference_unknown', __('errors.import_year_unknown', ['code' => trim($row['year'])]));
        $program = DB::selectOne('SELECT id FROM school.program WHERE tenant_id = ? AND year_id = ? AND code = ?', [$tenant, $year, trim($row['program'])])?->id
            ?? throw new RowRefused('reference_unknown', __('errors.import_program_unknown', ['code' => trim($row['program'])]));
        $group = null;
        if (($code = $this->text($row['group'] ?? null)) !== null) {
            $group = DB::selectOne('SELECT id FROM school.study_group WHERE tenant_id = ? AND year_id = ? AND code = ?', [$tenant, $year, $code])?->id
                ?? throw new RowRefused('reference_unknown', __('errors.import_group_unknown', ['code' => $code]));
        }
        $current = DB::selectOne(self::CURRENT." WHERE e.tenant_id = ? AND e.student_id = ? AND e.program_id = ? AND e.status <> 'cancelled'",
            [$tenant, $pupil->id, $program]);
        if ($current) {
            if ($group !== null && $group !== $current->group_id) throw new RowRefused('enrollment_exists', __('errors.import_enrollment_exists'));
            return ['outcome' => 'unchanged', 'target_type' => 'enrollment', 'target_id' => $current->id, 'previous' => null];
        }
        // A key of its own: the database makes one enrolment of it, however often the row runs.
        $id = DB::selectOne('SELECT school.enroll(?, ?, ?) AS id', [$pupil->id, $program, (string) Str::uuid()])->id;
        if ($group !== null) {
            // From today, or from the class's first day when the import comes before it.
            DB::insert('INSERT INTO school.group_membership (tenant_id, enrollment_id, group_id, valid_from)
                SELECT tenant_id, ?, id, greatest(current_date, valid_from) FROM school.study_group WHERE tenant_id = ? AND id = ?', [$id, $tenant, $group]);
        }
        $status = ($row['status'] ?? null) ?: 'pending';
        if ($status === 'validated') {
            DB::select("SELECT set_config('app.reason', ?, true)", [__('services.import_validation')]);
            DB::update("UPDATE school.enrollment SET status = 'validated' WHERE tenant_id = ? AND id = ?", [$tenant, $id]);
            DB::select("SELECT set_config('app.reason', '', true)");
        }
        return ['outcome' => 'created', 'target_type' => 'enrollment', 'target_id' => $id,
            'previous' => ['created' => ['enrollment' => $id], 'status' => $status, 'group_id' => $group]];
    }

    public function undo(string $outcome, string $targetId, ?array $previous, array $row): void
    {
        $current = DB::selectOne(self::CURRENT.' WHERE e.tenant_id = ? AND e.id = ? FOR UPDATE OF e', [$this->school->id(), $targetId]);
        if (!$current || $current->status !== $previous['status'] || $current->group_id !== $previous['group_id']) {
            throw new DependentOperation(0, __('errors.import_record_changed'));
        }
        DB::select('SELECT school.discard_enrollment(?)', [$targetId]);
    }
}
