<?php

namespace App\Imports;

use App\Support\CurrentSchool;
use App\Support\PupilRecords;
use Illuminate\Support\Facades\DB;

/**
 * Guardians and their link to a pupil (FR-IMP-01, FR-ELV-02): a row per guardian and pupil. The
 * guardian is found by the reference of the school's file, so that brothers and sisters share
 * one record (AC1); the pupil, by the reference of the pupils' file or their matricule, is never
 * created here. Undone link first, then the guardian once no pupil is linked to them.
 */
final class GuardiansImporter implements Importer
{
    use Columns;

    private const FIELDS = [...PupilRecords::NAMES, 'phone', 'email', 'profession'];
    private const FLAGS = ['is_primary_contact', 'is_payer', 'emergency_contact'];
    private const SELECT = 'SELECT id, last_name_ar, first_name_ar, last_name_lat, first_name_lat, phone, email, profession, custom FROM school.guardian';
    private const LINK = 'SELECT id, relationship, is_primary_contact, is_payer, emergency_contact FROM school.student_guardian';

    public function __construct(private CurrentSchool $school, private PupilRecords $records) {}

    public function permission(): string { return 'guardian.record.create'; }

    public function rules(): array
    {
        return ['external_ref' => 'required|string|max:100', 'student_ref' => 'required|string|max:100',
            'relationship' => 'required|in:father,mother,guardian,other', ...array_fill_keys(PupilRecords::NAMES, 'nullable|string|max:80'),
            'phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:200', 'profession' => 'nullable|string|max:120',
            ...array_fill_keys(self::FLAGS, ['nullable', self::BOOLEAN]), ...$this->customRules('guardian'),
            'duplicate_reason' => 'nullable|string|min:3|max:500'];
    }

    public function key(array $row): string { return trim((string) $row['external_ref']).' / '.trim((string) $row['student_ref']); }

    public function apply(array $row, array $options = []): array
    {
        $tenant = $this->school->id();
        $pupil = $this->pupil(trim($row['student_ref']));
        // What the row created or changed, with its values before and after: an undo puts back
        // the first while the record still holds the second.
        $previous = ['created' => []];
        $guardian = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND external_ref = ?', [$tenant, trim($row['external_ref'])]);
        if ($guardian) {
            $changes = $this->changes($this->state($guardian), $this->wanted($row, $this->state($guardian)));
            if ($changes['after']) {
                $this->write('guardian', $guardian->id, $changes['after']);
                $previous['guardian'] = $changes;
            }
            $id = $guardian->id;
        } else {
            $values = $this->wanted($row, $this->state(null));
            // The same person under another reference (same phone or e-mail, same name): refused, as
            // on screen, unless the row says why; brothers and sisters share the first one's reference.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 32))', [$tenant]);
            $matches = $this->records->guardianDuplicates($values);
            $reason = $this->text($row['duplicate_reason'] ?? null);
            if ($matches && $reason === null) {
                throw new RowRefused('guardian_duplicate', __('errors.import_guardian_duplicate', ['guardians' => implode(', ', array_map(
                    fn (array $match) => trim(implode(' ', array_filter([$match['last_name_lat'] ?? $match['last_name_ar'], $match['first_name_lat'] ?? $match['first_name_ar']])))
                        .($match['external_ref'] ? ' ('.$match['external_ref'].')' : ''), $matches))]));
            }
            DB::select("SELECT set_config('app.reason', ?, true)", [$matches ? $reason : '']);
            $id = DB::table('school.guardian')->insertGetId(['tenant_id' => $tenant, 'external_ref' => trim($row['external_ref']), ...$this->columns($values)], 'id');
            DB::select("SELECT set_config('app.reason', '', true)");
            $previous['created']['guardian'] = $id;
            $previous['guardian'] = ['before' => null, 'after' => $values];
        }
        $link = DB::selectOne(self::LINK.' WHERE tenant_id = ? AND student_id = ? AND guardian_id = ? AND archived_at IS NULL', [$tenant, $pupil->id, $id]);
        if ($link) {
            $changes = $this->changes((array) $link, $this->flags($row, (array) $link));
            if ($changes['after']) {
                $this->write('student_guardian', $link->id, $changes['after']);
                $previous['link'] = $changes;
            }
            $linkId = $link->id;
        } else {
            $flags = $this->flags($row, array_fill_keys(self::FLAGS, false));
            $linkId = DB::table('school.student_guardian')->insertGetId(['tenant_id' => $tenant, 'student_id' => $pupil->id, 'guardian_id' => $id, ...$flags], 'id');
            $previous['created']['student_guardian'] = $linkId;
            $previous['link'] = ['before' => null, 'after' => $flags];
        }
        $outcome = $previous['created'] ? 'created' : (isset($previous['guardian']) || isset($previous['link']) ? 'updated' : 'unchanged');
        return ['outcome' => $outcome, 'target_type' => 'student_guardian', 'target_id' => $linkId,
            'previous' => $outcome === 'unchanged' ? null : [...$previous, 'guardian_id' => $id]];
    }

