<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Accounts;
use App\Filament\School\Pages\Assignments;
use App\Filament\School\Pages\Credentials;
use App\Filament\School\Pages\Roles;
use App\Filament\School\Pages\SchoolLogin;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/** W1.7: real panel pages AND real HTTP Livewire updates under school_runtime. */
final class SchoolPanelTest extends TestCase
{
    private function admin(): array
    {
        $admin = $this->account('admin');
        $school = $this->school('essential', $admin, 'private_school_dz');
        $this->member($school, $admin);
        return [$school, $admin];
    }

    /** For component assertions only. HTTP tests below exercise the actual middleware. */
    private function within(object $school, array $account, callable $test): void
    {
        $this->actingAs($this->person($account));
        $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('school'));
        DB::transaction(function () use ($school, $account, $test) {
            app(CurrentSchool::class)->enter($school->id, $account['id'], CurrentSchool::requestId(null));
            app(CurrentSchool::class)->setMode('active');
            $test();
        });
    }

    private function snapshot(string $page, string $component): string
    {
        $html = $this->get($page)->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        $names = [];
        foreach ($matches[1] as $raw) {
            $snapshot = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);
            $parsed = json_decode($snapshot, true);
            $names[] = $parsed['memo']['name'];
            // Filament registers full-page components using a generated alias in Livewire 4.
            if (isset($parsed['data']['schoolId']) && array_key_exists('tableSearch', $parsed['data'])) return $snapshot;
        }
        $this->fail('Page component snapshot missing: '.$component.'; aliases: '.implode(', ', $names));
    }

    private function update(string $snapshot, array $calls = [], array $updates = []): TestResponse
    {
        // Feature tests reuse the application instance across HTTP requests. A real PHP
        // request (or Octane's flush-state hook) starts with fresh Livewire mechanism state.
        app('livewire')->flushState();
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => $updates,
            'calls' => array_map(fn ($call) => ['path' => '', 'method' => $call[0], 'params' => $call[1] ?? []], $calls)]]], ['X-Livewire' => 'true']);
    }

    public function test_panel_entry_school_choice_and_permission_based_navigation(): void
    {
        $this->get('/school/login')->assertOk()->assertSee('Se connecter');
        $this->get('/school/accounts')->assertRedirect('/school/login');
        [$a, $admin] = $this->admin();
        $b = $this->school();
        $this->member($b, $admin, 'active', ['tenant_admin']);
        $this->signIn($admin)->assertOk()->assertJsonPath('selected', null);
        $this->get('/school/home')->assertRedirect('/school/choose-school');
        $this->get('/school/choose-school')->assertOk()->assertSee($a->slug)->assertSee($b->slug);
        $this->putJson('/api/session/school', ['tenant_id' => $a->id])->assertNoContent();
        foreach (['home', 'accounts', 'roles', 'assignments'] as $page) $this->get('/school/'.$page)->assertOk();

        $member = $this->account('reader');
        $this->member($a, $member);
        $this->signIn($member)->assertOk();
        $this->get('/school/home')->assertOk()->assertDontSee('href="http://localhost/school/accounts"', false);
        $this->get('/school/accounts')->assertForbidden();
        $this->get('/school/roles')->assertForbidden();
        $this->get('/school/assignments')->assertForbidden();
    }

    public function test_the_school_login_uses_the_same_mfa_and_lockout_as_the_api(): void
    {
        [$school, $admin] = $this->admin();
        Filament::setCurrentPanel(Filament::getPanel('school'));
        Livewire::test(SchoolLogin::class)->fillForm(['login' => $admin['email'], 'password' => $admin['password']])->call('authenticate')
            ->assertRedirect('/school/login');
        $this->assertGuest();
        $this->assertSame($admin['id'], session('auth.pending.user'));
        // The page that comes back asks for the code, and only for it.
        $this->get('/school/login')->assertOk()->assertSee(__('school.mfa_code'))->assertDontSee(__('school.login'));
        Livewire::test(SchoolLogin::class)->fillForm(['code' => $this->totp($admin)])->call('authenticate')->assertRedirect('/school/home');
        $this->assertAuthenticatedAs($this->person($admin));
        $this->assertSame($school->id, session('school.tenant'));
        $this->deleteJson('/api/session');
        for ($i = 0; $i < 5; $i++) Livewire::test(SchoolLogin::class)->fillForm(['login' => $admin['email'], 'password' => 'bad-password'])->call('authenticate');
        Livewire::test(SchoolLogin::class)->fillForm(['login' => $admin['email'], 'password' => $admin['password']])->call('authenticate')
            ->assertNotified(__('errors.too_many_attempts'));
        $this->assertGuest();
    }

    public function test_livewire_rechecks_a_permission_removed_after_the_screen_was_opened(): void
    {
        [$school, $admin] = $this->admin();
        $reader = $this->account('reader');
        $this->member($school, $reader);
        $this->signIn($admin);
        $this->postJson('/api/roles', ['code' => 'viewer', 'labels' => ['fr' => 'Lecture'], 'permissions' => ['user.account.read']])->assertCreated();
        $assignment = $this->grant($school, $reader, 'viewer');
        $this->signIn($reader);
        $snapshot = $this->snapshot('/school/accounts', 'accounts');
        DB::connection('owner')->table('school.role_assignment')->where('id', $assignment)->update(['archived_at' => now()]);
        $this->update($snapshot)->assertForbidden()->assertJsonPath('code', 'permission_denied');
    }

    public function test_accounts_are_isolated_and_search_is_normalized_and_paginated(): void
    {
        [$a, $admin] = $this->admin();
        [$b] = $this->admin();
        $local = $this->account('أحمد');
        $foreign = $this->account('foreign-secret');
        $this->member($a, $local);
        $this->member($b, $foreign);
        $this->signIn($admin);
        $this->get('/school/accounts')->assertOk()->assertSee($local['email'])->assertDontSee($foreign['email']);
        $this->getJson('/api/accounts?paginate=1&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/accounts?paginate=1&per_page=101')->assertUnprocessable();
        $this->within($a, $admin, function () use ($local, $foreign) {
            Livewire::test(Accounts::class)->searchTable('احمد')->assertSee($local['email'])->assertDontSee($foreign['email']);
        });
    }

    public function test_account_actions_create_invite_and_suspend_with_an_audit_reason(): void
    {
        Mail::fake();
        [$school, $admin] = $this->admin();
        $this->within($school, $admin, function () use ($school) {
            Livewire::test(Accounts::class)->callTableAction('create', data: ['name' => 'Nouveau compte', 'email' => 'new-'.$school->slug.'@example.test', 'method' => 'link'])
                ->assertNotified(__('school.invitation_sent'));
            $created = DB::table('users')->where('email', 'new-'.$school->slug.'@example.test')->value('id');
            $this->assertSame('invited', DB::table('school.membership')->where('user_id', $created)->value('status'));
        });
        $target = $this->account('target');
        $this->member($school, $target);
        $this->within($school, $admin, function () use ($target) {
            Livewire::test(Accounts::class)->callTableAction('suspend', (string) $target['id'], data: ['reason' => 'Départ confirmé'])
                ->assertNotified(__('school.saved'));
        });
        $this->assertSame('suspended', DB::connection('owner')->table('school.membership')->where('tenant_id', $school->id)->where('user_id', $target['id'])->value('status'));
        $this->assertSame('Départ confirmé', DB::connection('owner')->table('school.audit_log')->where('tenant_id', $school->id)
            ->where('object_type', 'membership')->where('action', 'UPDATE')->where('after->status', 'suspended')->value('reason'));
    }

    public function test_role_changes_are_atomic_and_self_elevation_is_refused_without_poisoning_the_render(): void
    {
        [$school, $admin] = $this->admin();
        $this->within($school, $admin, function () use ($school) {
            Livewire::test(Roles::class)->callTableAction('create', data: ['code' => 'custom', 'labels' => ['fr' => 'Personnalisé'], 'copy_from' => $this->roleId($school, 'front_office')])
                ->assertNotified(__('school.saved'));
            $id = DB::table('school.role')->where('code', 'custom')->value('id');
            // Labels would change first; the invalid permission must roll them back too.
            Livewire::test(Roles::class)->callTableAction('edit', $id, data: ['labels' => ['fr' => 'Ne doit pas rester'], 'version' => 1, 'permissions' => ['not.a.permission']]);
            $this->assertSame('Personnalisé', json_decode(DB::table('school.role')->where('id', $id)->value('labels'), true)['fr']);
            Livewire::test(Roles::class)->callTableAction('edit', $this->roleId($school, 'tenant_admin'), data: ['labels' => ['fr' => 'Tentative'], 'version' => 1, 'permissions' => ['audit.log.read']])
                ->assertNotified(__('errors.self_elevation'));
            $this->assertTrue((bool) DB::selectOne("SELECT school.can('role.definition.manage') AS ok")->ok);
        });
    }

    public function test_assignment_scopes_are_checked_and_no_self_assignment_is_allowed(): void
    {
        [$school, $admin] = $this->admin();
        [$other] = $this->admin();
        $target = $this->account('staff');
        $this->member($school, $target);
        $foreign = DB::connection('owner')->table('school.site')->where('tenant_id', $other->id)->value('id');
        $this->within($school, $admin, function () use ($school, $target, $foreign, $admin) {
            $payload = ['user_id' => $target['id'], 'role_id' => $this->roleId($school, 'supervisor'), 'scope_type' => 'site',
                'scope_ids' => [$foreign], 'valid_from' => now()->toDateString()];
            Livewire::test(Assignments::class)->callTableAction('create', data: $payload);
            $this->assertSame(0, DB::table('school.role_assignment')->where('user_id', $target['id'])->count());
            Livewire::test(Assignments::class)->callTableAction('create', data: [...$payload, 'user_id' => $admin['id'], 'scope_type' => 'tenant'])
                ->assertNotified(__('errors.self_assignment'));
            Livewire::test(Assignments::class)->callTableAction('create', data: [...$payload, 'scope_type' => 'tenant'])
                ->assertNotified(__('school.saved'));
            $this->assertSame(1, DB::table('school.role_assignment')->where('user_id', $target['id'])->count());
        });
    }

    public function test_real_livewire_update_keeps_the_tenant_context_until_render_and_writes_commit(): void
    {
        [$school, $admin] = $this->admin();
        $this->signIn($admin);
        $snapshot = $this->snapshot('/school/roles', 'roles');
        $mounted = $this->update($snapshot, [['mountAction', ['create', [], ['table' => true]]]])->assertOk();
        $this->assertNotEmpty(json_decode($mounted->json('components.0.snapshot'), true)['data']['mountedActions'][0], $mounted->json('components.0.snapshot'));
        $this->update($mounted->json('components.0.snapshot'), [['callMountedAction']], [
            'mountedActions.0.data.code' => 'http_role', 'mountedActions.0.data.labels.fr' => 'Créé par HTTP',
        ])->assertOk();
        $this->assertSame(1, DB::connection('owner')->table('school.role')->where('tenant_id', $school->id)->where('code', 'http_role')->count());
        // SET LOCAL must not leak past the response.
        $this->assertSame(0, DB::table('school.role')->count());
    }

    public function test_livewire_rechecks_revoked_permissions_school_switch_and_suspension(): void
    {
        [$a, $admin] = $this->admin();
        $b = $this->school();
        $this->member($b, $admin, 'active', ['tenant_admin']);
        $replacement = $this->account('replacement');
        $this->member($b, $replacement, 'active', ['tenant_admin']);
        $this->signIn($admin);
        $this->putJson('/api/session/school', ['tenant_id' => $a->id]);
        $snapshot = $this->snapshot('/school/accounts', 'accounts');
        $this->putJson('/api/session/school', ['tenant_id' => $b->id]);
        $this->update($snapshot)->assertForbidden();
        $snapshot = $this->snapshot('/school/roles', 'roles');
        DB::connection('owner')->table('school.membership')->where('tenant_id', $b->id)->where('user_id', $admin['id'])->update(['status' => 'suspended']);
        $this->update($snapshot)->assertRedirect('/school/login');
        $this->assertGuest();
    }

    public function test_mfa_and_provisional_password_have_a_working_setup_page_before_school_access(): void
    {
        $admin = $this->account('new-admin', mfa: false);
        $school = $this->school('essential', $admin);
        $this->member($school, $admin);
        $this->signIn($admin);
        $this->get('/school/accounts')->assertRedirect('/school/credentials');
        $this->get('/school/credentials')->assertOk()->assertSee('Activer la double authentification');
        Filament::setCurrentPanel(Filament::getPanel('school'));
        $page = Livewire::test(Credentials::class)->callAction('startMfa');
        $secret = $page->get('mfaSecret');
        $page->callAction('confirmMfa', data: ['code' => (new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($secret)])
            ->assertCount('recoveryCodes', 8);
        $this->get('/school/accounts')->assertOk();
        DB::connection('owner')->table('users')->where('id', $admin['id'])->update(['must_change_password' => true]);
        auth()->setUser($this->person($admin));
        $this->get('/school/accounts')->assertRedirect('/school/credentials');
        $this->get('/school/credentials')->assertOk();
        Livewire::test(Credentials::class)->callAction('password', data: ['current_password' => $admin['password'],
            'password' => 'Fresh-'.str_repeat('k4G!', 4), 'password_confirmation' => 'Fresh-'.str_repeat('k4G!', 4)])->assertNotified(__('school.saved'));
        $this->assertFalse((bool) DB::connection('owner')->table('users')->where('id', $admin['id'])->value('must_change_password'));
    }

    public function test_expired_school_shows_accounts_but_offers_no_writes(): void
    {
        [$school, $admin] = $this->admin();
        DB::connection('console')->select('SELECT platform.set_entitlement(?, ?::date, ?::date, ?, ?, ?, ?)', [$school->id, '2020-01-01', '2020-02-01', 0, 500, 1024, 0]);
        $this->signIn($admin);
        $this->get('/school/home')->assertOk()->assertSee(__('errors.tenant_read_only'));
        $this->get('/school/accounts')->assertOk()->assertDontSee('Nouveau compte');
    }

    public function test_support_panel_stays_read_only_and_a_revoked_consent_stops_livewire(): void
    {
        [$school, $admin] = $this->admin();
        $operator = $this->account('operator');
        DB::connection('owner')->insert('INSERT INTO platform.operator (user_id) VALUES (?)', [$operator['id']]);
        $this->signIn($admin);
        $grant = $this->postJson('/api/support-grants', ['hours' => 2, 'reason' => 'Assistance panneau école'])->assertCreated()->json('data.id');
        $this->deleteJson('/api/session');
        $this->actingAs($this->person($operator));
        $this->withSession(['auth.started_at' => time(), 'auth.seen_at' => time()]);
        $this->postJson('/api/support/session', ['tenant_id' => $school->id, 'grant_id' => $grant])->assertNoContent();
        $this->get('/school/home')->assertOk()->assertSee('Accès support en lecture seule');
        foreach (['reference-types', 'reference-nodes', 'subjects', 'years', 'periods'] as $page) {
            $this->get('/school/'.$page)->assertOk()->assertSee('Accès support en lecture seule');
        }
        $snapshot = $this->snapshot('/school/accounts', 'accounts');
        $before = DB::connection('owner')->table('school.membership')->where('tenant_id', $school->id)->count();
        $mounted = $this->update($snapshot, [['mountAction', ['create', [], ['table' => true]]]])->assertOk();
        $this->assertEmpty(json_decode($mounted->json('components.0.snapshot'), true)['data']['mountedActions'][0]);
        $this->assertSame($before, DB::connection('owner')->table('school.membership')->where('tenant_id', $school->id)->count());
        $this->get('/school/roles')->assertForbidden();
        DB::connection('owner')->table('school.support_grant')->where('id', $grant)->update(['revoked_at' => now()]);
        $this->update($snapshot)->assertRedirect('/console');
        $this->assertNull(session('support.grant'));
    }
}
