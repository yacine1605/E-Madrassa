<?php

namespace Tests\Feature;

use App\Filament\Console\Pages\ConsoleLogin;
use App\Filament\Console\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Console\Resources\TenantResource\Pages\ListTenants;
use App\Mail\AccountLink;
use App\Models\Console\Tenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** The publisher's console (W1.2: FR-PLT-01, FR-PLT-02, FR-PLT-05) in its Filament panel. */
final class ConsoleTest extends TestCase
{
    private function operator(bool $mfa = true): array
    {
        $operator = $this->account('operator', $mfa);
        DB::connection('owner')->insert('INSERT INTO platform.operator (user_id) VALUES (?)', [$operator['id']]);
        return $operator;
    }

    private function enter(array $account): void
    {
        $this->actingAs($this->person($account));
        $this->withSession(['auth.started_at' => now()->getTimestamp(), 'auth.seen_at' => now()->getTimestamp()]);
        Filament::setCurrentPanel(Filament::getPanel('console'));
    }

    private function newSchool(string $slug): array
    {
        return ['name' => 'École '.$slug, 'slug' => $slug, 'profile' => 'private_school_dz', 'plan' => 'essential', 'default_locale' => 'ar',
            'admin_email' => "direction@$slug.dz", 'admin_name' => 'Direction', 'valid_from' => now()->toDateString(), 'valid_until' => null,
            'quota_active_students' => 300, 'quota_storage_mb' => 2048];
    }

    public function test_only_operators_with_mfa_enter_the_console(): void
    {
        $this->get('/console/login')->assertOk();
        // A page of the console sends whoever is signed out to its sign-in; only the API answers in JSON.
        $this->get('/console/tenants')->assertRedirect('/console/login');
        $school = $this->school();
        $member = $this->account('member');
        $this->member($school, $member, 'active', ['tenant_admin']);
        $this->enter($member);
        $this->get('/console/tenants')->assertForbidden();
        // MFA is compulsory: an operator without it is sent to set it up first.
        $this->enter($this->operator(mfa: false));
        $this->get('/console/tenants')->assertRedirect();
        $this->enter($this->operator());
        $this->get('/console/tenants?search='.$school->slug)->assertOk()->assertSee($school->slug);
    }

