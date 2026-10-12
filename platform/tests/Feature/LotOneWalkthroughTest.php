<?php

namespace Tests\Feature;

use App\Filament\Console\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Console\Resources\TenantResource\Pages\ListTenants;
use App\Mail\AccountLink;
use App\Models\Console\Tenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The exit of lot 1 (SRS §9.1, J1): two schools created from the console and run end to end,
 * isolated (TST-01), and the walkthroughs R01 (accounts and roles), R09 (modularity) and R10
 * (one code, a school's and a centre's referential). Each step goes through the API or the
 * screens a person uses, under the restricted roles.
 */
final class LotOneWalkthroughTest extends TestCase
{
    private const PASSWORD = 'Le figuier du jardin 2026';

    private array $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->operator = $this->account('operator');
        DB::connection('owner')->insert('INSERT INTO platform.operator (user_id) VALUES (?)', [$this->operator['id']]);
    }

    private function inConsole(): void
    {
        $this->deleteJson('/api/session');
        $this->actingAs($this->person($this->operator));
        $this->withSession(['auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('console'));
    }

    /** A school created from the console's form, as the publisher does; its administrator invited. */
    private function createSchool(string $kind, string $profile, string $locale): array
    {
        $this->inConsole();
        $slug = $kind.'-'.Str::lower(Str::random(6));
        $email = "direction@$slug.dz";
        Livewire::test(CreateTenant::class)->fillForm(['name' => ($kind === 'centre' ? 'Centre ' : 'École ').$slug, 'slug' => $slug,
            'profile' => $profile, 'plan' => 'essential', 'default_locale' => $locale, 'admin_email' => $email, 'admin_name' => 'Direction '.$kind,
            'valid_from' => now()->toDateString(), 'quota_active_students' => 500, 'quota_storage_mb' => 1024])->call('create')->assertHasNoFormErrors();
        return ['slug' => $slug, 'id' => DB::connection('owner')->table('platform.tenant')->where('slug', $slug)->value('id'), 'email' => $email,
            'token' => $this->link($email, 'activation')];
    }

    private function link(string $email, string $purpose): string
    {
        $mail = collect([...Mail::sent(AccountLink::class), ...Mail::queued(AccountLink::class)])
            ->last(fn (AccountLink $mail) => $mail->hasTo($email) && $mail->purpose === $purpose);
        $this->assertNotNull($mail, "no $purpose link for $email");
        return Str::after($mail->url, '#token=');
    }

    /** The invited person opens their link, chooses a password, signs in, then sets up MFA if the school asks for it. */
    private function activate(string $email, string $token): array
    {
        $this->deleteJson('/api/session');
        $this->postJson('/api/activation', ['token' => $token, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertNoContent();
        $person = ['email' => $email, 'secret' => null];
        $this->signInAs($person);
        if ($this->getJson('/api/me')->json('code') === 'mfa_setup_required') {
            $person['secret'] = $this->postJson('/api/account/mfa')->assertOk()->json('secret');
            $this->postJson('/api/account/mfa/confirm', ['code' => $this->code($person)])->assertOk();
        }
        $this->getJson('/api/me')->assertOk();
        return $person;
    }

    private function code(array $person): string
    {
        DB::connection('owner')->table('users')->where('email', $person['email'])->update(['mfa_last_step' => null]);
        return (new Google2FA())->getCurrentOtp($person['secret']);
    }

    private function signInAs(array $person, string $password = self::PASSWORD): void
    {
        $this->deleteJson('/api/session');
        $session = $this->postJson('/api/session', ['login' => $person['email'], 'password' => $password])->assertOk();
        if ($session->json('mfa_required')) $this->postJson('/api/session/mfa', ['code' => $this->code($person)])->assertOk();
    }

    public function test_two_schools_created_from_the_console_run_end_to_end_and_never_meet(): void
    {
        $a = $this->createSchool('ecole', 'private_school_dz', 'fr');
        $b = $this->createSchool('centre', 'training_center', 'ar');
        $adminA = $this->activate($a['email'], $a['token']);
        $this->postJson('/api/accounts', ['name' => 'Karim Haddad', 'username' => 'karim.h', 'method' => 'password'])->assertCreated();
        $siteA = $this->getJson('/api/sites')->assertOk()->json('data.0.id');
        $adminB = $this->activate($b['email'], $b['token']);
        $this->postJson('/api/accounts', ['name' => 'سليمة بن علي', 'username' => 'salima.b', 'method' => 'password'])->assertCreated();

        // TST-01: B's administrator sees B only, through the API and the screens, and cannot reach A.
        $names = array_column($this->getJson('/api/accounts')->assertOk()->json('data'), 'name');
        $this->assertContains('سليمة بن علي', $names);
        $this->assertNotContains('Karim Haddad', $names);
        $this->get('/school/accounts')->assertOk()->assertSee('سليمة بن علي')->assertDontSee('Karim Haddad');
        $this->putJson('/api/session/school', ['tenant_id' => $a['id']])->assertForbidden()->assertJsonPath('code', 'school_forbidden');
        $this->putJson("/api/sites/$siteA", ['labels' => ['fr' => 'Intrus'], 'version' => 1])->assertNotFound();
        $this->getJson('/api/audit?object_type=membership')->assertOk()->assertJsonMissing(['actor_name' => 'Direction ecole']);

        $this->signInAs($adminA);
        $this->get('/school/accounts')->assertOk()->assertSee('Karim Haddad')->assertDontSee('سليمة بن علي');
        $this->assertSame(1, DB::connection('owner')->table('school.site')->where('id', $siteA)->where('labels->fr', '!=', 'Intrus')->count());
    }

    public function test_r01_an_account_is_invited_given_a_role_suspended_and_recovered_and_exports_keep_the_same_rights(): void
    {
        $a = $this->createSchool('ecole', 'private_school_dz', 'fr');
        $admin = $this->activate($a['email'], $a['token']);
        $email = 'nadia-'.Str::lower(Str::random(5)).'@example.test';
        $teacher = $this->postJson('/api/accounts', ['name' => 'Nadia Teacher', 'email' => $email, 'method' => 'link',
            'roles' => [['role_id' => $this->roleId((object) $a, 'teacher'), 'scope_type' => 'tenant']]])->assertCreated()->json('data.id');
        $person = $this->activate($email, $this->link($email, 'activation'));

        // What a teacher may not see on screen, they may not export either.
        $this->getJson('/api/accounts')->assertForbidden();
        $this->postJson('/api/exports', ['kind' => 'accounts', 'format' => 'csv'])->assertForbidden();
        $this->get('/school/accounts')->assertForbidden();

        $this->signInAs($admin);
        $this->postJson('/api/exports', ['kind' => 'accounts', 'format' => 'csv'])->assertCreated()->assertJsonPath('data.rows', 2);
        $this->postJson("/api/accounts/$teacher/status", ['status' => 'suspended', 'reason' => 'Fin de remplacement'])->assertOk();
        $this->deleteJson('/api/session');
        $this->postJson('/api/session', ['login' => $email, 'password' => self::PASSWORD])->assertForbidden()->assertJsonPath('code', 'no_school');

        $this->signInAs($admin);
        $this->postJson("/api/accounts/$teacher/status", ['status' => 'active', 'reason' => 'Nouveau remplacement'])->assertOk();
        $this->deleteJson('/api/session');
        $this->postJson('/api/recovery/request', ['email' => $email])->assertStatus(202);
        $fresh = 'Un autre figuier 2027';
        $this->postJson('/api/recovery', ['token' => $this->link($email, 'recovery'), 'password' => $fresh, 'password_confirmation' => $fresh])->assertNoContent();
        $this->signInAs($person, $fresh);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.email', $email);
        // Every step is in the school's journal, with its author and reason.
        $this->signInAs($admin);
        $reasons = array_column($this->getJson('/api/audit?object_type=membership')->assertOk()->json('data'), 'reason');
        $this->assertContains('Fin de remplacement', $reasons);
        $this->assertContains('Nouveau remplacement', $reasons);
    }

    public function test_r09_a_module_turned_off_leaves_neither_menu_nor_api_and_turned_on_again_brings_its_data_back(): void
    {
        $a = $this->createSchool('ecole', 'private_school_dz', 'fr');
        $admin = $this->activate($a['email'], $a['token']);
        $this->postJson('/api/sites', ['code' => 'ANNEXE', 'labels' => ['fr' => 'Annexe']])->assertCreated();

        $this->inConsole();
        $modules = DB::connection('owner')->table('platform.tenant_module')->where('tenant_id', $a['id'])->pluck('module_key')->all();
        Livewire::test(ListTenants::class)->searchTable($a['slug'])->callTableAction('modules', Tenant::query()->findOrFail($a['id']),
            data: ['modules' => array_values(array_diff($modules, ['sites_rooms']))])->assertNotified();
        $this->signInAs($admin);
        $this->assertNotContains('sites_rooms', $this->getJson('/api/school')->json('data.modules'));
        $this->getJson('/api/sites')->assertForbidden()->assertJsonPath('code', 'module_disabled');
        $this->get('/school/sites')->assertForbidden();

        $this->inConsole();
        Livewire::test(ListTenants::class)->searchTable($a['slug'])->callTableAction('modules', Tenant::query()->findOrFail($a['id']),
            data: ['modules' => $modules])->assertNotified();
        $this->signInAs($admin);
        $this->assertContains('ANNEXE', array_column($this->getJson('/api/sites')->assertOk()->json('data'), 'code'));
    }

    public function test_r10_a_school_and_a_centre_run_the_same_code_with_their_own_referential(): void
    {
        $a = $this->createSchool('ecole', 'private_school_dz', 'fr');
        $b = $this->createSchool('centre', 'training_center', 'ar');
        $types = fn () => array_column($this->getJson('/api/referential/types')->assertOk()->json('data'), 'code');

        $this->activate($a['email'], $a['token']);
        $this->getJson('/api/school')->assertJsonPath('data.profile.code', 'private_school_dz')->assertJsonPath('data.profile.subject_kind', 'subject');
        $schoolTypes = $types();
        $this->get('/school/subjects')->assertOk()->assertSee(__('reference.subjects', [], 'fr'));

        $this->activate($b['email'], $b['token']);
        $this->getJson('/api/school')->assertJsonPath('data.profile.code', 'training_center')->assertJsonPath('data.profile.subject_kind', 'module');
        $centreTypes = $types();
        $this->get('/school/subjects')->assertOk()->assertSee(__('reference.modules', [], 'ar'));
        $this->getJson('/api/me')->assertJsonPath('data.effective_locale', 'ar');

        // The same screens and the same API; each its own hierarchy and labels (FR-REF-01, BR-18).
        $this->assertContains('cycle', $schoolTypes);
        $this->assertNotContains('cycle', $centreTypes);
        $this->assertNotSame($schoolTypes, $centreTypes);
    }
}
