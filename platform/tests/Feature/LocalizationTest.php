<?php

namespace Tests\Feature;

use App\Mail\AccountLink;
use App\Support\Formats;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Languages (FR-I18-01, NFR-I18N-01): each person's, the school's, French; Arabic right to left. */
final class LocalizationTest extends TestCase
{
    /** A school whose language is Arabic, with its administrator. */
    private function arabicSchool(array $admin): object
    {
        $slug = 'test-'.Str::lower(Str::random(12));
        $id = DB::connection('console')->selectOne('SELECT platform.create_tenant(?, ?, ?, ?, ?, ?::date, ?::date, ?, ?, ?, ?, ?, ?) AS id',
            [(string) Str::uuid(), $slug, 'مدرسة '.$slug, 'ar', 'essential', now()->toDateString(), null, 500, 1024, 'MAIN', null, null, 'private_school_dz'])->id;
        $school = (object) ['id' => $id, 'slug' => $slug];
        $this->member($school, $admin, 'active', ['tenant_admin']);
        return $school;
    }

    public function test_a_person_reads_their_language_among_those_the_school_offers(): void
    {
        $admin = $this->account('admin');
        $school = $this->school('essential', $admin, 'private_school_dz');
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->signIn($admin)->assertOk();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.locale', null)->assertJsonPath('data.effective_locale', 'fr')
            ->assertJsonPath('data.text_direction', 'ltr')->assertHeader('Content-Language', 'fr');
        $this->getJson('/api/school')->assertJsonPath('data.languages', ['fr', 'ar'])->assertJsonPath('data.arabic_digits', false);

        $this->putJson('/api/account/locale', ['locale' => 'ar'])->assertOk()->assertJsonPath('data.effective_locale', 'ar');
        $this->getJson('/api/me')->assertJsonPath('data.effective_locale', 'ar')->assertJsonPath('data.text_direction', 'rtl')
            ->assertHeader('Content-Language', 'ar');
        $this->getJson('/api/nowhere')->assertNotFound()->assertJsonPath('message', __('errors.not_found', [], 'ar'));
        // English has no catalog yet: not offered.
        $this->putJson('/api/account/locale', ['locale' => 'en'])->assertUnprocessable()
            ->assertJsonPath('errors.locale.0', __('errors.language_unavailable', [], 'ar'));

        // The school stops offering Arabic: its own language comes back.
        $this->putJson('/api/settings/locale.languages', ['value' => ['fr']])->assertOk();
        $this->getJson('/api/me')->assertJsonPath('data.locale', 'ar')->assertJsonPath('data.effective_locale', 'fr');
        $this->putJson('/api/settings/locale.languages', ['value' => ['fr', 'ar']])->assertOk();
        $this->putJson('/api/account/locale', ['locale' => null])->assertOk()->assertJsonPath('data.effective_locale', 'fr');
    }

    public function test_a_school_in_arabic_speaks_arabic_to_whoever_has_not_chosen(): void
    {
        $admin = $this->account('admin');
        $school = $this->arabicSchool($admin);
        $this->signIn($admin)->assertOk();
        $this->getJson('/api/me')->assertJsonPath('data.effective_locale', 'ar');
        $this->postJson('/api/years', [])->assertUnprocessable()->assertJsonPath('errors.code.0', fn ($message) => str_contains($message, 'مطلوب'));
        // Its default site is named in both languages.
        $this->getJson('/api/sites')->assertOk()->assertJsonPath('data.0.labels.fr', 'Site principal')->assertJsonPath('data.0.labels.ar', 'الموقع الرئيسي');
        // Before sign-in, the browser's preference.
        $this->deleteJson('/api/session');
        $this->postJson('/api/session', ['login' => 'nobody@example.test', 'password' => 'wrong-password'], ['Accept-Language' => 'ar-DZ,ar;q=0.9'])
            ->assertUnauthorized()->assertJsonPath('message', __('errors.invalid_credentials', [], 'ar'));
        $this->postJson('/api/session', ['login' => 'nobody@example.test', 'password' => 'wrong-password'], ['Accept-Language' => 'en-US'])
            ->assertUnauthorized()->assertJsonPath('message', __('errors.invalid_credentials', [], 'fr'));
    }