    /** The link first, then the guardian, each only while it is as the import left it. */
    public function undo(string $outcome, string $targetId, ?array $previous, array $row): void
    {
        $tenant = $this->school->id();
        if (isset($previous['link'])) {
            $link = DB::selectOne(self::LINK.' WHERE tenant_id = ? AND id = ? AND archived_at IS NULL FOR UPDATE', [$tenant, $targetId]);
            if (!$link || !$this->holds((array) $link, $previous['link']['after'])) throw new DependentOperation(0, __('errors.import_record_changed'));
            if (isset($previous['created']['student_guardian'])) DB::select('SELECT school.discard_guardian_link(?)', [$targetId]);
            else $this->write('student_guardian', $targetId, $previous['link']['before']);
        }
        if (isset($previous['guardian'])) {
            $guardian = DB::selectOne(self::SELECT.' WHERE tenant_id = ? AND id = ? FOR UPDATE', [$tenant, $previous['guardian_id']]);
            if (!$guardian || !$this->holds($this->state($guardian), $previous['guardian']['after'])) throw new DependentOperation(0, __('errors.import_record_changed'));
            if (isset($previous['created']['guardian'])) DB::select('SELECT school.discard_guardian(?)', [$previous['guardian_id']]);
            else $this->write('guardian', $previous['guardian_id'], $previous['guardian']['before']);
        }
    }

    private function state(?object $current): array
    {
        $state = array_fill_keys(self::FIELDS, null);
        if ($current) foreach (self::FIELDS as $field) $state[$field] = $current->$field;
        $custom = $current ? json_decode($current->custom, true, 32, JSON_THROW_ON_ERROR) : [];
        ksort($custom);
        return [...$state, 'custom' => $custom];
    }

    private function wanted(array $row, array $state): array
    {
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $row)) continue;
            $value = $this->text($row[$field]);
            $state[$field] = $field === 'email' && $value !== null ? mb_strtolower($value) : $value;
        }
        $state['custom'] = $this->patched($state['custom'], $this->customValues('guardian', $row));
        $this->named($state);
        return $state;
    }

    /** The link's relationship and flags: a flag the file leaves out or leaves empty keeps its value. */
    private function flags(array $row, array $current): array
    {
        $flags = ['relationship' => $row['relationship']];
        foreach (self::FLAGS as $flag) $flags[$flag] = $this->boolean($row[$flag] ?? null) ?? (bool) $current[$flag];
        return $flags;
    }

    private function write(string $table, string $id, array $values): void
    {
        DB::table('school.'.$table)->where('tenant_id', $this->school->id())->where('id', $id)->update($this->columns($values));
    }

    private function columns(array $values): array
    {
        if (array_key_exists('custom', $values)) $values['custom'] = json_encode((object) $values['custom'], JSON_THROW_ON_ERROR);
        return $values;
    }
}
