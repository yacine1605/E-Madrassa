<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Roles, permissions, scopes and segregation of duties through the API (FR-IAM-01, FR-IAM-03 to 05). */
final class AuthorizationTest extends TestCase
{
    /** A school whose administrator, invited at creation, has activated their account. */
    private function schoolWithAdmin(string $plan = 'essential'): array
    {
        $admin = $this->account('admin');
        $school = $this->school($plan, $admin);
        $this->member($school, $admin);
        return [$school, $admin];
    }

    private function assign(array $account, string $role, array $extra = [])
    {
        return $this->postJson('/api/role-assignments', ['user_id' => $account['id'], 'role_id' => $role, 'scope_type' => 'tenant', ...$extra]);
    }

    private function permissions(): array
    {
        return array_column($this->getJson('/api/me')->assertOk()->json('data.permissions'), 'permission');
    }

    public function test_a_member_without_a_role_may_do_nothing(): void
    {
        $school = $this->school();
        $account = $this->account();
        $this->member($school, $account);
        $this->signIn($account);
        $this->getJson('/api/school')->assertOk();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $account['id'])->assertJsonPath('data.permissions', []);
        $this->getJson('/api/sites')->assertForbidden()->assertJsonPath('code', 'permission_denied');
        $this->postJson('/api/sites', ['code' => 'X', 'labels' => ['fr' => 'X']])->assertForbidden()->assertJsonPath('code', 'permission_denied');
        $this->getJson('/api/roles')->assertForbidden()->assertJsonPath('code', 'permission_denied');
    }

    public function test_the_invited_administrator_manages_roles_within_the_schools_modules(): void
    {
        [, $admin] = $this->schoolWithAdmin();
        $this->signIn($admin)->assertOk();
        $this->assertContains('role.assignment.manage', $this->permissions());
        $roles = $this->getJson('/api/roles')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('data.0.code', 'tenant_admin')->json('data');
        $accountant = collect($roles)->firstWhere('code', 'accountant');
        $this->assertContains('finance.payment.validate', $accountant['permissions']);
        $this->assertNotContains('payroll.amount.read', $accountant['permissions']);
        $catalog = collect($this->getJson('/api/permissions')->assertOk()->json('data'));
        $this->assertTrue($catalog->firstWhere('code', 'finance.payment.validate')['sensitive']);
        $this->assertFalse($catalog->firstWhere('code', 'student.record.read')['sensitive']);
        $this->assertNull($catalog->firstWhere('module_key', 'payroll'));
    }

    public function test_roles_combine_and_a_change_stays_in_its_school(): void
    {
        [$a, $adminA] = $this->schoolWithAdmin();
        [$b, $adminB] = $this->schoolWithAdmin();
        $user = $this->account();
        $this->member($a, $user);
        $this->signIn($adminA);
        $this->assign($user, $this->roleId($a, 'front_office'))->assertCreated()->assertJsonPath('data.role_code', 'front_office');
        $this->assign($user, $this->roleId($a, 'accountant'))->assertCreated();

        // FR-IAM-03 AC2: the union of both roles.
        $this->signIn($user);
        $this->assertContains('crm.lead.create', $this->permissions());
        $this->assertContains('finance.payment.validate', $this->permissions());

        // FR-IAM-03 AC1: school A changes its front office role; school B's is untouched.
        $this->signIn($adminA);
        $frontOffice = collect($this->getJson('/api/roles')->json('data'))->firstWhere('code', 'front_office');
        $kept = array_values(array_diff($frontOffice['permissions'], ['crm.lead.create']));
        $this->putJson('/api/roles/'.$frontOffice['id'].'/permissions', ['permissions' => $kept])->assertOk()->assertJsonPath('data.permissions', $kept);
        $this->signIn($adminB);
        $this->assertContains('crm.lead.create', collect($this->getJson('/api/roles')->json('data'))->firstWhere('code', 'front_office')['permissions']);
        $this->signIn($user);
        $this->assertNotContains('crm.lead.create', $this->permissions());
        $this->assertContains('crm.lead.read', $this->permissions());
    }

    public function test_a_custom_role_takes_permissions_of_the_schools_modules_only(): void
    {
        [$school, $admin] = $this->schoolWithAdmin();
        $this->signIn($admin);
        $cashier = $this->postJson('/api/roles', ['code' => 'cashier', 'labels' => ['fr' => 'Caisse', 'ar' => 'الصندوق'],
            'copy_from' => $this->roleId($school, 'accountant')])->assertCreated()->assertJsonPath('data.kind', 'internal')->json('data');
        $this->assertContains('finance.payment.create', $cashier['permissions']);
        $this->assertNotContains('payroll.run.generate', $cashier['permissions']);
        $this->putJson('/api/roles/'.$cashier['id'].'/permissions', ['permissions' => ['finance.payment.create', 'payroll.amount.read']])
            ->assertStatus(409)->assertJsonPath('code', 'permission_module_disabled');
        $this->putJson('/api/roles/'.$cashier['id'].'/permissions', ['permissions' => ['finance.payment.create', 'nothing.at.all']])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->postJson('/api/roles', ['code' => 'cashier', 'labels' => ['fr' => 'Autre']])->assertStatus(409)->assertJsonPath('code', 'duplicate');
        $this->putJson('/api/roles/'.$cashier['id'], ['labels' => ['fr' => 'Caisse centrale'], 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_nobody_changes_their_own_roles(): void
    {
        [$school, $admin] = $this->schoolWithAdmin();
        $clerk = $this->account();
        $this->member($school, $clerk, 'active', ['front_office']);
        $this->signIn($admin);
        // FR-IAM-05: no assignment to oneself, no change to a role one holds.
        $this->assign($admin, $this->roleId($school, 'accountant'))->assertStatus(409)->assertJsonPath('code', 'self_assignment');
        $this->putJson('/api/roles/'.$this->roleId($school, 'tenant_admin').'/permissions', ['permissions' => ['audit.log.read']])
            ->assertStatus(409)->assertJsonPath('code', 'self_elevation');
        $this->signIn($clerk);
        $this->assign($clerk, $this->roleId($school, 'tenant_admin'))->assertForbidden()->assertJsonPath('code', 'permission_denied');
    }

    public function test_a_permission_held_for_some_sites_does_not_reach_school_wide_operations(): void
    {
        $school = $this->school();
        $local = $this->account();
        $this->member($school, $local);
        $main = DB::connection('owner')->selectOne("SELECT id FROM school.site WHERE tenant_id = ? AND code = 'MAIN'", [$school->id])->id;
        $this->grant($school, $local, 'tenant_admin', 'site', [$main]);
        $this->signIn($local);
        // The route opens (the permission is held somewhere); the database refuses outside its scope.
        $this->getJson('/api/roles')->assertOk();
        $this->postJson('/api/roles', ['code' => 'local', 'labels' => ['fr' => 'Local']])->assertForbidden()->assertJsonPath('code', 'permission_denied');
    }

    public function test_site_management_follows_the_scope_of_the_grant(): void
    {
        $school = $this->school();
        [$manager, $local] = [$this->account('manager'), $this->account('local')];
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->member($school, $local);
        $this->signIn($manager);
        $annex = $this->postJson('/api/sites', ['code' => 'ANNEXE', 'labels' => ['fr' => 'Annexe']])->assertCreated()->json('data.id');
        $main = collect($this->getJson('/api/sites')->json('data'))->firstWhere('code', 'MAIN')['id'];
        $this->grant($school, $local, 'academic_hr', 'site', [$annex]);

        // FR-IAM-04: the annex only, for changes; the referential stays readable.
        $this->signIn($local);
        $this->getJson('/api/sites')->assertOk()->assertJsonCount(2, 'data');
        $this->putJson('/api/sites/'.$annex, ['labels' => ['fr' => 'Annexe nord'], 'version' => 1])->assertOk();
        $this->putJson('/api/sites/'.$main, ['labels' => ['fr' => 'Siège'], 'version' => 1])->assertForbidden()->assertJsonPath('code', 'permission_denied');
        $this->postJson('/api/sites/'.$main.'/archive', ['reason' => 'Fermeture'])->assertForbidden();
        $this->postJson('/api/sites', ['code' => 'NOUVEAU', 'labels' => ['fr' => 'Nouveau']])->assertForbidden();
        $grant = collect($this->getJson('/api/me')->json('data.permissions'))->firstWhere('permission', 'site.site.manage');
        $this->assertSame(['site', [$annex]], [$grant['scope_type'], $grant['scope_ids']]);
    }

    public function test_scopes_are_checked_when_a_role_is_assigned(): void
    {
        [$school, $admin] = $this->schoolWithAdmin();
        [$other] = $this->schoolWithAdmin();
        $pupil = $this->account('pupil');
        $this->member($school, $pupil);
        $foreignSite = DB::connection('owner')->selectOne("SELECT id FROM school.site WHERE tenant_id = ? AND code = 'MAIN'", [$other->id])->id;
        $this->signIn($admin);
        $student = $this->roleId($school, 'student');
        // FR-IAM-04: a student role never covers the whole school.
        $this->assign($pupil, $student)->assertStatus(409)->assertJsonPath('code', 'scope_personal_required');
        $this->assign($pupil, $student, ['scope_type' => 'personal'])->assertCreated()->assertJsonPath('data.scope_ids', null);
        $this->assign($pupil, $this->roleId($school, 'supervisor'), ['scope_type' => 'site', 'scope_ids' => [$foreignSite]])
            ->assertStatus(409)->assertJsonPath('code', 'scope_unknown');
        $this->assign($pupil, $this->roleId($school, 'supervisor'), ['scope_ids' => [$foreignSite]])->assertUnprocessable();
        $outsider = $this->account();
        $this->assign($outsider, $this->roleId($school, 'teacher'))->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_an_assigned_role_is_archived_only_once_its_assignments_end(): void
    {
        [$school, $admin] = $this->schoolWithAdmin();
        $teacher = $this->account();
        $this->member($school, $teacher);
        $this->signIn($admin);
        $role = $this->roleId($school, 'teacher');
        $assignment = $this->assign($teacher, $role)->assertCreated()->json('data.id');
        $this->postJson('/api/roles/'.$role.'/archive', ['reason' => 'Réorganisation'])->assertStatus(409)->assertJsonPath('code', 'role_in_use');
        $this->postJson('/api/role-assignments/'.$assignment.'/archive', ['reason' => 'Fin de contrat'])->assertOk();
        $this->getJson('/api/role-assignments?user_id='.$teacher['id'])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/roles/'.$role.'/archive', ['reason' => 'Réorganisation'])->assertOk();
        $this->assign($teacher, $role)->assertStatus(409)->assertJsonPath('code', 'role_archived');
        $this->assertSame('Fin de contrat', DB::connection('owner')->selectOne("SELECT reason FROM school.audit_log
            WHERE object_type = 'role_assignment' AND object_id = ? AND action = 'UPDATE'", [$assignment])->reason);
    }

    public function test_an_assignment_applies_from_its_start_date(): void
    {
        [$school, $admin] = $this->schoolWithAdmin();
        $user = $this->account();
        $this->member($school, $user);
        $this->signIn($admin);
        $this->assign($user, $this->roleId($school, 'accountant'), ['valid_from' => now()->addDay()->toDateString()])->assertCreated();
        $this->assign($user, $this->roleId($school, 'front_office'), ['valid_from' => '2026-01-01', 'valid_until' => '2025-12-31'])->assertUnprocessable();
        $this->signIn($user);
        $this->assertNotContains('finance.payment.read', $this->permissions());
    }

    public function test_an_administrator_hands_over_but_never_removes_their_own_role(): void
    {
        [$school, $first] = $this->schoolWithAdmin();
        $second = $this->account('second');
        $this->member($school, $second);
        $this->signIn($first);
        $this->assign($second, $this->roleId($school, 'tenant_admin'))->assertCreated();
        $this->signIn($second);
        $assignments = collect($this->getJson('/api/role-assignments')->assertOk()->json('data'));
        $mine = $assignments->firstWhere('user_id', $second['id'])['id'];
        $this->postJson('/api/role-assignments/'.$mine.'/archive', ['reason' => 'Départ'])->assertStatus(409)->assertJsonPath('code', 'self_assignment');
        $this->postJson('/api/role-assignments/'.$assignments->firstWhere('user_id', $first['id'])['id'].'/archive', ['reason' => 'Passation'])->assertOk();
        $this->signIn($first);
        $this->getJson('/api/roles')->assertForbidden();
    }
}
