<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sign-in and choice of school (W1.1, ND-06). */
final class SessionTest extends TestCase
{
    public function test_requests_use_the_restricted_role(): void
    {
        $runtime = DB::selectOne('SELECT current_user AS role, r.rolbypassrls AS bypass, r.rolsuper AS super FROM pg_roles r WHERE r.rolname = current_user');
        $this->assertSame(['school_runtime', false, false], [$runtime->role, $runtime->bypass, $runtime->super]);
        $this->assertSame('platform_admin', DB::connection('console')->selectOne('SELECT current_user AS role')->role);
    }

    public function test_passwords_are_hashed_with_argon2id(): void
    {
        $account = $this->account();
        $this->assertStringStartsWith('$argon2id$', DB::connection('owner')->table('users')->where('id', $account['id'])->value('password'));
    }

    public function test_sign_in_opens_the_only_school(): void
    {
        $school = $this->school();
        $account = $this->account();
        $this->member($school, $account);
        $this->signIn($account)->assertOk()->assertJsonPath('selected', $school->id);
        $this->getJson('/api/school')->assertOk()->assertJsonPath('data.id', $school->id)->assertJsonPath('data.mode', 'active')
            ->assertJsonPath('data.modules', ['core', 'enrollment_groups', 'sites_rooms', 'student_finance', 'students_crm']);
    }

    public function test_a_member_of_several_schools_chooses_among_them(): void
    {
        [$a, $b, $c] = [$this->school(), $this->school(), $this->school()];
        $account = $this->account();
        $this->member($a, $account);
        $this->member($b, $account);
        $this->signIn($account)->assertOk()->assertJsonCount(2, 'schools')->assertJsonPath('selected', null);
        $this->getJson('/api/school')->assertStatus(409)->assertJsonPath('code', 'school_not_selected');
        $this->putJson('/api/session/school', ['tenant_id' => $c->id])->assertForbidden()->assertJsonPath('code', 'school_forbidden');
        $this->putJson('/api/session/school', ['tenant_id' => $b->id])->assertNoContent();
        $this->getJson('/api/school')->assertOk()->assertJsonPath('data.id', $b->id);
    }

    public function test_the_school_never_comes_from_the_request(): void
    {
        [$a, $b] = [$this->school(), $this->school()];
        $account = $this->account();
        $this->member($a, $account);
        $this->signIn($account)->assertOk();
        $this->withHeaders(['X-Tenant' => $b->id])->getJson('/api/school?tenant_id='.$b->id.'&tenant='.$b->id)
            ->assertOk()->assertJsonPath('data.id', $a->id);
    }

    public function test_no_open_school_means_no_session(): void
    {
        $school = $this->school();
        $invited = $this->account();
        $this->member($school, $invited, 'invited');
        $this->signIn($invited)->assertForbidden()->assertJsonPath('code', 'no_school');
        $this->getJson('/api/session/schools')->assertUnauthorized();
    }

    public function test_wrong_credentials_are_refused(): void
    {
        $account = $this->account();
        $this->postJson('/api/session', ['email' => $account['email'], 'password' => 'not-the-password'])
            ->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_a_dedicated_installation_serves_only_its_school(): void
    {
        [$a, $b] = [$this->school(), $this->school()];
        $account = $this->account();
        $this->member($a, $account);
        $this->member($b, $account);
        config(['platform.dedicated_school' => $a->slug]);
        $this->signIn($account)->assertOk()->assertJsonCount(1, 'schools')->assertJsonPath('selected', $a->id);
        $this->putJson('/api/session/school', ['tenant_id' => $b->id])->assertForbidden();
        $this->getJson('/api/school')->assertOk()->assertJsonPath('data.id', $a->id);
    }
}
