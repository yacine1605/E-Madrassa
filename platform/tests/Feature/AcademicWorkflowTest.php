<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The lot 2 admission workflow through real routes, restricted roles and production triggers. */
final class AcademicWorkflowTest extends TestCase
{
    private function manager(): array
    {
        $manager = $this->account('school-workflow');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(), 'status' => 'planned'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $site = DB::connection('owner')->selectOne('SELECT id FROM school.site WHERE tenant_id = ? LIMIT 1', [$school->id])->id;
        return [$school, $manager, $year, $node, $site];
    }

    private function program(string $year, string $node, array $extra = []): array
    {
        return $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => Str::upper(Str::random(8)),
            'labels' => ['fr' => 'Sciences'], 'base_price' => '120000.50', 'payment_terms' => [['code' => 'INITIAL']], 'status' => 'active', ...$extra])
            ->assertCreated()->json('data');
    }

    private function group(array $program, string $site, array $extra = []): array
    {
        return $this->postJson('/api/groups', ['year_id' => $program['year_id'], 'program_id' => $program['id'], 'site_id' => $site,
            'code' => Str::upper(Str::random(8)), 'labels' => ['fr' => 'Classe'], 'group_type' => 'class', 'capacity' => 25,
            'valid_from' => now()->toDateString(), 'status' => 'active', ...$extra])->assertCreated()->json('data');
    }

    private function pupil(array $extra = []): array
    {
        return $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => Str::random(12),
            'birth_date' => '1990-01-01', ...$extra])->assertCreated()->json('data');
    }

    private function enroll(array $student, array $program, ?string $key = null): array
    {
        return $this->postJson('/api/enrollments', ['student_id' => $student['id'], 'program_id' => $program['id'],
            'idempotency_key' => $key ?? (string) Str::uuid()])->assertCreated()->json('data');
    }

    private function assign(array $enrollment, array $group): array
    {
        return $this->postJson('/api/groups/'.$group['id'].'/memberships', ['enrollment_id' => $enrollment['id']])->assertCreated()->json('data');
    }

    public function test_minor_admission_reaches_a_validated_group_with_guardian_and_price_history(): void
    {
        [$school, , $year, $node, $site] = $this->manager();
        $program = $this->program($year, $node);
        $group = $this->group($program, $site);
        $student = $this->pupil(['birth_date' => '2012-01-01']);
        $input = ['student_id' => $student['id'], 'program_id' => $program['id'], 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/api/enrollments', $input)->assertConflict()->assertJsonPath('code', 'primary_contact_required');
        $guardian = $this->postJson('/api/guardians', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0550123456'])->assertCreated()->json('data');
        $this->postJson('/api/students/'.$student['id'].'/guardians', ['guardian_id' => $guardian['id'], 'relationship' => 'father', 'is_primary_contact' => true])->assertCreated();
        $enrollment = $this->postJson('/api/enrollments', $input)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->postJson('/api/enrollments/'.$enrollment['id'].'/validate', ['version' => 1, 'reason' => 'Dossier complet'])
            ->assertConflict()->assertJsonPath('code', 'main_group_required');
        $membership = $this->assign($enrollment, $group);
        $this->assertTrue($membership['is_main']);
        $this->postJson('/api/enrollments/'.$enrollment['id'].'/validate', ['version' => 1, 'reason' => 'Dossier complet'])
            ->assertOk()->assertJsonPath('data.status', 'validated')->assertJsonPath('data.version', 2);
        $this->getJson('/api/groups/'.$group['id'].'/memberships')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.enrollment_id', $enrollment['id']);
        $this->getJson('/api/enrollments/'.$enrollment['id'].'/history')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.to_status', 'validated');
        $this->assertSame(1, DB::connection('owner')->table('school.enrollment')->where('tenant_id', $school->id)->count());
    }

    public function test_an_enrolled_minor_keeps_a_primary_contact_with_a_phone(): void
    {
        [, , $year, $node] = $this->manager();
        $program = $this->program($year, $node);
        $student = $this->pupil(['birth_date' => '2012-01-01']);
        $father = $this->postJson('/api/guardians', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0550123456'])->assertCreated()->json('data');
        $link = $this->postJson('/api/students/'.$student['id'].'/guardians', ['guardian_id' => $father['id'], 'relationship' => 'father', 'is_primary_contact' => true])
            ->assertCreated()->json('data');
        $this->enroll($student, $program);
        // FR-ELV-02: the last primary contact, their phone or their link stay while the minor is enrolled.
        $this->putJson('/api/students/'.$student['id'].'/guardians/'.$link['id'], ['relationship' => 'father', 'is_primary_contact' => false, 'version' => 1])
            ->assertConflict()->assertJsonPath('code', 'primary_contact_kept')->assertJsonPath('message', __('errors.primary_contact_kept'));
        $this->postJson('/api/students/'.$student['id'].'/guardians/'.$link['id'].'/archive', ['reason' => 'Erreur de saisie', 'version' => 1])
            ->assertConflict()->assertJsonPath('code', 'primary_contact_kept');
        $this->putJson('/api/guardians/'.$father['id'], ['phone' => null, 'version' => 1])->assertConflict()->assertJsonPath('code', 'primary_contact_kept');
        // Another primary contact named first, the father steps back.
        $mother = $this->postJson('/api/guardians', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Mina', 'phone' => '0661223344'])->assertCreated()->json('data');
        $this->postJson('/api/students/'.$student['id'].'/guardians', ['guardian_id' => $mother['id'], 'relationship' => 'mother', 'is_primary_contact' => true])->assertCreated();
        $this->putJson('/api/students/'.$student['id'].'/guardians/'.$link['id'], ['relationship' => 'father', 'is_primary_contact' => false, 'version' => 1])
            ->assertOk()->assertJsonPath('data.is_primary_contact', false);
        $this->putJson('/api/guardians/'.$father['id'], ['phone' => null, 'version' => 1])->assertOk();
    }

    public function test_a_transfer_cancels_the_enrolment_it_leaves_and_links_the_one_it_goes_to_once(): void
    {
        [$school, , $year, $node, $site] = $this->manager();
        $from = $this->program($year, $node);
        $to = $this->program($year, $node, ['base_price' => '95000.00']);
        $classFrom = $this->group($from, $site);
        $classTo = $this->group($to, $site);
        $student = $this->pupil();
        $source = $this->enroll($student, $from);
        $this->assign($source, $classFrom);
        $source = $this->postJson('/api/enrollments/'.$source['id'].'/validate', ['version' => 1, 'reason' => 'Dossier complet'])->assertOk()->json('data');
        $key = (string) Str::uuid();
        $transfer = fn (array $extra = []) => $this->postJson('/api/enrollments/'.$source['id'].'/transfer', ['program_id' => $to['id'],
            'idempotency_key' => $key, 'version' => $source['version'], 'reason' => 'Changement de filière', ...$extra]);
        $transfer(['program_id' => $from['id']])->assertConflict()->assertJsonPath('code', 'transfer_same_program');
        $transfer(['version' => 1])->assertConflict()->assertJsonPath('code', 'stale_version');
        $inactive = $this->program($year, $node, ['status' => 'inactive']);
        $transfer(['program_id' => $inactive['id']])->assertConflict()->assertJsonPath('code', 'program_not_active');
        $this->getJson('/api/enrollments/'.$source['id'])->assertOk()->assertJsonPath('data.status', 'validated')->assertJsonPath('data.main_group.id', $classFrom['id']);
        // FR-INS-03 AC2: the source cancelled with the reason and its place given back, the target pending at its price, linked.
        $response = $transfer()->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.transferred_from', $source['id'])
            ->assertJsonPath('data.terms_snapshot.base_price', '95000.00')->assertJsonPath('data.transfer_source.code', $from['code'])
            ->assertJsonPath('previous.status', 'cancelled')->assertJsonPath('previous.status_reason', 'Changement de filière');
        $target = $response->json('data.id');
        $response->assertJsonPath('previous.transfer_target.id', $target);
        $this->getJson('/api/groups/'.$classFrom['id'].'/memberships')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/enrollments/'.$source['id'].'/history')->assertOk()->assertJsonPath('data.2.to_status', 'cancelled')
            ->assertJsonPath('data.2.reason', 'Changement de filière');
        // Sent twice (a double click, a retry): the same transfer.
        $transfer()->assertCreated()->assertJsonPath('data.id', $target);
        $transfer(['idempotency_key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'enrollment_cancelled');
        $this->assertSame(2, DB::connection('owner')->table('school.enrollment')->where('tenant_id', $school->id)->count());
        $this->assign(['id' => $target], $classTo);
        $this->getJson('/api/enrollments/'.$target)->assertOk()->assertJsonPath('data.main_group.id', $classTo['id']);
        // The link is never sent by the client.
        $this->postJson('/api/enrollments', ['student_id' => $student['id'], 'program_id' => $from['id'], 'idempotency_key' => (string) Str::uuid(),
            'transferred_from' => $source['id']])->assertUnprocessable();
    }

    public function test_exact_prices_version_and_snapshot_survive_catalogue_changes_and_idempotent_retries(): void
    {
        [, , $year, $node] = $this->manager();
        $program = $this->program($year, $node, ['base_price' => '999999999999.99']);
        $student = $this->pupil();
        $key = (string) Str::uuid();
        $enrollment = $this->enroll($student, $program, $key);
        $this->assertSame('999999999999.99', $enrollment['agreed_price']);
        $this->assertSame('999999999999.99', $enrollment['terms_snapshot']['base_price']);
        $path = '/api/programs/'.$program['id'];
        $this->putJson($path, ['base_price' => '130000.25', 'payment_terms' => [], 'version' => 1])->assertOk()->assertJsonPath('data.price_version', 2);
        $this->putJson($path, ['base_price' => '1.00', 'version' => 1])->assertConflict()->assertJsonPath('code', 'stale_version');
        $this->putJson($path, ['base_price' => '1.001', 'version' => 2])->assertUnprocessable();
        $this->putJson($path, ['base_price' => 1.50, 'version' => 2])->assertUnprocessable();
        $this->putJson($path, ['base_price' => '1e3', 'version' => 2])->assertUnprocessable();
        $this->putJson($path, ['price_version' => 999, 'version' => 2])->assertUnprocessable();
        $nested = ['code' => 'NESTED'];
        for ($i = 0; $i < 20; $i++) $nested = ['nested' => $nested];
        $this->putJson($path, ['payment_terms' => [$nested], 'version' => 2])->assertUnprocessable()->assertJsonValidationErrors('payment_terms');
        $this->putJson($path, ['year_id' => (string) Str::uuid(), 'version' => 2])->assertUnprocessable();
        $this->getJson($path.'/prices')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.base_price', '130000.25');
        $retry = $this->enroll($student, $program, $key);
        $this->assertSame($enrollment['id'], $retry['id']);
        $this->assertSame('999999999999.99', $retry['agreed_price']);
        $this->assertSame(1, $retry['price_version']);
        $this->postJson('/api/enrollments', ['student_id' => $student['id'], 'program_id' => $program['id'], 'idempotency_key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'duplicate');
        $other = $this->pupil();
        $this->postJson('/api/enrollments', ['student_id' => $other['id'], 'program_id' => $program['id'], 'idempotency_key' => $key])
            ->assertConflict()->assertJsonPath('code', 'idempotency_key_reused');
        $this->postJson('/api/enrollments', ['student_id' => $other['id'], 'program_id' => $program['id'], 'idempotency_key' => (string) Str::uuid(), 'agreed_price' => '0.00'])
            ->assertUnprocessable();
        $this->putJson($path, ['status' => 'inactive', 'version' => 2])->assertOk();
        $this->getJson('/api/programs?status=active')->assertJsonPath('meta.total', 0);
        $this->postJson('/api/enrollments', ['student_id' => $other['id'], 'program_id' => $program['id'], 'idempotency_key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'program_not_active');
        $this->getJson('/api/enrollments/'.$enrollment['id'])->assertJsonPath('data.status', 'pending');
    }

    public function test_group_capacity_and_transfers_are_atomic_versioned_and_keep_history(): void
    {
        [, , $year, $node, $site] = $this->manager();
        $program = $this->program($year, $node);
        $source = $this->group($program, $site, ['capacity' => 2]);
        $full = $this->group($program, $site, ['capacity' => 1]);
        $target = $this->group($program, $site);
        $a = $this->enroll($this->pupil(), $program);
        $b = $this->enroll($this->pupil(), $program);
        $c = $this->enroll($this->pupil(), $program);
        $first = $this->assign($a, $source);
        $this->assign($b, $source);
        $this->assign($c, $full);
        $this->putJson('/api/groups/'.$source['id'], ['capacity' => 1, 'version' => 1])->assertConflict()->assertJsonPath('code', 'capacity_below_members');
        $this->postJson('/api/groups/'.$target['id'].'/memberships', ['enrollment_id' => $a['id']])->assertConflict()->assertJsonPath('code', 'duplicate');
        $path = '/api/group-memberships/'.$first['id'];
        $this->postJson($path.'/transfer', ['group_id' => $full['id'], 'version' => 1, 'reason' => 'Changement de classe'])
            ->assertConflict()->assertJsonPath('code', 'group_full');
        $this->getJson('/api/groups/'.$source['id'].'/memberships')->assertJsonPath('meta.total', 2);
        $this->postJson($path.'/transfer', ['group_id' => $source['id'], 'version' => 1, 'reason' => 'Même classe'])
            ->assertConflict()->assertJsonPath('code', 'group_unchanged');
        $moved = $this->postJson($path.'/transfer', ['group_id' => $target['id'], 'version' => 1, 'reason' => 'Changement de classe'])
            ->assertOk()->assertJsonPath('previous.valid_to', now()->toDateString())->assertJsonPath('previous.version', 2)->json('data');
        $this->getJson('/api/groups/'.$source['id'].'/memberships')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/groups/'.$source['id'].'/memberships?include_history=1')->assertJsonPath('meta.total', 2);
        $this->postJson($path.'/end', ['version' => 1, 'reason' => 'Ancienne version'])->assertConflict()->assertJsonPath('code', 'stale_version');
        $this->postJson('/api/group-memberships/'.$moved['id'].'/end', ['version' => 1, 'reason' => 'Fin de groupe'])->assertOk();
        $this->getJson('/api/groups/'.$target['id'].'/memberships')->assertJsonPath('meta.total', 0);
        $this->putJson('/api/groups/'.$target['id'], ['status' => 'closed', 'version' => 1])->assertOk();
        $this->putJson('/api/groups/'.$target['id'], ['capacity' => 30, 'version' => 2])->assertConflict()->assertJsonPath('code', 'group_closed');
        $this->postJson('/api/groups/'.$target['id'].'/memberships', ['enrollment_id' => $a['id']])->assertConflict()->assertJsonPath('code', 'group_not_active');
    }

    public function test_teaching_states_require_their_own_actions_versions_and_reasons_and_cancel_releases_places(): void
    {
        [$school, , $year, $node, $site] = $this->manager();
        $program = $this->program($year, $node);
        $group = $this->group($program, $site);
        $enrollment = $this->enroll($this->pupil(), $program);
        $this->assign($enrollment, $group);
        $path = '/api/enrollments/'.$enrollment['id'];
        $this->postJson($path.'/validate', ['version' => 1, 'reason' => 'Admission confirmée'])->assertOk();
        $this->postJson($path.'/suspend', ['version' => 2])->assertUnprocessable();
        $this->postJson($path.'/suspend', ['version' => 1, 'reason' => 'Absence prolongée'])->assertConflict()->assertJsonPath('code', 'stale_version');
        $this->postJson($path.'/suspend', ['version' => 2, 'reason' => 'Absence prolongée'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson($path.'/validate', ['version' => 3, 'reason' => 'Retour confirmé'])->assertOk()->assertJsonPath('data.status', 'validated');
        $this->postJson($path.'/cancel', ['version' => 4, 'reason' => 'Départ définitif'])->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.version', 5);
        $this->getJson('/api/groups/'.$group['id'].'/memberships')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/groups/'.$group['id'].'/memberships?include_history=1')->assertJsonPath('meta.total', 1);
        $this->getJson($path.'/history')->assertJsonPath('meta.total', 5)->assertJsonPath('data.4.reason', 'Départ définitif');
        $this->postJson($path.'/validate', ['version' => 5, 'reason' => 'Retour tardif'])->assertConflict()->assertJsonPath('code', 'enrollment_cancelled');
        $this->postJson('/api/groups/'.$group['id'].'/memberships', ['enrollment_id' => $enrollment['id']])->assertConflict()->assertJsonPath('code', 'enrollment_not_assignable');
        $entry = DB::connection('owner')->selectOne("SELECT reason FROM school.audit_log WHERE tenant_id = ? AND object_id = ? AND action = 'UPDATE' ORDER BY occurred_at DESC LIMIT 1", [$school->id, $enrollment['id']]);
        $this->assertSame('Départ définitif', $entry->reason);
        $this->deleteJson($path)->assertStatus(405);
    }

    public function test_catalogue_and_membership_lists_paginate_filter_and_validate_partial_changes(): void
    {
        [, , $year, $node, $site] = $this->manager();
        $program = $this->program($year, $node, ['labels' => ['fr' => 'Mathématiques'], 'duration_value' => 12, 'duration_unit' => 'months']);
        for ($i = 0; $i < 26; $i++) $this->program($year, $node, ['status' => 'draft']);
        $this->getJson('/api/programs?paginate=0')->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 27);
        $this->getJson('/api/programs?page=2')->assertJsonCount(2, 'data');
        $this->getJson('/api/programs?per_page=101')->assertUnprocessable();
        $this->getJson('/api/programs?q=MATHEMATIQUES&status=active&year_id='.$year)->assertJsonCount(1, 'data');
        $this->putJson('/api/programs/'.$program['id'], ['duration_unit' => null, 'version' => 1])->assertUnprocessable();
        $this->putJson('/api/programs/'.$program['id'], ['duration_unit' => null, 'duration_value' => null, 'version' => 1])->assertOk();
        $this->putJson('/api/programs/'.$program['id'], ['labels' => ['ar' => 'رياضيات'], 'version' => 2])->assertOk();
        $group = $this->group($program, $site);
        $this->getJson('/api/groups?site_id='.$site.'&program_id='.$program['id'])->assertJsonPath('meta.total', 1);
        $this->putJson('/api/groups/'.$group['id'], ['valid_to' => now()->subDay()->toDateString(), 'version' => 1])->assertUnprocessable();
        $this->putJson('/api/groups/'.$group['id'], ['site_id' => (string) Str::uuid(), 'version' => 1])->assertUnprocessable();
        $enrollment = $this->enroll($this->pupil(), $program);
        $this->assign($enrollment, $group);
        $this->getJson('/api/groups/'.$group['id'].'/memberships?paginate=0&per_page=1')->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/enrollments?student_id='.$enrollment['student_id'].'&status=pending')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/enrollments?status=paid')->assertUnprocessable();
    }

    public function test_resource_scopes_apply_to_actions_prices_and_rosters_and_cancellation_can_remove_read_access(): void
    {
        [$school, , $year, $node, $site] = $this->manager();
        $a = $this->program($year, $node);
        $b = $this->program($year, $node);
        $c = $this->program($year, $node);
        $groupA = $this->group($a, $site);
        $groupB = $this->group($b, $site);
        $groupC = $this->group($c, $site);
        $ea = $this->enroll($this->pupil(), $a);
        $eb = $this->enroll($this->pupil(), $b);
        $ec = $this->enroll($this->pupil(), $c);
        $this->assign($ea, $groupA);
        $this->assign($eb, $groupB);
        $this->assign($ec, $groupC);
        $editor = $this->account('scoped-workflow');
        $this->member($school, $editor);
        $this->grant($school, $editor, 'academic_hr', 'group', [$groupA['id']]);
        $this->grant($school, $editor, 'front_office', 'group', [$groupB['id']]);
        $this->signIn($editor)->assertOk();
        $this->getJson('/api/programs')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/groups')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/enrollments')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/programs/'.$c['id'].'/prices')->assertNotFound();
        $this->getJson('/api/groups/'.$groupC['id'].'/memberships')->assertNotFound();
        $this->getJson('/api/enrollments/'.$ec['id'].'/history')->assertNotFound();
        $this->putJson('/api/programs/'.$a['id'], ['base_price' => '99.99', 'version' => 1])->assertOk();
        $this->putJson('/api/programs/'.$b['id'], ['base_price' => '99.99', 'version' => 1])->assertNotFound();
        $this->putJson('/api/groups/'.$groupA['id'], ['capacity' => 30, 'version' => 1])->assertOk();
        $this->putJson('/api/groups/'.$groupB['id'], ['capacity' => 30, 'version' => 1])->assertNotFound();
        $this->postJson('/api/enrollments/'.$eb['id'].'/cancel', ['version' => 1, 'reason' => 'Hors portée'])->assertForbidden();
        $this->postJson('/api/enrollments', ['student_id' => $ea['student_id'], 'program_id' => $a['id'], 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->postJson('/api/groups/'.$groupA['id'].'/memberships', ['enrollment_id' => $ea['id']])->assertForbidden();
        $this->postJson('/api/programs', [])->assertForbidden();
        $this->postJson('/api/enrollments/'.$ea['id'].'/cancel', ['version' => 1, 'reason' => 'Fin de parcours'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson('/api/enrollments/'.$ea['id'])->assertNotFound();
        $this->getJson('/api/enrollments')->assertJsonPath('meta.total', 1);
    }

    public function test_foreign_references_missing_permissions_and_disabled_modules_do_not_open_the_workflow(): void
    {
        [, , $year, $node, $site] = $this->manager();
        $program = $this->program($year, $node);
        $group = $this->group($program, $site);
        $enrollment = $this->enroll($this->pupil(), $program);
        [$school, $manager, $otherYear, $otherNode] = $this->manager();
        foreach (['/api/programs/'.$program['id'], '/api/groups/'.$group['id'].'/memberships', '/api/enrollments/'.$enrollment['id'].'/history', '/api/programs/invalid'] as $path)
            $this->getJson($path)->assertNotFound();
        $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $otherNode, 'code' => 'FOREIGN', 'labels' => ['fr' => 'Test']])->assertNotFound();
        $this->postJson('/api/enrollments', ['student_id' => $enrollment['student_id'], 'program_id' => $program['id'], 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $nobody = $this->account('without-workflow-rights');
        $this->member($school, $nobody);
        $this->signIn($nobody)->assertOk();
        foreach (['/api/programs', '/api/groups', '/api/enrollments'] as $path) $this->getJson($path)->assertForbidden();
        $this->signIn($manager)->assertOk();
        DB::connection('console')->select("SELECT platform.disable_module(?, 'student_finance')", [$school->id]);
        DB::connection('console')->select("SELECT platform.disable_module(?, 'enrollment_groups')", [$school->id]);
        $this->getJson('/api/programs')->assertForbidden()->assertJsonPath('code', 'module_disabled');
        $this->postJson('/api/enrollments', [])->assertForbidden()->assertJsonPath('code', 'module_disabled');
    }

    public function test_eligibility_years_and_group_dates_return_localized_domain_refusals(): void
    {
        [$school, , $year, $node, $site] = $this->manager();
        $program = $this->program($year, $node);
        $student = $this->pupil();
        $this->postJson('/api/students/'.$student['id'].'/status', ['status' => 'blocked', 'reason' => 'Dossier manquant', 'version' => 1])->assertOk();
        $this->putJson('/api/account/locale', ['locale' => 'ar'])->assertOk();
        $this->postJson('/api/enrollments', ['student_id' => $student['id'], 'program_id' => $program['id'], 'idempotency_key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'student_blocked')->assertJsonPath('message', __('errors.student_blocked', [], 'ar'));
        $this->postJson('/api/students/'.$student['id'].'/status', ['status' => 'active', 'reason' => 'Dossier reçu', 'version' => 2])->assertOk();
        $enrollment = $this->enroll($student, $program);
        $future = $this->group($program, $site, ['valid_from' => now()->addDay()->toDateString()]);
        $this->postJson('/api/groups/'.$future['id'].'/memberships', ['enrollment_id' => $enrollment['id']])
            ->assertConflict()->assertJsonPath('code', 'outside_group_dates');
        $other = $this->program($year, $node);
        $wrong = $this->group($other, $site);
        $this->postJson('/api/groups/'.$wrong['id'].'/memberships', ['enrollment_id' => $enrollment['id']])
            ->assertConflict()->assertJsonPath('code', 'group_other_program');
        // The calendar API closes a year only after its own allowed transition.
        $this->postJson('/api/years/'.$year.'/status', ['status' => 'current'])->assertOk();
        $this->postJson('/api/years/'.$year.'/status', ['status' => 'closed', 'reason' => 'Année terminée'])->assertOk();
        $this->putJson('/api/programs/'.$program['id'], ['description' => 'Modification tardive', 'version' => 1])
            ->assertConflict()->assertJsonPath('code', 'year_closed');
        $this->postJson('/api/enrollments/'.$enrollment['id'].'/cancel', ['reason' => 'Trop tard', 'version' => 1])
            ->assertConflict()->assertJsonPath('code', 'year_closed');
    }

    public function test_a_programme_s_subjects_keep_each_volume_and_end_or_go_while_nothing_uses_them(): void
    {
        [$school, , $year, $node] = $this->manager();
        $program = $this->program($year, $node);
        $subject = fn (string $code) => $this->postJson('/api/subjects', ['code' => $code, 'labels' => ['fr' => 'Module '.$code]])->assertCreated()->json('data.id');
        [$python, $sql] = [$subject('PYTHON'), $subject('SQL')];
        $lines = '/api/programs/'.$program['id'].'/subjects';
        // AC4: a programme without subjects; AC2: a line without any volume.
        $this->getJson($lines)->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson($lines, ['subject_id' => $python, 'session_duration_minutes' => 90])->assertConflict()->assertJsonPath('code', 'program_subject_volume_required');
        foreach ([['total_hours' => '40.555'], ['total_hours' => 0], ['weekly_hours' => 169], ['session_count' => 1.5], ['session_duration_minutes' => 10]] as $wrong)
            $this->postJson($lines, ['subject_id' => $python, ...$wrong])->assertUnprocessable();
        // AC1: « Python — 40 h — 20 séances », then 36 h: both versions kept.
        $line = $this->postJson($lines, ['subject_id' => $python, 'total_hours' => '40', 'session_count' => 20])->assertCreated()
            ->assertJsonPath('data.total_hours', '40.00')->assertJsonPath('data.session_duration_minutes', 60)->assertJsonPath('data.valid_from', now()->toDateString())
            ->assertJsonPath('data.subject.code', 'PYTHON')->assertJsonPath('data.volume_version', 1)
            // 40 h in 20 sessions are sessions of 120 min: a warning, never a refusal.
            ->assertJsonPath('data.implicit_minutes', '120.0')->assertJsonPath('data.duration_gap', true)->json('data');
        $this->postJson($lines, ['subject_id' => $python, 'weekly_hours' => 2])->assertConflict()->assertJsonPath('code', 'program_subject_overlap');
        $path = '/api/program-subjects/'.$line['id'];
        $this->putJson($path, ['total_hours' => 36, 'session_duration_minutes' => 110, 'version' => 1])->assertOk()
            ->assertJsonPath('data.volume_version', 2)->assertJsonPath('data.session_count', 20)->assertJsonPath('data.duration_gap', false);
        $this->putJson($path, ['total_hours' => 30, 'version' => 1])->assertConflict()->assertJsonPath('code', 'stale_version');
        $this->putJson($path, ['subject_id' => $sql, 'version' => 2])->assertUnprocessable();
        $this->getJson($path.'/volumes')->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.total_hours', '36.00')->assertJsonPath('data.1.total_hours', '40.00')->assertJsonPath('data.1.session_duration_minutes', 60);
        // AC3: an end of validity, final; an ended line leaves the list of what is taught.
        $this->postJson($path.'/end', ['valid_to' => now()->subDay()->toDateString(), 'version' => 2])
            ->assertConflict()->assertJsonPath('code', 'program_subject_end_before_start');
        $this->postJson($path.'/end', ['valid_to' => now()->toDateString(), 'version' => 2])->assertOk()->assertJsonPath('data.volume_version', 2);
        $this->putJson($path, ['total_hours' => 30, 'version' => 3])->assertConflict()->assertJsonPath('code', 'program_subject_ended');
        $old = $this->postJson($lines, ['subject_id' => $sql, 'weekly_hours' => '1.5', 'valid_from' => now()->startOfYear()->toDateString(),
            'valid_to' => now()->subDay()->toDateString()])->assertCreated()->json('data');
        $this->getJson($lines)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $line['id']);
        $this->getJson($lines.'?include_ended=1')->assertJsonPath('meta.total', 2);
        $this->postJson($lines, ['subject_id' => $sql, 'weekly_hours' => 2, 'valid_from' => now()->addYear()->toDateString()])
            ->assertConflict()->assertJsonPath('code', 'program_subject_outside_year');
        // A subject in a programme stays in the catalogue; a line nothing uses goes, with its versions.
        $this->deleteJson('/api/subjects/'.$sql)->assertConflict()->assertJsonPath('code', 'subject_in_use');
        $this->deleteJson('/api/program-subjects/'.$old['id'], ['version' => 2])->assertConflict()->assertJsonPath('code', 'stale_version');
        $this->deleteJson('/api/program-subjects/'.$old['id'], ['version' => 1])->assertNoContent();
        $this->getJson('/api/program-subjects/'.$old['id'].'/volumes')->assertNotFound();
        $this->deleteJson('/api/subjects/'.$sql)->assertNoContent();
        $this->assertSame(1, DB::connection('owner')->table('school.audit_log')->where('tenant_id', $school->id)
            ->where('object_type', 'program_subject')->where('object_id', $old['id'])->where('action', 'DELETE')->count());
        // The direction reads the subjects of a programme and changes none (section 3.2).
        $direction = $this->account('direction');
        $this->member($school, $direction, 'active', ['direction']);
        $this->signIn($direction)->assertOk();
        $this->getJson($lines)->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson($lines, ['subject_id' => $python, 'weekly_hours' => 2])->assertForbidden();
        $this->deleteJson($path, ['version' => 3])->assertForbidden();
    }
}
