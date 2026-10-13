<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The lot 2 reference import (FR-IMP-01, lot 2 exit, R02, TST-14): pupils, their guardians and
 * their enrolments from the school's files, through the import engine's preview, confirmation
 * and undo, under the rules the screens apply.
 */
final class PupilImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
    }

    /** @return array{0: object, 1: array} a school with a year and a programme of two classes, its academic manager signed in */
    private function manager(int $capacity = 250, string $role = 'academic_hr'): array
    {
        $manager = $this->account('pupil-import');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', [$role]);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'current'])
            ->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' ORDER BY code LIMIT 1", [$school->id])->id;
        $site = DB::connection('owner')->selectOne('SELECT id FROM school.site WHERE tenant_id = ? LIMIT 1', [$school->id])->id;
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => 'PRIMAIRE-1', 'labels' => ['fr' => 'Première année'],
            'base_price' => '45000.00', 'status' => 'active'])->assertCreated()->json('data.id');
        foreach (['CLASSE-A', 'CLASSE-B'] as $code) {
            $this->postJson('/api/groups', ['year_id' => $year, 'program_id' => $program, 'site_id' => $site, 'code' => $code, 'labels' => ['fr' => $code],
                'group_type' => 'class', 'capacity' => $capacity, 'valid_from' => '2026-09-01', 'status' => 'active'])->assertCreated();
        }
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

    private function rows(object $school, string $table, string $where = 'true', array $bindings = []): int
    {
        return (int) DB::connection('owner')->selectOne("SELECT count(*) AS n FROM school.$table WHERE tenant_id = ? AND $where", [$school->id, ...$bindings])->n;
    }

    private function pupils(int $count): array
    {
        return array_map(fn (int $i) => ['external_ref' => sprintf('EL-%04d', $i), 'matricule' => $i <= 10 ? sprintf('ANC%04d', $i) : null,
            'last_name_lat' => 'Famille'.intdiv($i + 1, 2), 'first_name_lat' => 'Enfant'.$i, 'last_name_ar' => 'عائلة'.intdiv($i + 1, 2), 'first_name_ar' => 'طفل'.$i,
            // Dates as Excel writes them in Algeria, or as ISO dates.
            'birth_date' => $i % 2 ? sprintf('%02d/%02d/%d', 1 + $i % 28, 1 + $i % 12, 2012 + $i % 5) : sprintf('%d-%02d-%02d', 2012 + $i % 5, 1 + $i % 12, 1 + $i % 28),
            'gender' => $i % 2 ? 'M' : 'f'], range(1, $count));
    }

    public function test_the_five_hundred_reference_pupils_come_with_their_guardians_and_classes_once_only(): void
    {
        [$school] = $this->manager();
        $file = $this->pupils(500);
        // FR-IMP-01 AC3: the preview applies every row, then writes nothing.
        $preview = $this->import('students', $file);
        $this->assertSame(['total' => 500, 'valid' => 500, 'rejected' => 0, 'create' => 500], array_intersect_key($preview['counts'], array_flip(['total', 'valid', 'rejected', 'create'])));
        $this->assertSame(0, $this->rows($school, 'student'));
        $this->assertSame(500, $this->confirmed($preview)['counts']['created']);
        // A matricule each, the school's former ones kept (OD-11), never twice (FR-ELV-01 AC1).
        $this->assertSame(500, (int) DB::connection('owner')->selectOne('SELECT count(DISTINCT matricule) AS n FROM school.student WHERE tenant_id = ?', [$school->id])->n);
        $this->assertSame('ANC0007', DB::connection('owner')->table('school.student')->where('tenant_id', $school->id)->where('external_ref', 'EL-0007')->value('matricule'));
        $this->assertSame('2013-02-02', DB::connection('owner')->table('school.student')->where('tenant_id', $school->id)->where('external_ref', 'EL-0001')->value('birth_date'));

        // AC1, TST-14: the same file again duplicates nothing.
        $again = $this->import('students', $file);
        $this->assertSame([0, 500], [$again['counts']['create'], $again['counts']['unchanged']]);
        $this->confirmed($again);
        $this->assertSame(500, $this->rows($school, 'student'));

        // FR-ELV-02 AC1: brothers and sisters share one guardian, the primary contact.
        $guardians = $this->import('guardians', array_map(fn (int $i) => ['external_ref' => 'P-'.intdiv($i + 1, 2), 'student_ref' => sprintf('EL-%04d', $i),
            'relationship' => 'father', 'last_name_lat' => 'Famille'.intdiv($i + 1, 2), 'first_name_lat' => 'Pere', 'phone' => sprintf('0550%06d', intdiv($i + 1, 2)),
            'is_primary_contact' => 'oui', 'is_payer' => 'نعم'], range(1, 500)));
        $this->assertSame(500, $this->confirmed($guardians)['counts']['created']);
        $this->assertSame([250, 500], [$this->rows($school, 'guardian'), $this->rows($school, 'student_guardian', 'is_primary_contact AND is_payer')]);

        // Validated in their class, a pupil found by their former matricule too; the price of the day (BR-11).
        $enrollments = $this->import('enrollments', array_map(fn (int $i) => ['student_ref' => $i <= 10 ? sprintf('ANC%04d', $i) : sprintf('EL-%04d', $i),
            'year' => '2026-2027', 'program' => 'PRIMAIRE-1', 'group' => $i <= 250 ? 'CLASSE-A' : 'CLASSE-B', 'status' => 'validated'], range(1, 500)));
        $this->assertSame(500, $this->confirmed($enrollments)['counts']['created']);
        $this->assertSame(500, $this->rows($school, 'enrollment', "status = 'validated' AND agreed_price = 45000"));
        $this->assertSame(250, $this->rows($school, 'group_membership', 'is_main AND valid_to IS NULL AND group_id = (SELECT id FROM school.study_group WHERE tenant_id = ? AND code = ?)',
            [$school->id, 'CLASSE-B']));
        $this->assertSame(500, $this->rows($school, 'enrollment_status_history', "to_status = 'validated' AND reason = ?", [__('services.import_validation')]));
        $this->getJson('/api/enrollments/'.DB::connection('owner')->table('school.enrollment')->where('tenant_id', $school->id)->value('id').'/history')
            ->assertOk()->assertJsonPath('data.0.to_status', 'pending')->assertJsonPath('data.1.reason', __('services.import_validation'));
        $again = $this->import('enrollments', [['student_ref' => 'EL-0300', 'year' => '2026-2027', 'program' => 'PRIMAIRE-1', 'group' => 'CLASSE-B', 'status' => 'validated']]);
        $this->assertSame([0, 1], [$again['counts']['create'], $again['counts']['unchanged']]);
    }

    public function test_each_refused_row_is_named_with_its_line_and_cause_and_a_probable_duplicate_needs_a_reason(): void
    {
        [$school] = $this->manager(capacity: 1);
        $this->postJson('/api/students', ['last_name_lat' => 'Saidi', 'first_name_lat' => 'Lina', 'birth_date' => '2013-05-02', 'matricule' => 'ANC1'])->assertCreated();
        $row = fn (string $ref, array $values) => ['external_ref' => $ref, 'matricule' => null, 'last_name_lat' => null, 'first_name_lat' => null,
            'last_name_ar' => null, 'first_name_ar' => null, 'birth_date' => null, 'duplicate_reason' => null, ...$values];
        $batch = $this->import('students', [
            $row('R-1', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'birth_date' => '14/03/2012']),
            $row('R-2', ['last_name_lat' => 'Hamdi', 'first_name_lat' => 'Sara', 'birth_date' => '31/02/2012']),
            $row('R-3', ['birth_date' => '2012-01-01']),
            $row('R-1', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'birth_date' => '14/03/2012']),
            $row('R-4', ['last_name_lat' => 'BENALI', 'first_name_lat' => 'amine', 'birth_date' => '2012-03-14']),
            $row('R-5', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'birth_date' => '2012-03-14', 'duplicate_reason' => 'Homonymes, cousins']),
            $row('R-6', ['last_name_lat' => 'Mansouri', 'first_name_lat' => 'Yanis', 'birth_date' => '2099-01-01']),
            $row('R-7', ['matricule' => 'ANC1', 'last_name_lat' => 'Kaci', 'first_name_lat' => 'Nour', 'birth_date' => '2014-06-01']),
            // FR-ELV-04 AC1: the same pupil in Arabic letters.
            $row('R-8', ['last_name_ar' => 'سعيدي', 'first_name_ar' => 'لينة', 'birth_date' => '2013-05-02']),
        ]);
        $this->assertSame([[3, 'invalid'], [4, 'name_required'], [5, 'duplicate_in_file'], [6, 'student_duplicate'], [8, 'birth_date_future'],
            [9, 'duplicate'], [10, 'student_duplicate']], $this->refused($batch));
        $rows = collect($this->getJson('/api/imports/'.$batch['id'].'/rows?outcome=rejected')->json('data'))->keyBy('line');
        $this->assertSame(__('errors.import_date_invalid', ['column' => 'birth_date']), $rows[3]['error_message']);
        $this->assertStringContainsString('ANC1', $rows[10]['error_message']);
        $this->assertSame(1, $this->rows($school, 'student'));
        $this->confirmed($batch);
        // The reason to create a second record stays in the journal.
        $this->assertSame('Homonymes, cousins', DB::connection('owner')->selectOne("SELECT a.reason FROM school.audit_log a JOIN school.student s ON s.id = a.object_id
            WHERE s.tenant_id = ? AND s.external_ref = 'R-5' AND a.action = 'INSERT'", [$school->id])->reason);

        $batch = $this->import('guardians', [['external_ref' => 'P-1', 'student_ref' => 'X-9', 'relationship' => 'mother', 'last_name_lat' => 'Benali', 'first_name_lat' => 'Mina']]);
        $this->assertSame([[2, 'reference_unknown']], $this->refused($batch));
        $this->assertSame(__('errors.import_student_unknown', ['reference' => 'X-9']), $this->getJson('/api/imports/'.$batch['id'].'/rows')->json('data.0.error_message'));

        // The enrolment's own rules, as on screen: a minor's primary contact, the codes, the class's places (BR-07).
        foreach (['Adulte', 'Second'] as $name) $this->postJson('/api/students', ['last_name_lat' => $name, 'first_name_lat' => 'Majeur', 'birth_date' => '1990-01-01'])->assertCreated();
        $adults = DB::connection('owner')->table('school.student')->where('tenant_id', $school->id)->where('first_name_lat', 'Majeur')->orderBy('last_name_lat')->pluck('matricule');
        $batch = $this->import('enrollments', [
            ['student_ref' => 'R-1', 'year' => '2026-2027', 'program' => 'PRIMAIRE-1', 'group' => null],
            ['student_ref' => $adults[0], 'year' => '2026-2027', 'program' => 'INCONNU', 'group' => null],
            ['student_ref' => $adults[0], 'year' => '2025-2026', 'program' => 'PRIMAIRE-1', 'group' => null],
            ['student_ref' => $adults[0], 'year' => '2026-2027', 'program' => 'PRIMAIRE-1', 'group' => 'CLASSE-A'],
            ['student_ref' => $adults[1], 'year' => '2026-2027', 'program' => 'PRIMAIRE-1', 'group' => 'CLASSE-A'],
        ]);
        $this->assertSame([[2, 'primary_contact_required'], [3, 'reference_unknown'], [4, 'reference_unknown'], [6, 'group_full']], $this->refused($batch));
        $this->assertSame(0, $this->rows($school, 'enrollment'));
        $this->assertSame(1, $this->confirmed($batch)['counts']['created']);
    }

    public function test_a_guardian_already_recorded_under_another_reference_is_refused_unless_the_row_says_why(): void
    {
        [$school] = $this->manager();
        $this->confirmed($this->import('students', $this->pupils(3)));
        // The father, recorded on screen before the file came.
        $this->postJson('/api/guardians', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0550 00 00 01'])->assertCreated();
        $row = fn (string $ref, string $pupil, string $relationship, array $values) => ['external_ref' => $ref, 'student_ref' => $pupil,
            'relationship' => $relationship, 'last_name_lat' => null, 'first_name_lat' => null, 'last_name_ar' => null, 'first_name_ar' => null,
            'phone' => null, 'duplicate_reason' => null, ...$values];
        $batch = $this->import('guardians', [
            $row('P-1', 'EL-0001', 'father', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0550000001']),
            // The same father in Arabic letters (FR-ELV-04 AC1's rule, for guardians).
            $row('P-2', 'EL-0002', 'father', ['last_name_ar' => 'بن علي', 'first_name_ar' => 'كريم', 'phone' => '0550000001']),
            // The mother, on the household's phone: another person.
            $row('P-3', 'EL-0003', 'mother', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Mina', 'phone' => '0550000001']),
            $row('P-4', 'EL-0001', 'guardian', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0550000001',
                'duplicate_reason' => 'Homonyme : le grand-père, tuteur']),
            // Brothers and sisters: the reference of the first row, not a duplicate.
            $row('P-3', 'EL-0002', 'mother', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Mina', 'phone' => '0550000001']),
        ]);
        $this->assertSame([[2, 'guardian_duplicate'], [3, 'guardian_duplicate']], $this->refused($batch));
        $this->assertSame(__('errors.import_guardian_duplicate', ['guardians' => 'Benali Karim']),
            $this->getJson('/api/imports/'.$batch['id'].'/rows?outcome=rejected')->json('data.0.error_message'));
        $this->assertSame(3, $this->confirmed($batch)['counts']['created']);
        $this->assertSame([3, 3], [$this->rows($school, 'guardian'), $this->rows($school, 'student_guardian')]);
        $this->assertSame('Homonyme : le grand-père, tuteur', DB::connection('owner')->selectOne("SELECT a.reason FROM school.audit_log a
            JOIN school.guardian g ON g.id = a.object_id WHERE g.tenant_id = ? AND g.external_ref = 'P-4' AND a.action = 'INSERT'", [$school->id])->reason);
        $this->assertNull(DB::connection('owner')->selectOne("SELECT a.reason FROM school.audit_log a JOIN school.guardian g ON g.id = a.object_id
            WHERE g.tenant_id = ? AND g.external_ref = 'P-3' AND a.action = 'INSERT'", [$school->id])->reason);
        $this->assertStringContainsString(';duplicate_reason', $this->get('/api/imports/templates/guardians')->assertOk()->getContent());
    }

    public function test_an_import_updates_what_its_file_changes_and_is_undone_last_first_while_nothing_depends_on_it(): void
    {
        [$school] = $this->manager();
        $file = array_map(fn (array $row) => [...$row, 'phone' => '0661000000'], $this->pupils(3));
        $students = $this->confirmed($this->import('students', $file));
        $guardians = $this->confirmed($this->import('guardians', array_map(fn (int $i) => ['external_ref' => 'P-1', 'student_ref' => sprintf('EL-%04d', $i),
            'relationship' => 'mother', 'last_name_lat' => 'Famille1', 'first_name_lat' => 'Mere', 'phone' => '0550000001', 'is_primary_contact' => '1'], [1, 2])));
        $enrollments = $this->confirmed($this->import('enrollments', array_map(fn (int $i) => ['student_ref' => sprintf('EL-%04d', $i), 'year' => '2026-2027',
            'program' => 'PRIMAIRE-1', 'group' => 'CLASSE-A'], [1, 2])));

        // A column the file leaves out keeps its value; one it changes is updated.
        $this->assertSame(3, $this->import('students', array_map(fn (array $row) => array_diff_key($row, ['phone' => 1]), $file))['counts']['unchanged']);
        $file[0]['phone'] = '0661999999';
        $changed = $this->import('students', $file);
        $this->assertSame([1, 2], [$changed['counts']['update'], $changed['counts']['unchanged']]);
        $changed = $this->confirmed($changed);
        $pupil = DB::connection('owner')->table('school.student')->where('tenant_id', $school->id)->where('external_ref', 'EL-0001')->first();
        $this->assertSame('0661999999', $pupil->phone);
        // Changed again on screen since: the update stays, until the record is as the import left it.
        $this->putJson('/api/students/'.$pupil->id, ['phone' => '0770123456', 'version' => $pupil->version])->assertOk();
        $this->postJson('/api/imports/'.$changed['id'].'/rollback')->assertConflict()->assertJsonPath('code', 'dependent_operation')->assertJsonPath('line', 2);
        $this->putJson('/api/students/'.$pupil->id, ['phone' => '0661999999', 'version' => $pupil->version + 1])->assertOk();
        $this->postJson('/api/imports/'.$changed['id'].'/rollback')->assertOk()->assertJsonPath('data.counts.undone', 1);
        $this->assertSame('0661000000', DB::connection('owner')->table('school.student')->where('id', $pupil->id)->value('phone'));

        // The pupils have guardians and classes: their import stays whole.
        $this->postJson('/api/imports/'.$students['id'].'/rollback')->assertConflict()->assertJsonPath('code', 'dependent_operation');
        $this->assertSame(3, $this->rows($school, 'student'));
        // Undone in the order they came: enrolments with their places, guardians, then pupils.
        $this->postJson('/api/imports/'.$guardians['id'].'/rollback')->assertConflict()->assertJsonPath('code', 'dependent_operation');
        $this->postJson('/api/imports/'.$enrollments['id'].'/rollback')->assertOk()->assertJsonPath('data.counts.undone', 2);
        $this->assertSame([0, 0, 0], [$this->rows($school, 'enrollment'), $this->rows($school, 'group_membership'), $this->rows($school, 'enrollment_status_history')]);
        $this->postJson('/api/imports/'.$guardians['id'].'/rollback')->assertOk()->assertJsonPath('data.counts.undone', 2);
        $this->assertSame([0, 0], [$this->rows($school, 'guardian'), $this->rows($school, 'student_guardian')]);
        // A pupil corrected on screen since stays, and the import with them, until the correction is undone.
        $third = DB::connection('owner')->table('school.student')->where('tenant_id', $school->id)->where('external_ref', 'EL-0003')->first();
        $this->putJson('/api/students/'.$third->id, ['birth_place' => 'Oran', 'version' => $third->version])->assertOk();
        $this->postJson('/api/imports/'.$students['id'].'/rollback')->assertConflict()->assertJsonPath('line', 4);
        $this->putJson('/api/students/'.$third->id, ['birth_place' => null, 'version' => $third->version + 1])->assertOk();
        $this->postJson('/api/imports/'.$students['id'].'/rollback')->assertOk()->assertJsonPath('data.counts.undone', 3);
        $this->assertSame(0, $this->rows($school, 'student'));
        // Imported again, they come back once.
        $this->assertSame(3, $this->confirmed($this->import('students', $file))['counts']['created']);
    }

    public function test_the_schools_own_fields_are_columns_and_a_sensitive_one_needs_its_permission(): void
    {
        [$school] = $this->manager();
        foreach ([['transport', 'boolean', true, false, 1], ['blood_group', 'text', false, true, 2]] as [$key, $type, $required, $sensitive, $order]) {
            DB::connection('owner')->insert('INSERT INTO school.custom_field (tenant_id, entity, key, field_type, required, is_sensitive, labels, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?)', [$school->id, 'student', $key, $type, $required, $sensitive, json_encode(['fr' => $key]), $order]);
        }
        $this->assertStringStartsWith("\xEF\xBB\xBFexternal_ref;matricule;last_name_ar;first_name_ar;last_name_lat;first_name_lat;birth_date;birth_place;gender;phone;email;address;origin_school;site;custom_transport;custom_blood_group;duplicate_reason",
            $this->get('/api/imports/templates/students')->assertOk()->getContent());
        $file = fn (array $values) => [['external_ref' => 'C-1', 'last_name_lat' => 'Kaci', 'first_name_lat' => 'Nour', 'birth_date' => '2014-06-01', ...$values]];
        $this->assertSame([[2, 'custom_value_required']], $this->refused($this->import('students', $file(['custom_transport' => null, 'custom_blood_group' => 'A+']))));
        $this->assertSame([[2, 'custom_value_invalid']], $this->refused($this->import('students', $file(['custom_transport' => 'peut-être']))));
        $this->confirmed($this->import('students', $file(['custom_transport' => 'Oui', 'custom_blood_group' => 'A+'])));
        $this->assertEquals(['blood_group' => 'A+', 'transport' => true],
            json_decode(DB::connection('owner')->table('school.student')->where('tenant_id', $school->id)->where('external_ref', 'C-1')->value('custom'), true));

        // The front office creates pupils, without reading their sensitive fields (FR-REF-04).
        $clerk = $this->account('front');
        $this->member($school, $clerk, 'active', ['front_office']);
        $this->signIn($clerk)->assertOk();
        $batch = $this->import('students', [['external_ref' => 'C-2', 'last_name_lat' => 'Kaci', 'first_name_lat' => 'Rym', 'birth_date' => '2015-06-01',
            'custom_transport' => 'non', 'custom_blood_group' => 'O-']]);
        $this->assertSame([[2, 'permission_denied']], $this->refused($batch));
        $this->assertSame(__('errors.import_column_denied', ['column' => 'custom_blood_group']), $this->getJson('/api/imports/'.$batch['id'].'/rows')->json('data.0.error_message'));
    }

    public function test_a_pupil_is_recorded_on_the_site_its_row_names_and_a_guardian_on_their_pupil_s(): void
    {
        [$school] = $this->manager();
        $annex = $this->postJson('/api/sites', ['code' => 'ANNEXE', 'labels' => ['fr' => 'Annexe']])->assertCreated()->json('data.id');
        $default = DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
        $row = fn (string $ref, ?string $site) => ['external_ref' => $ref, 'last_name_lat' => 'Site', 'first_name_lat' => $ref, 'birth_date' => '2001-01-01', 'site' => $site];
        $this->assertSame([[4, 'reference_unknown']], $this->refused($this->import('students', [$row('S-1', 'ANNEXE'), $row('S-2', ''), $row('S-3', 'NULLE-PART')])));
        $this->confirmed($this->import('students', [$row('S-1', 'ANNEXE'), $row('S-2', '')]));
        $site = fn (string $ref) => DB::connection('owner')->table('school.student')->where('tenant_id', $school->id)->where('external_ref', $ref)->value('site_id');
        $this->assertSame([$annex, $default], [$site('S-1'), $site('S-2')]);
        $this->confirmed($this->import('guardians', [['external_ref' => 'R-1', 'student_ref' => 'S-1', 'relationship' => 'father',
            'last_name_lat' => 'Site', 'first_name_lat' => 'Pere', 'phone' => '0550 10 20 30']]));
        $this->assertSame($annex, DB::connection('owner')->table('school.guardian')->where('tenant_id', $school->id)->where('external_ref', 'R-1')->value('site_id'));
        // A later file moves a pupil to another site; undone, the import puts them back.
        $moved = $this->confirmed($this->import('students', [$row('S-2', 'ANNEXE')]));
        $this->assertSame($annex, $site('S-2'));
        $this->postJson('/api/imports/'.$moved['id'].'/rollback')->assertOk()->assertJsonPath('data.counts.undone', 1);
        $this->assertSame($default, $site('S-2'));
    }

    public function test_a_pupil_import_needs_its_module_and_the_permission_for_the_whole_school(): void
    {
        [$school, $manager] = $this->manager();
        $site = DB::connection('owner')->selectOne('SELECT id FROM school.site WHERE tenant_id = ? LIMIT 1', [$school->id])->id;
        $local = $this->account('site-manager');
        $this->member($school, $local);
        $this->grant($school, $local, 'academic_hr', 'site', [$site]);
        $this->signIn($local)->assertOk();
        $this->get('/api/imports/templates/students')->assertForbidden();
        $this->post('/api/imports', ['kind' => 'students', 'file' => UploadedFile::fake()->createWithContent('eleves.csv', "external_ref;birth_date\r\nEL-1;2014-01-01\r\n")],
            ['Accept' => 'application/json'])->assertForbidden()->assertJsonPath('code', 'permission_denied');

        // R09, BR-20: a school without the pupils' module imports none.
        $this->signIn($manager)->assertOk();
        foreach (['student_finance', 'enrollment_groups', 'students_crm'] as $module) {
            DB::connection('console')->select('SELECT platform.disable_module(?, ?)', [$school->id, $module]);
        }
        $this->get('/api/imports/templates/students')->assertForbidden();
        $this->get('/api/imports/templates/enrollments')->assertForbidden();
        $this->post('/api/imports', ['kind' => 'guardians', 'file' => UploadedFile::fake()->createWithContent('parents.csv', "external_ref\r\nP-1\r\n")],
            ['Accept' => 'application/json'])->assertForbidden();
    }
}