    public function test_a_search_finds_a_name_however_it_is_written(): void
    {
        $admin = $this->account('admin');
        $school = $this->school('essential', $admin, 'private_school_dz');
        $this->member($school, $admin, 'active', ['tenant_admin']);
        foreach (['أحمد بن عليّ', 'Hélène Benali', 'Fatima Zohra'] as $name) {
            $id = DB::connection('owner')->table('users')->insertGetId(['name' => $name, 'email' => Str::lower(Str::random(8)).'@example.test',
                'created_at' => now(), 'updated_at' => now()]);
            $this->member($school, ['id' => $id]);
        }
        $this->signIn($admin)->assertOk();
        $found = fn (string $q) => array_column($this->getJson('/api/accounts?q='.urlencode($q))->assertOk()->json('data'), 'name');
        // AC2.
        $this->assertSame(['أحمد بن عليّ'], $found('احمد'));
        $this->assertSame(['أحمد بن عليّ'], $found('بن علي'));
        $this->assertSame(['Hélène Benali'], $found('HELENE'));
        $this->assertSame([], $found('Zahra'));
    }

    public function test_emails_go_out_in_the_recipients_language(): void
    {
        Mail::fake();
        $admin = $this->account('admin');
        $this->arabicSchool($admin);
        $this->signIn($admin)->assertOk();
        $this->postJson('/api/accounts', ['name' => 'Karim', 'email' => 'karim-'.Str::lower(Str::random(6)).'@example.test', 'method' => 'link'])->assertCreated();
        Mail::assertSent(AccountLink::class, fn (AccountLink $mail) => $mail->locale === 'ar'
            && str_contains($mail->render(), 'مرحبًا Karim') && str_contains($mail->render(), 'خلال 72 ساعة'));
        // A recovery link: the person's own language.
        $reader = $this->account('reader', false);
        $this->speaks($reader, 'ar');
        $this->deleteJson('/api/session');
        $this->postJson('/api/recovery/request', ['email' => $reader['email']])->assertStatus(202);
        Mail::assertQueued(AccountLink::class, fn (AccountLink $mail) => $mail->purpose === 'recovery' && $mail->locale === 'ar');
    }

    public function test_dates_and_amounts_are_written_for_each_language(): void
    {
        $this->assertSame('11/10/2026', Formats::date('2026-10-11', 'fr'));
        $this->assertSame('11/10/2026', Formats::date('2026-10-11', 'ar'));
        $this->assertSame('١١/١٠/٢٠٢٦', Formats::date('2026-10-11', 'ar', true));
        $this->assertSame('11/10/2026', Formats::date('2026-10-11', 'fr', true), 'Arabic-Indic digits only in Arabic');
        $this->assertSame('11/10/2026 14:30', Formats::dateTime('2026-10-11 13:30:00+00', 'fr'), 'in Algiers time');
        $this->assertSame("12\u{00A0}500,50", Formats::number(12500.5, 2, 'fr'));
        $this->assertSame("12\u{00A0}500,00\u{00A0}DA", Money::format(1250000, 'fr'));
        $this->assertSame("\u{2066}١٢\u{00A0}٥٠٠,٠٠\u{2069}\u{00A0}د.ج", Money::format(1250000, 'ar', true));
    }

    public function test_the_console_follows_its_operators_language(): void
    {
        $operator = $this->account('operator');
        DB::connection('owner')->insert('INSERT INTO platform.operator (user_id) VALUES (?)', [$operator['id']]);
        $user = $this->speaks($operator, 'ar');
        $this->actingAs($user);
        $this->withSession(['auth.started_at' => now()->getTimestamp(), 'auth.seen_at' => now()->getTimestamp()]);
        Filament::setCurrentPanel(Filament::getPanel('console'));
        $this->get('/console/tenants')->assertOk()->assertSee('dir="rtl"', false)->assertSee(__('console.tenant.models', [], 'ar'))
            ->assertDontSee(__('console.tenant.models', [], 'fr'));
        $this->post('/console/locale/fr')->assertRedirect();
        $this->assertSame('fr', $this->person($operator)->locale);
        $this->get('/console/tenants')->assertOk()->assertSee('dir="ltr"', false)->assertSee(__('console.tenant.models', [], 'fr'));
        $this->post('/console/locale/en')->assertNotFound();
    }
}
