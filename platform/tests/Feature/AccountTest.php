<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The school's accounts through the API (FR-IAM-01). */
final class AccountTest extends TestCase
{
    /** @return array{0: object, 1: array} a school and its signed-in administrator */
    private function administeredSchool(): array
    {
        [$school, $admin] = [$this->school(), $this->account('admin')];
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->signIn($admin)->assertOk();
        return [$school, $admin];
    }

    public function test_suspension_closes_access_at_once_and_revocation_is_final(): void
    {
        [$school, $admin] = $this->administeredSchool();
        $teacher = $this->postJson('/api/accounts', ['name' => 'Yasmine T.', 'username' => 'yasmine.t', 'method' => 'password'])->assertCreated();
        $id = $teacher->json('data.id');
        $this->deleteJson('/api/session');
        $this->postJson('/api/session', ['login' => 'yasmine.t', 'school' => $school->slug, 'password' => $teacher->json('provisional_password')])->assertOk();
        $this->putJson('/api/account/password', ['current_password' => $teacher->json('provisional_password'),
            'password' => 'Le cèdre de Chréa 2026', 'password_confirmation' => 'Le cèdre de Chréa 2026'])->assertNoContent();
        $this->getJson('/api/school')->assertOk();

        $this->signIn($admin);
        $this->postJson("/api/accounts/$id/status", ['status' => 'suspended', 'reason' => ''])->assertUnprocessable();
        $this->postJson("/api/accounts/$id/status", ['status' => 'suspended', 'reason' => 'Contrat suspendu'])->assertOk()->assertJsonPath('data.status', 'suspended');
        // FR-IAM-01 AC1: the very next request of the suspended account fails.
        $this->deleteJson('/api/session');
        $this->postJson('/api/session', ['login' => 'yasmine.t', 'school' => $school->slug, 'password' => 'Le cèdre de Chréa 2026'])
            ->assertForbidden()->assertJsonPath('code', 'no_school');

        $this->signIn($admin);
        $this->postJson("/api/accounts/$id/status", ['status' => 'active', 'reason' => 'Reprise'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/accounts/$id/status", ['status' => 'revoked', 'reason' => 'Départ'])->assertOk();
        $this->postJson("/api/accounts/$id/status", ['status' => 'active', 'reason' => 'Retour'])->assertStatus(409)->assertJsonPath('code', 'membership_revoked');
        $this->postJson("/api/accounts/{$admin['id']}/status", ['status' => 'suspended', 'reason' => 'Test'])->assertStatus(409)->assertJsonPath('code', 'self_account');
        $this->assertSame(['INSERT', 'UPDATE', 'UPDATE', 'UPDATE'], array_column(DB::connection('owner')->select("SELECT action FROM school.audit_log
            WHERE object_type = 'membership' AND object_id = (SELECT id FROM school.membership WHERE tenant_id = ? AND user_id = ?) ORDER BY occurred_at",
            [$school->id, $id]), 'action'));
    }

    public function test_the_last_administrator_cannot_be_suspended(): void
    {
        [$school] = $this->administeredSchool();
        $admin = DB::connection('owner')->selectOne("SELECT user_id FROM school.role_assignment WHERE tenant_id = ?", [$school->id])->user_id;
        $clerk = $this->account('clerk');
        $this->member($school, $clerk);
        $role = $this->postJson('/api/roles', ['code' => 'accounts_clerk', 'labels' => ['fr' => 'Gestion des comptes'],
            'permissions' => ['user.account.read', 'user.account.suspend']])->assertCreated()->json('data.id');
        $this->postJson('/api/role-assignments', ['user_id' => $clerk['id'], 'role_id' => $role, 'scope_type' => 'tenant'])->assertCreated();
        $this->signIn($clerk);
        // FR-IAM-01 AC2.
        $this->postJson("/api/accounts/$admin/status", ['status' => 'suspended', 'reason' => 'Essai'])->assertStatus(409)->assertJsonPath('code', 'last_admin');
    }

    public function test_an_email_has_one_account_per_school_and_a_shared_person_keeps_their_identity(): void
    {
        Mail::fake();
        [$school] = $this->administeredSchool();
        $known = $this->account('known');
        $this->member($this->school(), $known);
        $this->postJson('/api/accounts', ['name' => 'Autre nom', 'email' => strtoupper($known['email']), 'method' => 'password'])
            ->assertCreated()->assertJsonPath('data.status', 'invited')->assertJsonPath('data.name', 'known')->assertJsonMissingPath('provisional_password');
        $this->postJson('/api/accounts', ['name' => 'Doublon', 'email' => $known['email'], 'method' => 'link'])->assertStatus(409)->assertJsonPath('code', 'account_exists');
        $this->putJson("/api/accounts/{$known['id']}", ['name' => 'Renommé', 'email' => $known['email']])->assertStatus(409)->assertJsonPath('code', 'account_shared');
        $this->putJson("/api/accounts/{$known['id']}", ['name' => 'known', 'email' => $known['email'], 'username' => 'connu'])
            ->assertOk()->assertJsonPath('data.username', 'connu');
        $this->postJson("/api/accounts/{$known['id']}/password")->assertStatus(409)->assertJsonPath('code', 'account_shared');
        $this->assertSame(1, DB::connection('owner')->table('users')->where('email', $known['email'])->count());
    }

    public function test_a_privileged_role_needs_an_email_and_nothing_is_kept_of_a_refused_request(): void
    {
        [$school] = $this->administeredSchool();
        $this->postJson('/api/accounts', ['name' => 'Sans e-mail', 'username' => 'sans.email', 'method' => 'password',
            'roles' => [['role_id' => $this->roleId($school, 'accountant'), 'scope_type' => 'tenant']]])
            ->assertStatus(409)->assertJsonPath('code', 'email_required');
        $this->assertSame(0, DB::connection('owner')->table('school.membership')->where('tenant_id', $school->id)->where('username', 'sans.email')->count());
        $this->postJson('/api/accounts', ['name' => 'Avec rôle', 'email' => 'role-'.uniqid().'@example.test', 'method' => 'password',
            'roles' => [['role_id' => $this->roleId($school, 'front_office'), 'scope_type' => 'tenant']]])->assertCreated();
    }

    public function test_importing_the_same_accounts_twice_creates_no_duplicate(): void
    {
        [$school] = $this->administeredSchool();
        $rows = array_map(fn ($i) => ['external_ref' => sprintf('EL-%04d', $i), 'name' => "Élève $i", 'username' => sprintf('eleve%04d', $i)], range(1, 500));
        // FR-IAM-01 AC3: 500 accounts with stable references, imported twice.
        $this->postJson('/api/accounts/import', ['rows' => $rows])->assertOk()->assertJsonPath('created', 500)->assertJsonPath('rejected', []);
        $this->postJson('/api/accounts/import', ['rows' => $rows])->assertOk()->assertJsonPath('created', 0)->assertJsonPath('unchanged', 500);
        $rows[0]['name'] = 'Élève Un';
        $rows[1]['username'] = 'eleve0003';
        $report = $this->postJson('/api/accounts/import', ['rows' => $rows])->assertOk()->assertJsonPath('updated', 1)->assertJsonPath('unchanged', 498);
        $this->assertSame([['row' => 2, 'external_ref' => 'EL-0002', 'code' => 'duplicate']],
            array_map(fn ($r) => array_intersect_key($r, ['row' => 1, 'external_ref' => 1, 'code' => 1]), $report->json('rejected')));
        $this->assertSame(501, DB::connection('owner')->table('school.membership')->where('tenant_id', $school->id)->count());
        $this->getJson('/api/accounts?status=invited')->assertOk()->assertJsonCount(500, 'data');
    }

    public function test_a_provisional_password_for_an_account_without_email(): void
    {
        [$school] = $this->administeredSchool();
        $this->postJson('/api/accounts/import', ['rows' => [['external_ref' => 'P-1', 'name' => 'Parent Un', 'username' => 'parent.un']]])->assertOk();
        $id = collect($this->getJson('/api/accounts')->json('data'))->firstWhere('username', 'parent.un')['id'];
        $provisional = $this->postJson("/api/accounts/$id/password")->assertOk()->assertJsonPath('data.status', 'active')->json('provisional_password');
        $this->deleteJson('/api/session');
        $this->postJson('/api/session', ['login' => 'parent.un', 'school' => $school->slug, 'password' => $provisional])
            ->assertOk()->assertJsonPath('password_change_required', true);
    }

    public function test_accounts_need_their_permissions(): void
    {
        $school = $this->school();
        $clerk = $this->account('clerk');
        $this->member($school, $clerk, 'active', ['front_office']);
        $this->signIn($clerk);
        $this->getJson('/api/accounts')->assertForbidden()->assertJsonPath('code', 'permission_denied');
        $this->postJson('/api/accounts', ['name' => 'X', 'username' => 'xxx', 'method' => 'password'])->assertForbidden();
        $this->getJson('/api/settings')->assertForbidden();
    }
}