    public function test_a_school_is_created_once_with_its_profile_and_invited_administrator(): void
    {
        Mail::fake();
        $operator = $this->operator();
        $this->enter($operator);
        $slug = 'ecole-'.strtolower(\Illuminate\Support\Str::random(6));
        Livewire::test(CreateTenant::class)->fillForm($this->newSchool($slug))->call('create')->assertHasNoFormErrors();
        // FR-PLT-01 AC3: the same form sent again gives the same school.
        Livewire::test(CreateTenant::class)->fillForm($this->newSchool($slug))->call('create')->assertHasNoFormErrors();
        $tenant = DB::connection('owner')->table('platform.tenant')->where('slug', $slug)->first();
        $this->assertSame(1, DB::connection('owner')->table('platform.tenant')->where('slug', $slug)->count());
        // AC1: one site, the profile's data, the administrator invited; the operator is the journal's author.
        $this->assertSame(['private_school_dz', 'ar', $operator['id']], [$tenant->profile_code, $tenant->default_locale, (int) $tenant->created_by]);
        $this->assertSame(1, DB::connection('owner')->table('school.site')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(33, DB::connection('owner')->table('school.ref_node')->where('tenant_id', $tenant->id)->count());
        $admin = DB::connection('owner')->table('users')->where('email', "direction@$slug.dz")->first();
        $this->assertNull($admin->password);
        $this->assertSame('invited', DB::connection('owner')->table('school.membership')->where('tenant_id', $tenant->id)->where('user_id', $admin->id)->value('status'));
        Mail::assertSent(AccountLink::class, fn (AccountLink $mail) => $mail->hasTo("direction@$slug.dz") && $mail->purpose === 'activation');
    }

    /** The publisher's commands (DEPLOY.md) invite as the console does: in the school's language. */
    public function test_the_commands_create_a_school_and_invite_its_administrator_in_its_language(): void
    {
        Mail::fake();
        $admin = $this->account('direction', false);
        $slug = 'cmd-'.strtolower(\Illuminate\Support\Str::random(8));
        $this->artisan('platform:create-school', ['slug' => $slug, 'name' => 'مدرسة الأمل', '--locale' => 'ar', '--admin' => $admin['email']])
            ->assertSuccessful();
        Mail::assertSent(AccountLink::class, fn (AccountLink $mail) => $mail->locale === 'ar' && $mail->school === 'مدرسة الأمل'
            && $mail->hasTo($admin['email']));
        $this->artisan('platform:invite-admin', ['slug' => $slug])->expectsOutput('Invitation sent (valid 72 hours).')->assertSuccessful();
        $this->artisan('platform:invite-admin', ['slug' => 'unknown-school'])->assertFailed();
    }

    /** A membership with whole-school roles, given once however often the command runs; an unknown role changes nothing. */
    public function test_the_membership_command_grants_whole_school_roles_once(): void
    {
        $school = $this->school();
        $member = $this->account('secretary', false);
        $this->artisan('platform:membership', ['slug' => $school->slug, 'email' => $member['email'], '--role' => ['nobody']])->assertFailed();
        $this->assertSame(0, DB::connection('owner')->table('school.membership')->where('tenant_id', $school->id)->where('user_id', $member['id'])->count());
        foreach ([1, 2] as $run) {
            $this->artisan('platform:membership', ['slug' => $school->slug, 'email' => $member['email'], '--role' => ['academic_hr', 'front_office']])
                ->assertSuccessful();
        }
        $roles = DB::connection('owner')->select("SELECT r.code, a.scope_type FROM school.role_assignment a JOIN school.role r ON r.tenant_id = a.tenant_id AND r.id = a.role_id
            WHERE a.tenant_id = ? AND a.user_id = ? ORDER BY r.code", [$school->id, $member['id']]);
        $this->assertSame([['academic_hr', 'tenant'], ['front_office', 'tenant']], array_map(fn ($row) => [$row->code, $row->scope_type], $roles));
    }

    public function test_a_school_suspended_from_the_console_is_closed_and_the_journal_says_by_whom(): void
    {
        $school = $this->school();
        $member = $this->account('member');
        $this->member($school, $member);
        $operator = $this->operator();
        $this->enter($operator);
        $tenant = Tenant::query()->findOrFail($school->id);
        Livewire::test(ListTenants::class)->searchTable($school->slug)->assertCanSeeTableRecords([$tenant])
            ->callTableAction('suspend', $tenant, data: ['reason' => 'Abonnement impayé'])->assertNotified('État enregistré.');
        $this->assertSame('suspended', DB::connection('owner')->table('platform.tenant')->where('id', $school->id)->value('status'));
        $entry = DB::connection('owner')->selectOne("SELECT actor_id, reason FROM school.audit_log WHERE tenant_id = ? AND object_type = 'tenant' AND action = 'UPDATE'", [$school->id]);
        $this->assertSame([$operator['id'], 'Abonnement impayé'], [(int) $entry->actor_id, $entry->reason]);
        // FR-PLT-01 AC2.
        $this->signIn($member)->assertForbidden()->assertJsonPath('code', 'no_school');
        $this->enter($operator);
        Livewire::test(ListTenants::class)->searchTable($school->slug)->callTableAction('reactivate', $tenant, data: ['reason' => 'Régularisé']);
        $this->assertSame('active', DB::connection('owner')->table('platform.tenant')->where('id', $school->id)->value('status'));
    }

    public function test_modules_and_subscription_change_from_the_console(): void
    {
        $school = $this->school();
        $this->enter($this->operator());
        $tenant = Tenant::query()->findOrFail($school->id);
        Livewire::test(ListTenants::class)->searchTable($school->slug)->callTableAction('modules', $tenant, data: ['modules' => ['students_crm', 'payroll']])->assertNotified('Modules enregistrés.');
        $this->assertSame(['core', 'expenses', 'payroll', 'staff', 'students_crm'],
            DB::connection('owner')->table('platform.tenant_module')->where('tenant_id', $school->id)->orderBy('module_key')->pluck('module_key')->all());
        Livewire::test(ListTenants::class)->searchTable($school->slug)->callTableAction('entitlement', $tenant, data: ['valid_from' => '2026-09-01', 'valid_until' => '2027-08-31',
            'grace_days' => 30, 'quota_active_students' => 800, 'quota_storage_mb' => 4096, 'quota_ai_requests_month' => 0]);
        $entitlement = DB::connection('owner')->table('platform.tenant_entitlement')->where('tenant_id', $school->id)->first();
        $this->assertSame(['2027-08-31', 30, 800, 4096], [$entitlement->valid_until, $entitlement->grace_days, $entitlement->quota_active_students, $entitlement->quota_storage_mb]);
    }

    public function test_supervision_counts_without_showing_school_data(): void
    {
        $school = $this->school();
        $secret = $this->account('eleve-confidentiel');
        $this->member($school, $secret);
        $this->enter($this->operator());
        // FR-PLT-05: the school and its numbers, never its people.
        $this->get('/console/supervision')->assertOk()->assertSee($school->slug)->assertSee('Comptes actifs')
            ->assertDontSee($secret['email'])->assertDontSee('eleve-confidentiel');
    }

    /** The deployment's commands make the first operator, with MFA (DEPLOY.md, first start). */
    public function test_the_commands_make_an_operator_with_mfa(): void
    {
        $email = 'operateur-'.Str::lower(Str::random(8)).'@example.test';
        $this->assertSame(0, Artisan::call('platform:user', ['email' => $email, '--password' => 'Secret-'.Str::random(12), '--mfa' => true]));
        $this->assertMatchesRegularExpression('/authenticator application: [A-Z2-7]{16,}/', Artisan::output());
        $this->assertSame(0, Artisan::call('platform:operator', ['email' => $email]));
        $this->assertNotNull(DB::connection('owner')->table('users')->where('email', $email)->value('mfa_enabled_at'));
    }

    /** Before any school the database shows nobody: the sign-in finds the operator through platform.find_login (F2). */
    public function test_the_console_sign_in_finds_the_operator_by_email(): void
    {
        $operator = $this->operator();
        Livewire::test(ConsoleLogin::class)->fillForm(['email' => strtoupper($operator['email']), 'password' => $operator['password']])
            ->call('authenticate')->assertHasNoFormErrors();
        Livewire::test(ConsoleLogin::class)->fillForm(['email' => 'nobody@example.test', 'password' => $operator['password']])
            ->call('authenticate')->assertHasFormErrors(['email']);
    }

    public function test_the_console_sign_in_locks_after_five_failures(): void
    {
        $operator = $this->operator();
        for ($i = 0; $i < 5; $i++) {
            Livewire::test(ConsoleLogin::class)->fillForm(['email' => $operator['email'], 'password' => 'wrong-'.$i])->call('authenticate')->assertHasFormErrors(['email']);
        }
        // FR-IAM-02 AC2: the sixth attempt waits, even with the right password.
        Livewire::test(ConsoleLogin::class)->fillForm(['email' => $operator['email'], 'password' => $operator['password']])->call('authenticate')
            ->assertNotified('Trop de tentatives. Réessayez dans quelques minutes.');
        $this->assertGuest();
    }
}
