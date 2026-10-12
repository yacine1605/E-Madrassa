<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Admission dossiers through their real routes, with restricted SQL roles and signed contexts. */
final class PupilRecordsTest extends TestCase
{
    private function manager(): array
    {
        $manager = $this->account('admission');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        return [$school, $manager];
    }

    private function pupil(array $extra = []): array
    {
        return ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'birth_date' => '2012-05-15', ...$extra];
    }

    private function create(array $extra = []): array
    {
        return $this->postJson('/api/students', $this->pupil($extra))->assertCreated()->json('data');
    }

    /** Focus these fixtures on dossier scopes; AcademicWorkflowTest exercises the enrolment APIs. */
    private function place(object $school, string $student): array
    {
        $db = DB::connection('owner');
        $code = Str::upper(Str::random(10));
        $year = $db->table('school.academic_year')->insertGetId(['tenant_id' => $school->id, 'code' => $code,
            'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString(), 'status' => 'planned'], 'id');
        $node = $db->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $program = $db->table('school.program')->insertGetId(['tenant_id' => $school->id, 'code' => $code, 'year_id' => $year,
            'node_id' => $node, 'labels' => '{"fr":"Programme"}', 'status' => 'active'], 'id');
        $enrollment = $db->table('school.enrollment')->insertGetId(['tenant_id' => $school->id, 'student_id' => $student,
            'program_id' => $program, 'idempotency_key' => (string) Str::uuid()], 'id');
        $site = $db->selectOne('SELECT id FROM school.site WHERE tenant_id = ? LIMIT 1', [$school->id])->id;
        $group = $db->table('school.study_group')->insertGetId(['tenant_id' => $school->id, 'year_id' => $year, 'code' => $code,
            'program_id' => $program, 'site_id' => $site, 'group_type' => 'class', 'labels' => '{"fr":"Classe"}',
            'capacity' => 10, 'valid_from' => now()->toDateString(), 'status' => 'active'], 'id');
        $db->table('school.group_membership')->insert(['tenant_id' => $school->id, 'enrollment_id' => $enrollment, 'group_id' => $group]);
        return [$enrollment, $group];
    }

    public function test_dossiers_validate_names_dates_and_identity_and_detect_stale_edits(): void
    {
        $this->manager();
        $pupil = $this->create();
        $this->assertMatchesRegularExpression('/^'.date('Y').'\d{5}$/', $pupil['matricule']);
        $this->assertArrayNotHasKey('photo_file_id', $pupil);
        $this->assertArrayNotHasKey('user_id', $pupil);
        $this->postJson('/api/students', ['last_name_lat' => 'Incomplet', 'birth_date' => '2010-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('last_name_lat');
        $this->postJson('/api/students', $this->pupil(['birth_date' => '2999-01-01']))->assertUnprocessable();
        $this->postJson('/api/students', $this->pupil(['tenant_id' => (string) Str::uuid()]))->assertUnprocessable();
        $this->putJson('/api/students/'.$pupil['id'], ['phone' => '0550 123 456', 'version' => 1])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.first_name_lat', 'Amine');
        $this->putJson('/api/students/'.$pupil['id'], ['phone' => '0660', 'version' => 1])
            ->assertConflict()->assertJsonPath('code', 'stale_version');
        $this->putJson('/api/students/'.$pupil['id'], ['matricule' => 'OTHER', 'version' => 2])->assertUnprocessable();
        $this->deleteJson('/api/students/'.$pupil['id'])->assertStatus(405);
        $this->postJson('/api/custom-fields', ['entity' => 'student', 'key' => 'appointment', 'field_type' => 'date',
            'labels' => ['fr' => 'Rendez-vous']])->assertCreated();
        $this->putJson('/api/students/'.$pupil['id'], ['custom' => ['appointment' => '2026-02-31'], 'version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('custom.appointment');
        $this->putJson('/api/students/'.$pupil['id'], ['custom' => ['appointment' => '2026-02-28'], 'version' => 2])
            ->assertOk()->assertJsonPath('data.custom.appointment', '2026-02-28');
    }

    public function test_lists_always_paginate_and_search_normalized_names_matricules_and_birth_dates(): void
    {
        $this->manager();
        for ($i = 0; $i < 26; $i++) $this->create(['first_name_lat' => 'Eleve'.$i]);
        $helene = $this->create(['first_name_lat' => 'Hélène', 'birth_date' => '2013-01-02', 'matricule' => 'IMPORT-42']);
        $this->getJson('/api/students?paginate=0')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 27);
        $this->getJson('/api/students?page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/students?per_page=101')->assertUnprocessable();
        $this->getJson('/api/students?q=HELENE')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $helene['id']);
        $this->getJson('/api/students?q=IMPORT-42&birth_date=2013-01-02')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/students?birth_date=2012-05-15')->assertOk()->assertJsonPath('meta.total', 26);
    }

    public function test_duplicate_suggestions_require_a_reason_to_create_and_keep_it_in_the_journal(): void
    {
        [$school] = $this->manager();
        $first = $this->create(['first_name_lat' => 'Hélène', 'phone' => '0550 123 456']);
        $same = $this->pupil(['first_name_lat' => 'HELENE']);
        $this->postJson('/api/students/duplicates', $same)->assertOk()->assertJsonPath('data.0.id', $first['id']);
        $this->postJson('/api/students', $same)->assertConflict()->assertJsonPath('code', 'student_duplicate');
        $this->postJson('/api/students/duplicates', $this->pupil(['first_name_lat' => 'Autre', 'phone' => '0550123456']))
            ->assertOk()->assertJsonPath('data.0.id', $first['id']);
        $created = $this->postJson('/api/students', [...$same, 'duplicate_reason' => 'Homonymes confirmés par la famille'])
            ->assertCreated()->json('data.id');
        $entry = DB::connection('owner')->selectOne("SELECT reason FROM school.audit_log WHERE tenant_id = ? AND object_id = ? AND action = 'INSERT'", [$school->id, $created]);
        $this->assertSame('Homonymes confirmés par la famille', $entry->reason);
        $arabic = ['last_name_ar' => 'محمد', 'first_name_ar' => 'أمين', 'birth_date' => '2014-02-03'];
        $id = $this->postJson('/api/students', $arabic)->assertCreated()->json('data.id');
        $this->postJson('/api/students/duplicates', $this->pupil(['last_name_lat' => 'Mohamed', 'first_name_lat' => 'Amine', 'birth_date' => '2014-02-03']))
            ->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_a_guardian_is_shared_by_siblings_and_link_history_and_versions_are_kept(): void
    {
        $this->manager();
        $a = $this->create();
        $b = $this->create(['first_name_lat' => 'Lina']);
        $guardian = $this->postJson('/api/guardians', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0550'])
            ->assertCreated()->json('data');
        $link = ['guardian_id' => $guardian['id'], 'relationship' => 'father', 'is_primary_contact' => true];
        $first = $this->postJson('/api/students/'.$a['id'].'/guardians', $link)->assertCreated()->json('data');
        $this->postJson('/api/students/'.$b['id'].'/guardians', $link)->assertCreated();
        $this->getJson('/api/guardians')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/students/'.$a['id'].'/guardians', $link)->assertConflict()->assertJsonPath('code', 'duplicate');
        $path = '/api/students/'.$a['id'].'/guardians/'.$first['id'];
        $this->putJson($path, ['relationship' => 'father', 'is_payer' => true, 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($path, ['relationship' => 'father', 'version' => 1])->assertConflict()->assertJsonPath('code', 'stale_version');
        $this->postJson($path.'/archive', ['reason' => 'Contact remplacé', 'version' => 2])->assertOk();
        $this->getJson('/api/students/'.$a['id'].'/guardians')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/students/'.$a['id'].'/guardians?include_archived=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/students/'.$b['id'].'/guardians')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson('/api/guardians/'.$guardian['id'], ['phone' => '0660', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_sensitive_custom_values_are_hidden_cannot_be_overwritten_and_survive_partial_edits(): void
    {
        [$school, $manager] = $this->manager();
        foreach (['health' => true, 'hobby' => false] as $key => $sensitive) {
            $this->postJson('/api/custom-fields', ['entity' => 'student', 'key' => $key, 'field_type' => 'text',
                'labels' => ['fr' => $key], 'is_sensitive' => $sensitive])->assertCreated();
        }
        $pupil = $this->create(['custom' => ['health' => 'Confidentiel', 'hobby' => 'Lecture']]);
        $this->getJson('/api/students/'.$pupil['id'])->assertJsonPath('data.custom.health', 'Confidentiel');
        $clerk = $this->account('clerk');
        $this->member($school, $clerk, 'active', ['front_office']);
        $this->signIn($clerk)->assertOk();
        $this->getJson('/api/students/'.$pupil['id'])->assertOk()->assertJsonMissingPath('data.custom.health');
        $this->getJson('/api/students')->assertOk()->assertJsonMissingPath('data.0.custom.health');
        $this->putJson('/api/students/'.$pupil['id'], ['custom' => ['health' => null], 'version' => 1])->assertForbidden();
        $this->putJson('/api/students/'.$pupil['id'], ['custom' => ['hobby' => 'Sport'], 'version' => 1])
            ->assertOk()->assertJsonMissingPath('data.custom.health')->assertJsonPath('data.custom.hobby', 'Sport');
        $this->signIn($manager)->assertOk();
        $this->getJson('/api/students/'.$pupil['id'])->assertJsonPath('data.custom.health', 'Confidentiel')->assertJsonPath('data.version', 2);
    }

    public function test_foreign_ids_missing_permissions_and_disabled_modules_are_refused(): void
    {
        [$a, $manager] = $this->manager();
        $pupil = $this->create();
        [$b] = $this->manager();
        $this->getJson('/api/students/'.$pupil['id'])->assertNotFound();
        $this->putJson('/api/students/'.$pupil['id'], ['phone' => '0660', 'version' => 1])->assertNotFound();
        $this->getJson('/api/students/not-a-uuid')->assertNotFound();
        $this->getJson('/api/students')->assertJsonPath('meta.total', 0);
        $reader = $this->account('without-rights');
        $this->member($b, $reader);
        $this->signIn($reader)->assertOk();
        $this->getJson('/api/students')->assertForbidden();
        $this->postJson('/api/guardians', [])->assertForbidden();
        $this->signIn($manager)->assertOk();
        foreach (['student_finance', 'enrollment_groups', 'students_crm'] as $module)
            DB::connection('console')->select('SELECT platform.disable_module(?, ?)', [$a->id, $module]);
        $this->getJson('/api/students')->assertForbidden()->assertJsonPath('code', 'module_disabled');
    }

    public function test_status_changes_are_reasoned_localized_and_former_records_are_read_only(): void
    {
        $this->manager();
        $pupil = $this->create();
        $path = '/api/students/'.$pupil['id'];
        $this->postJson($path.'/status', ['status' => 'blocked', 'version' => 1])->assertUnprocessable();
        $this->postJson($path.'/archive', ['reason' => 'Archivage', 'version' => 1])->assertConflict()->assertJsonPath('code', 'student_archive_former');
        $this->postJson($path.'/status', ['status' => 'blocked', 'reason' => 'Dossier incomplet', 'version' => 1])->assertOk();
        $this->postJson($path.'/status', ['status' => 'active', 'reason' => 'Dossier complété', 'version' => 2])->assertOk();
        $this->postJson($path.'/status', ['status' => 'former', 'reason' => 'Fin de parcours', 'version' => 3])->assertOk();
        $this->putJson('/api/account/locale', ['locale' => 'ar'])->assertOk();
        $this->putJson($path, ['phone' => '0550', 'version' => 4])->assertConflict()->assertJsonPath('code', 'student_former')
            ->assertJsonPath('message', __('errors.student_former', [], 'ar'));
        $this->postJson($path.'/archive', ['reason' => 'Archivage autorisé', 'version' => 4])->assertOk();
        $this->getJson('/api/students')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/students?include_archived=1&status=former')->assertJsonPath('meta.total', 1);
    }

    public function test_scoped_reads_updates_duplicates_and_sensitive_fields_use_their_own_grants(): void
    {
        [$school] = $this->manager();
        $this->postJson('/api/custom-fields', ['entity' => 'student', 'key' => 'health', 'field_type' => 'text',
            'labels' => ['fr' => 'Santé'], 'is_sensitive' => true])->assertCreated();
        $a = $this->create(['birth_date' => '1990-01-01', 'custom' => ['health' => 'Privé A']]);
        $b = $this->create(['first_name_lat' => 'Lina', 'birth_date' => '1990-01-01', 'custom' => ['health' => 'Privé B']]);
        $c = $this->create(['first_name_lat' => 'Inaccessible', 'birth_date' => '1990-01-01']);
        [, $groupA] = $this->place($school, $a['id']);
        [, $groupB] = $this->place($school, $b['id']);
        $reader = $this->account('scoped');
        $this->member($school, $reader);
        $this->grant($school, $reader, 'front_office', 'group', [$groupA]);
        $this->grant($school, $reader, 'academic_hr', 'group', [$groupB]);
        $this->signIn($reader)->assertOk();
        $this->getJson('/api/students')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/students/'.$a['id'])->assertOk()->assertJsonMissingPath('data.custom.health');
        $this->getJson('/api/students/'.$b['id'])->assertOk()->assertJsonPath('data.custom.health', 'Privé B');
        $this->getJson('/api/students/'.$c['id'])->assertNotFound();
        $this->putJson('/api/students/'.$c['id'], ['version' => 1, 'phone' => '0550'])->assertNotFound();
        $this->putJson('/api/students/'.$a['id'], ['version' => 1, 'custom' => ['health' => 'Refusé']])->assertForbidden();
        $this->putJson('/api/students/'.$a['id'], ['version' => 1, 'phone' => '0550'])->assertOk();
        $this->postJson('/api/students/duplicates', $this->pupil(['first_name_lat' => 'Inaccessible', 'birth_date' => '1990-01-01']))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/students', $this->pupil(['first_name_lat' => 'Nouveau']))->assertForbidden()->assertJsonPath('code', 'permission_denied');
    }

    public function test_blocking_warns_about_enrolments_without_changing_them_even_without_enrolment_read_permission(): void
    {
        [$school] = $this->manager();
        $student = $this->create(['birth_date' => '1990-01-01']);
        [$enrollment] = $this->place($school, $student['id']);
        $editor = $this->account('dossier-editor');
        $this->member($school, $editor);
        $db = DB::connection('owner');
        $role = $db->table('school.role')->insertGetId(['tenant_id' => $school->id, 'code' => 'dossier_editor',
            'kind' => 'internal', 'labels' => '{"fr":"Dossiers"}'], 'id');
        foreach (['student.record.read', 'student.record.update'] as $permission)
            $db->table('school.role_permission')->insert(['tenant_id' => $school->id, 'role_id' => $role, 'permission_code' => $permission]);
        $this->grant($school, $editor, 'dossier_editor');
        $this->signIn($editor)->assertOk();
        $path = '/api/students/'.$student['id'].'/status';
        $input = ['status' => 'blocked', 'reason' => 'Pièce manquante', 'version' => 1];
        $this->postJson($path, $input)->assertConflict()->assertJsonPath('code', 'student_active_enrollments')->assertJsonPath('active_enrollments', 1);
        $this->getJson('/api/students/'.$student['id'])->assertJsonPath('data.status', 'active')->assertJsonPath('data.version', 1);
        $this->postJson($path, [...$input, 'confirm_enrollments' => true])->assertOk()->assertJsonPath('data.status', 'blocked');
        $this->assertSame('pending', $db->table('school.enrollment')->where('id', $enrollment)->value('status'));
        $this->assertSame(1, $db->table('school.group_membership')->where('enrollment_id', $enrollment)->whereNull('valid_to')->count());
    }
}
