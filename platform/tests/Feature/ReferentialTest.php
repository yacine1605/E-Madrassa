<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The referential through the API (FR-PLT-03, FR-REF-01 to 05). */
final class ReferentialTest extends TestCase
{
    /** @return array{0: object, 1: array} a school with a profile, its administrator signed in */
    private function schoolWithProfile(string $profile = 'private_school_dz'): array
    {
        $admin = $this->account('admin');
        $school = $this->school('essential', $admin, $profile);
        $this->member($school, $admin);
        $this->signIn($admin)->assertOk();
        return [$school, $admin];
    }

    private function nodeId(string $type, string $code): string
    {
        return collect($this->getJson('/api/referential/nodes?type='.$type)->assertOk()->json('data'))->firstWhere('code', $code)['id'];
    }

    public function test_a_school_and_a_training_centre_name_their_referential_after_their_profile(): void
    {
        $this->schoolWithProfile();
        // FR-REF-01 AC1, FR-REF-03 AC3: same product, labels from the profile.
        $this->getJson('/api/school')->assertOk()->assertJsonPath('data.profile.code', 'private_school_dz')
            ->assertJsonPath('data.profile.subject_kind', 'subject')->assertJsonPath('data.time_zone', 'Africa/Algiers');
        $this->getJson('/api/referential/types')->assertOk()->assertJsonPath('data.1.code', 'grade')->assertJsonPath('data.1.labels.fr', 'Niveau');
        $this->getJson('/api/referential/nodes?type=cycle')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.3.labels.ar', 'التعليم الثانوي');
        $this->getJson('/api/subjects')->assertOk()->assertJsonCount(28, 'data')->assertJsonPath('data.0.kind', 'subject');

        $this->schoolWithProfile('training_center');
        $this->getJson('/api/school')->assertJsonPath('data.profile.subject_kind', 'module');
        $this->getJson('/api/referential/types')->assertJsonPath('data.1.code', 'specialty')->assertJsonPath('data.1.labels.fr', 'Spécialité');
        $this->getJson('/api/referential/nodes')->assertJsonCount(0, 'data');
        $this->postJson('/api/subjects', ['code' => 'REACT', 'labels' => ['fr' => 'React avancé']])->assertCreated()->assertJsonPath('data.kind', 'module');
    }

