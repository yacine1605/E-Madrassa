<?php

namespace App\Imports;

use App\Support\CurrentSchool;
use App\Support\Formats;
use Illuminate\Support\Facades\DB;

/**
 * A teacher's qualifications (FR-IMP-01, FR-STF-02): a row per teacher, subject and node of the
 * referential, from a day until another. The teacher is found by the reference of the staff's
 * file or their number, the subject and the node by their codes: an unknown one is refused, never
 * created. A line already there is left as it is, whatever day the file is imported again; one the
 * file gives another end is refused, its end being final and changed on screen. Undone while the
 * line is as the import left it.
 */
final class QualificationsImporter implements Importer
{
    use Columns;

    private const SELECT = 'SELECT id, valid_from, valid_to FROM school.qualification';

    public function __construct(private CurrentSchool $school) {}

    public function permission(): string { return 'staff.qualification.manage'; }

    public function rules(): array
    {
        return ['staff_ref' => 'required|string|max:100', 'subject' => 'required|string|max:40', 'node' => 'required|string|max:40',
            'valid_from' => ['nullable', 'string', self::DATE], 'valid_to' => ['nullable', 'string', self::DATE]];
    }

    public function key(array $row): string
    {
        return implode(' / ', array_filter([trim((string) $row['staff_ref']), mb_strtoupper(trim((string) $row['subject'])), mb_strtoupper(trim((string) $row['node'])),
            $this->text($row['valid_from'] ?? null)]));
    }

    public function apply(array $row, array $options = []): array
    {
        $tenant = $this->school->id();
        $staff = $this->member(trim($row['staff_ref']));
        // Codes are written in capitals (« 1AS », « MATH »); a file may not be.
        [$subjectCode, $nodeCode] = [mb_strtoupper(trim($row['subject'])), mb_strtoupper(trim($row['node']))];
        $subject = DB::selectOne('SELECT id FROM school.subject WHERE tenant_id = ? AND code = ?', [$tenant, $subjectCode])?->id
            ?? throw new RowRefused('reference_unknown', __('errors.import_subject_unknown', ['code' => $subjectCode]));
        $node = DB::selectOne('SELECT id FROM school.ref_node WHERE tenant_id = ? AND code = ?', [$tenant, $nodeCode])?->id
            ?? throw new RowRefused('reference_unknown', __('errors.import_node_unknown', ['code' => $nodeCode]));
        $from = $this->date($this->text($row['valid_from'] ?? null), 'valid_from');
        $until = $this->date($this->text($row['valid_to'] ?? null), 'valid_to');
        // The line the file names: the one of that first day, or without one the line in force or to come.
        $line = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND staff_id = ? AND subject_id = ? AND node_id = ?
            AND '.($from === null ? '(valid_to IS NULL OR valid_to >= current_date)' : 'valid_from = ?::date').' ORDER BY valid_from DESC LIMIT 1',
            [$tenant, $staff, $subject, $node, ...($from === null ? [] : [$from])]);
        if ($line) {
            if ($until !== null && $until !== $line->valid_to) {
                throw new RowRefused('qualification_differs', __('errors.import_qualification_differs', ['until' => $line->valid_to === null ? __('staff.contract_open') : Formats::date($line->valid_to)]));
            }
            return ['outcome' => 'unchanged', 'target_type' => 'qualification', 'target_id' => $line->id, 'previous' => null];
        }
        $id = DB::table('school.qualification')->insertGetId(['tenant_id' => $tenant, 'staff_id' => $staff, 'subject_id' => $subject, 'node_id' => $node,
            ...($from === null ? [] : ['valid_from' => $from]), 'valid_to' => $until], 'id');
        return ['outcome' => 'created', 'target_type' => 'qualification', 'target_id' => $id,
            'previous' => ['created' => ['qualification' => $id], 'after' => ['valid_to' => $until]]];
    }

    /** A line still as the import left it is removed; one ended on screen since stays, and the import with it. */
    public function undo(string $outcome, string $targetId, ?array $previous, array $row): void
    {
        $line = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ? FOR UPDATE', [$this->school->id(), $targetId]);
        if (!$line || $line->valid_to !== $previous['after']['valid_to']) throw new DependentOperation(0, __('errors.import_record_changed'));
        DB::table('school.qualification')->where('tenant_id', $this->school->id())->where('id', $targetId)->delete();
    }

    /** A member of staff by the reference of the staff's file, else by their number: never created here. */
    private function member(string $reference): string
    {
        return DB::selectOne('SELECT id FROM school.staff WHERE tenant_id = school.tenant() AND (external_ref = ? OR staff_number = ?)
            ORDER BY (external_ref IS NOT DISTINCT FROM ?) DESC LIMIT 1', [$reference, $reference, $reference])?->id
            ?? throw new RowRefused('reference_unknown', __('errors.import_staff_unknown', ['reference' => $reference]));
    }
}
