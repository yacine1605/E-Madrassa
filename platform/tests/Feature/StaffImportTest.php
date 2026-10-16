<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The staff's import (FR-IMP-01, FR-STF-01, FR-STF-02, SQL 036): members of staff and their
 * qualifications from the school's files, through the import engine's preview, confirmation and
 * undo, under the rules the screens apply.
 */
final class StaffImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
    }

    /** @return array{0: object, 1: array} a school with the staff module and an annex, its pedagogy and HR manager signed in */
    private function manager(): array
    {
        $manager = $this->account('staff-import');
        $school = $this->school('pro', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $this->postJson('/api/sites', ['code' => 'ANNEXE', 'labels' => ['fr' => 'Annexe']])->assertCreated();
        $this->postJson('/api/subjects', ['code' => 'ROBOTIQUE', 'kind' => 'subject', 'labels' => ['fr' => 'Robotique']])->assertCreated();
        return [$school, $manager];
    }

    /** @param list<array<string, ?string>> $rows a CSV file as a spreadsheet saves it: the columns' codes, then the rows */
    private function import(string $kind, array $rows): array
    {
        $lines = [implode(';', array_keys($rows[0])), ...array_map(fn (array $row) => implode(';', array_map(fn ($value) => (string) $value, $row)), $rows)];
        $batch = $this->post('/api/imports', ['kind' => $kind, 'file' => UploadedFile::fake()->createWithContent($kind.'.csv', "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n")],
            ['Accept' => 'application/json'])->assertStatus(202)->json('data.batch.id');
        return $this->getJson("/api/imports/$batch")->assertOk()->assertJsonPath('data.status', 'previewed')->json('data');
    }

    private function confirmed(array $batch): array
    {
        $this->postJson('/api/imports/'.$batch['id'].'/confirm')->assertStatus(202);
        return $this->getJson('/api/imports/'.$batch['id'])->assertOk()->assertJsonPath('data.status', 'imported')->json('data');
    }

    /** @return list<array{int, string}> each refused line with its code */
    private function refused(array $batch): array
    {
        return array_map(fn (array $row) => [$row['line'], $row['error_code']], $this->getJson('/api/imports/'.$batch['id'].'/rows?outcome=rejected')->json('data'));
    }

    private function staff(object $school, string $reference): ?object
    {
        return DB::connection('owner')->table('school.staff')->where('tenant_id', $school->id)->where('external_ref', $reference)->first();
    }

    /** The school's first level of the referential, by its code. */
    private function level(object $school): string
    {
        return DB::connection('owner')->selectOne("SELECT code FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' ORDER BY code LIMIT 1", [$school->id])->code;
    }

    private function file(): array
    {
        return [
            ['external_ref' => 'RH-001', 'staff_number' => 'ENS-01', 'last_name_lat' => 'Meziane', 'first_name_lat' => 'Karim', 'last_name_ar' => 'مزيان',
                'first_name_ar' => 'كريم', 'staff_type' => 'teacher_internal', 'phone' => '0550 98 76 54', 'email' => 'K.Meziane@Example.test',
                'hire_date' => '01/09/2020', 'site' => 'ANNEXE'],
            ['external_ref' => 'RH-002', 'staff_number' => '', 'last_name_lat' => 'Bouzid', 'first_name_lat' => 'Salima', 'last_name_ar' => '',
                'first_name_ar' => '', 'staff_type' => 'teacher_external', 'phone' => '', 'email' => '', 'hire_date' => '2024-02-15', 'site' => ''],
            ['external_ref' => 'RH-003', 'staff_number' => '', 'last_name_lat' => 'Kaci', 'first_name_lat' => 'Amel', 'last_name_ar' => '',
                'first_name_ar' => '', 'staff_type' => 'administrative', 'phone' => '', 'email' => '', 'hire_date' => '', 'site' => ''],
        ];
    }

    public function test_the_staff_come_from_their_file_once_and_their_qualifications_by_codes(): void
    {
        [$school] = $this->manager();
        $this->assertStringStartsWith("\xEF\xBB\xBFexternal_ref;staff_number;last_name_ar;first_name_ar;last_name_lat;first_name_lat;staff_type;phone;email;hire_date;site",
            $this->get('/api/imports/templates/staff')->assertOk()->getContent());
        $preview = $this->import('staff', $this->file());
        $this->assertSame([3, 0], [$preview['counts']['create'], DB::connection('owner')->table('school.staff')->where('tenant_id', $school->id)->count()]);
        $this->assertSame(3, $this->confirmed($preview)['counts']['created']);
        // A number given is kept, another attributed; the annex named, else the default site; the e-mail as the screen stores it.
        $first = $this->staff($school, 'RH-001');
        $annex = DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->where('code', 'ANNEXE')->value('id');
        $default = DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
        $this->assertEquals(['ENS-01', '2020-09-01', $annex, 'k.meziane@example.test', 'active'],
            [$first->staff_number, $first->hire_date, $first->site_id, $first->email, $first->status]);
        $this->assertEquals(['P0001', $default], [$this->staff($school, 'RH-002')->staff_number, $this->staff($school, 'RH-002')->site_id]);
        // The same file again changes nothing (BR-05); a changed cell updates that member only.
        $this->assertSame(3, $this->import('staff', $this->file())['counts']['unchanged']);
        $file = $this->file();
        $file[1]['phone'] = '0661 22 33 44';
        $counts = $this->confirmed($this->import('staff', $file))['counts'];
        $this->assertSame([1, 2], [$counts['updated'], $counts['unchanged']]);
        $this->assertSame('0661 22 33 44', $this->staff($school, 'RH-002')->phone);

        // Qualifications: the teacher by the staff's reference or number, the subject and the node by their codes.
        $level = $this->level($school);
        $lines = [['staff_ref' => 'RH-001', 'subject' => 'robotique', 'node' => $level, 'valid_from' => '01/09/2024', 'valid_to' => ''],
            ['staff_ref' => 'P0001', 'subject' => 'ROBOTIQUE', 'node' => $level, 'valid_from' => '', 'valid_to' => '']];
        $this->assertSame(2, $this->confirmed($this->import('qualifications', $lines))['counts']['created']);
        $this->getJson('/api/staff/'.$first->id.'/qualifications')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.valid_from', '2024-09-01');
        // Imported again, another day or not, nothing is added.
        $this->assertSame(2, $this->import('qualifications', $lines)['counts']['unchanged']);
    }

    public function test_each_refused_row_is_named_with_its_line_and_cause(): void
    {
        [$school] = $this->manager();
        $this->confirmed($this->import('staff', $this->file()));
        $row = fn (string $reference, array $values) => [...$this->file()[0], 'external_ref' => $reference, 'staff_number' => '', 'site' => '', ...$values];
        // A wrong function, an impossible date, no full name, an unknown site, a number already given, a number changed, a line twice.
        $this->assertSame([[2, 'invalid'], [3, 'invalid'], [4, 'name_required'], [5, 'reference_unknown'], [6, 'duplicate'], [7, 'code_immutable'],
            [8, 'duplicate_in_file']], $this->refused($this->import('staff', [
                $row('RH-10', ['staff_type' => 'professeur']), $row('RH-11', ['hire_date' => '31/02/2020']), $row('RH-12', ['last_name_lat' => '', 'last_name_ar' => '']),
                $row('RH-13', ['site' => 'NULLE-PART']), $row('RH-14', ['staff_number' => 'ENS-01']), [...$this->file()[0], 'staff_number' => 'ENS-99'],
                $this->file()[0]])));
        // A member who left receives no qualification; an unknown teacher, subject or level is refused, never created.
        $left = $this->staff($school, 'RH-002');
        $this->postJson("/api/staff/{$left->id}/status", ['status' => 'left', 'version' => $left->version, 'reason' => 'Fin de mission'])->assertOk();
        $level = $this->level($school);
        $batch = $this->import('qualifications', [['staff_ref' => 'RH-002', 'subject' => 'ROBOTIQUE', 'node' => $level, 'valid_from' => '', 'valid_to' => ''],
            ['staff_ref' => 'RH-404', 'subject' => 'ROBOTIQUE', 'node' => $level, 'valid_from' => '', 'valid_to' => ''],
            ['staff_ref' => 'RH-001', 'subject' => 'CHIMIE', 'node' => $level, 'valid_from' => '', 'valid_to' => ''],
            ['staff_ref' => 'RH-001', 'subject' => 'ROBOTIQUE', 'node' => 'NULLE', 'valid_from' => '', 'valid_to' => ''],
            ['staff_ref' => 'RH-003', 'subject' => 'ROBOTIQUE', 'node' => $level, 'valid_from' => '', 'valid_to' => '']]);
        $this->assertSame([[2, 'staff_left'], [3, 'reference_unknown'], [4, 'reference_unknown'], [5, 'reference_unknown'], [6, 'qualification_not_teacher']],
            $this->refused($batch));
        $this->assertSame(__('errors.import_subject_unknown', ['code' => 'CHIMIE']), $this->getJson('/api/imports/'.$batch['id'].'/rows?outcome=rejected')->json('data.2.error_message'));
        // A line already there keeps its end: the file cannot change it.
        $this->confirmed($this->import('qualifications', [['staff_ref' => 'RH-001', 'subject' => 'ROBOTIQUE', 'node' => $level, 'valid_from' => '', 'valid_to' => '']]));
        $this->assertSame([[2, 'qualification_differs']], $this->refused($this->import('qualifications',
            [['staff_ref' => 'RH-001', 'subject' => 'ROBOTIQUE', 'node' => $level, 'valid_from' => '', 'valid_to' => now()->addYear()->toDateString()]])));
    }

    public function test_an_import_is_undone_last_first_while_nothing_depends_on_it(): void
    {
        [$school] = $this->manager();
        $staff = $this->confirmed($this->import('staff', $this->file()));
        $qualifications = $this->confirmed($this->import('qualifications',
            [['staff_ref' => 'RH-001', 'subject' => 'ROBOTIQUE', 'node' => $this->level($school), 'valid_from' => '', 'valid_to' => '']]));
        // The teachers have qualifications: the staff's import stays whole.
        $this->postJson('/api/imports/'.$staff['id'].'/rollback')->assertConflict()->assertJsonPath('code', 'dependent_operation')->assertJsonPath('line', 2);
        $this->postJson('/api/imports/'.$qualifications['id'].'/rollback')->assertOk()->assertJsonPath('data.counts.undone', 1);
        $this->assertSame(0, DB::connection('owner')->table('school.qualification')->where('tenant_id', $school->id)->count());
        // A member with a contract since, or corrected on screen, stays, and the import with them, until that is undone.
        $third = $this->staff($school, 'RH-003');
        $contract = $this->postJson("/api/staff/{$third->id}/contracts", ['contract_type' => 'permanent', 'valid_from' => now()->addMonth()->toDateString()])
            ->assertCreated()->json('data');
        $this->postJson('/api/imports/'.$staff['id'].'/rollback')->assertConflict()->assertJsonPath('line', 4);
        $this->deleteJson("/api/contracts/{$contract['id']}", ['version' => 1])->assertNoContent();
        $this->putJson("/api/staff/{$third->id}", ['phone' => '0770 12 34 56', 'version' => $third->version])->assertOk();
        $this->postJson('/api/imports/'.$staff['id'].'/rollback')->assertConflict()->assertJsonPath('line', 4);
        $this->putJson("/api/staff/{$third->id}", ['phone' => null, 'version' => $third->version + 1])->assertOk();
        $this->postJson('/api/imports/'.$staff['id'].'/rollback')->assertOk()->assertJsonPath('data.counts.undone', 3);
        $this->assertSame(0, DB::connection('owner')->table('school.staff')->where('tenant_id', $school->id)->count());
        // Imported again, they come back once, with their numbers.
        $this->assertSame(3, $this->confirmed($this->import('staff', $this->file()))['counts']['created']);
        $this->assertSame('ENS-01', $this->staff($school, 'RH-001')->staff_number);
    }

    public function test_the_schools_own_fields_are_columns_a_sensitive_one_read_with_the_contracts_and_the_import_needs_the_whole_school(): void
    {
        [$school] = $this->manager();
        foreach ([['diploma', 'text', false, 1], ['bank_account', 'text', true, 2]] as [$key, $type, $sensitive, $order]) {
            DB::connection('owner')->insert('INSERT INTO school.custom_field (tenant_id, entity, key, field_type, required, is_sensitive, labels, sort_order)
                VALUES (?, ?, ?, ?, false, ?, ?::jsonb, ?)', [$school->id, 'staff', $key, $type, $sensitive, json_encode(['fr' => $key]), $order]);
        }
        $this->assertStringStartsWith("\xEF\xBB\xBFexternal_ref;staff_number;last_name_ar;first_name_ar;last_name_lat;first_name_lat;staff_type;phone;email;hire_date;site;custom_diploma;custom_bank_account",
            $this->get('/api/imports/templates/staff')->assertOk()->getContent());
        $this->confirmed($this->import('staff', [[...$this->file()[0], 'custom_diploma' => 'Licence', 'custom_bank_account' => '007 99999']]));
        $this->assertEquals(['bank_account' => '007 99999', 'diploma' => 'Licence'], json_decode($this->staff($school, 'RH-001')->custom, true));

        // A role that creates staff without reading their contracts writes no sensitive field (FR-REF-04).
        DB::connection('owner')->statement("INSERT INTO school.role (tenant_id, code, kind, labels) VALUES (?, 'staff_clerk', 'internal', '{\"fr\": \"Saisie RH\"}')", [$school->id]);
        foreach (['staff.profile.read', 'staff.profile.create', 'import.job.create', 'import.job.read'] as $permission) {
            DB::connection('owner')->statement("INSERT INTO school.role_permission (tenant_id, role_id, permission_code) SELECT tenant_id, id, ? FROM school.role WHERE tenant_id = ? AND code = 'staff_clerk'",
                [$permission, $school->id]);
        }
        $clerk = $this->account('clerk');
        $this->member($school, $clerk, 'active', ['staff_clerk']);
        $this->signIn($clerk)->assertOk();
        $this->assertSame([[2, 'permission_denied']], $this->refused($this->import('staff', [[...$this->file()[1], 'custom_bank_account' => '001 11111']])));
        $this->get('/api/imports/templates/qualifications')->assertForbidden();

        // A grant limited to a site imports nothing: an import is the whole school's.
        $site = DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
        $local = $this->account('site-hr');
        $this->member($school, $local);
        $this->grant($school, $local, 'academic_hr', 'site', [$site]);
        $this->signIn($local)->assertOk();
        $this->get('/api/imports/templates/staff')->assertForbidden();
    }
}