    public function test_types_are_renamed_never_recoded_and_only_unused_optional_ones_turn_off(): void
    {
        $this->schoolWithProfile();
        $this->putJson('/api/referential/types/grade', ['labels' => ['fr' => 'Classe', 'ar' => 'القسم'], 'active' => true, 'version' => 1])
            ->assertOk()->assertJsonPath('data.code', 'grade')->assertJsonPath('data.labels.fr', 'Classe');
        $this->putJson('/api/referential/types/grade', ['labels' => ['fr' => 'Classe'], 'active' => false, 'version' => 2])
            ->assertStatus(409)->assertJsonPath('code', 'type_required');
        $this->putJson('/api/referential/types/track', ['labels' => ['fr' => 'Filière'], 'active' => false, 'version' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'type_in_use');
    }

    public function test_nodes_are_added_under_the_right_parent_and_archived_from_the_bottom(): void
    {
        $this->schoolWithProfile();
        $secondaire = $this->nodeId('cycle', 'SECONDAIRE');
        // FR-REF-02 AC1.
        $this->postJson('/api/referential/nodes', ['type' => 'grade', 'parent_id' => $secondaire, 'code' => '1AS_BIS', 'labels' => ['fr' => '1RE ANNEE  secondaire']])
            ->assertCreated();
        $this->postJson('/api/referential/nodes', ['type' => 'grade', 'parent_id' => $secondaire, 'code' => '1AS_TER', 'labels' => ['fr' => '1re  année Secondaire']])
            ->assertStatus(409)->assertJsonPath('code', 'duplicate');
        $this->postJson('/api/referential/nodes', ['type' => 'grade', 'parent_id' => $this->nodeId('grade', '1AS'), 'code' => 'X', 'labels' => ['fr' => 'X']])
            ->assertStatus(409)->assertJsonPath('code', 'parent_invalid');
        $this->postJson('/api/referential/nodes', ['type' => 'grade', 'code' => 'Y', 'labels' => ['en' => 'Only English']])->assertUnprocessable();

        $deuxAS = $this->nodeId('grade', '2AS');
        $this->postJson("/api/referential/nodes/$deuxAS/archive", ['reason' => 'Fermeture'])->assertStatus(409)->assertJsonPath('code', 'node_has_children');
        foreach (collect($this->getJson('/api/referential/nodes?parent_id='.$deuxAS)->json('data'))->pluck('id') as $track) {
            $this->postJson("/api/referential/nodes/$track/archive", ['reason' => 'Fermeture'])->assertOk();
        }
        $this->postJson("/api/referential/nodes/$deuxAS/archive", ['reason' => 'Fermeture'])->assertOk()->assertJsonPath('data.archived_at', fn ($at) => $at !== null);
        $this->getJson('/api/referential/nodes?type=grade')->assertJsonMissing(['id' => $deuxAS]);
        $this->getJson('/api/referential/nodes?type=grade&include_archived=1')->assertJsonFragment(['id' => $deuxAS]);
    }

    public function test_an_unused_subject_is_deleted_a_used_one_archived(): void
    {
        [$school] = $this->schoolWithProfile();
        // FR-REF-03 AC1.
        $id = $this->postJson('/api/subjects', ['code' => 'ROBOT', 'labels' => ['fr' => 'Robotique', 'ar' => 'الروبوتيك']])->assertCreated()->json('data.id');
        $this->deleteJson("/api/subjects/$id")->assertNoContent();
        $this->postJson('/api/subjects', ['code' => 'MATHS', 'labels' => ['fr' => ' mathématiques ']])->assertStatus(409)->assertJsonPath('code', 'duplicate');
        // FR-REF-03 AC2, with the first reference there is: a role's scope.
        $math = collect($this->getJson('/api/subjects')->json('data'))->firstWhere('code', 'MATH')['id'];
        $teacher = $this->account('teacher');
        $this->member($school, $teacher);
        $this->postJson('/api/role-assignments', ['user_id' => $teacher['id'], 'role_id' => $this->roleId($school, 'teacher'),
            'scope_type' => 'subject', 'scope_ids' => [$math]])->assertCreated();
        $this->deleteJson("/api/subjects/$math")->assertStatus(409)->assertJsonPath('code', 'subject_in_use');
        $this->postJson("/api/subjects/$math/archive", ['reason' => 'Plus enseignée'])->assertOk();
    }

    public function test_years_have_one_current_and_periods_never_overlap_and_a_closed_year_is_final(): void
    {
        $this->schoolWithProfile();
        $year = $this->postJson('/api/years', ['code' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'current'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/years', ['code' => '2027-2028', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'status' => 'current'])
            ->assertStatus(409)->assertJsonPath('code', 'duplicate');
        $this->postJson("/api/years/$year/periods", ['code' => 'S1', 'labels' => ['fr' => '1er semestre'], 'start_date' => '2026-09-01', 'end_date' => '2027-01-31'])
            ->assertCreated();
        // FR-REF-05 AC1.
        $this->postJson("/api/years/$year/periods", ['code' => 'S2', 'labels' => ['fr' => '2e semestre'], 'start_date' => '2027-01-15', 'end_date' => '2027-06-30'])
            ->assertStatus(409)->assertJsonPath('code', 'overlap');
        $s2 = $this->postJson("/api/years/$year/periods", ['code' => 'S2', 'labels' => ['fr' => '2e semestre'], 'start_date' => '2027-02-01', 'end_date' => '2027-06-30'])
            ->assertCreated()->json('data.id');
        $this->getJson('/api/years')->assertOk()->assertJsonCount(2, 'data.0.periods');
        $this->postJson("/api/years/$year/status", ['status' => 'closed'])->assertUnprocessable();
        $this->postJson("/api/years/$year/status", ['status' => 'closed', 'reason' => 'Fin d’année'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->putJson("/api/periods/$s2", ['labels' => ['fr' => 'Autre'], 'start_date' => '2027-02-01', 'end_date' => '2027-06-30', 'sort_order' => 2, 'version' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'year_closed');
    }

    public function test_custom_fields_keep_their_values_meaning(): void
    {
        $this->schoolWithProfile();
        $field = $this->postJson('/api/custom-fields', ['entity' => 'student', 'key' => 'blood_group', 'field_type' => 'select', 'labels' => ['fr' => 'Groupe sanguin'],
            'options' => [['code' => 'A_POS', 'labels' => ['fr' => 'A+']], ['code' => 'O_NEG', 'labels' => ['fr' => 'O−']]]])
            ->assertCreated()->assertJsonPath('data.options.1.code', 'O_NEG')->json('data.id');
        $this->putJson("/api/custom-fields/$field", ['labels' => ['fr' => 'Groupe sanguin'], 'options' => [['code' => 'A_POS', 'labels' => ['fr' => 'A+']]], 'version' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'option_removed');
        $this->postJson('/api/custom-fields', ['entity' => 'student', 'key' => 'blood_group', 'field_type' => 'text', 'labels' => ['fr' => 'Doublon']])
            ->assertStatus(409)->assertJsonPath('code', 'duplicate');
        $this->getJson('/api/custom-fields?entity=student')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_school_settings_cover_calendar_languages_and_receipts(): void
    {
        $this->schoolWithProfile();
        $settings = collect($this->getJson('/api/settings')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame([0, 1, 2, 3, 4], $settings['calendar.open_days']['value']);
        $this->assertSame(['fr', 'ar'], $settings['locale.languages']['value']);
        $this->putJson('/api/settings/calendar.open_days', ['value' => [0, 1, 2, 3, 4, 6]])->assertOk()->assertJsonPath('data.value', [0, 1, 2, 3, 4, 6]);
        $this->putJson('/api/settings/calendar.open_days', ['value' => [8]])->assertStatus(409)->assertJsonPath('code', 'setting_invalid');
        $this->putJson('/api/settings/identity.phone', ['value' => '023 00 00 00'])->assertOk();
        $this->putJson('/api/settings/unknown.key', ['value' => 1])->assertNotFound();
    }

    public function test_the_referential_is_read_by_members_and_managed_by_its_permissions(): void
    {
        [$school] = $this->schoolWithProfile();
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($teacher);
        $this->getJson('/api/referential/nodes?type=grade')->assertOk()->assertJsonCount(15, 'data');
        $this->getJson('/api/subjects')->assertOk();
        $this->postJson('/api/referential/nodes', ['type' => 'cycle', 'code' => 'X', 'labels' => ['fr' => 'X']])->assertForbidden();
        $this->postJson('/api/subjects', ['code' => 'X', 'labels' => ['fr' => 'X']])->assertForbidden();
        $this->postJson('/api/years', ['code' => 'X', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'planned'])->assertForbidden();
    }
}
