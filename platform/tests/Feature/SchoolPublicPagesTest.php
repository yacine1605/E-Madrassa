<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Activation;
use App\Filament\School\Pages\Recovery;
use App\Filament\School\Pages\Sites;
use App\Mail\AccountLink;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** W1.7: the pages account links open before sign-in (FR-IAM-01, FR-IAM-02), and the sites (FR-SLL-01, R09). */
final class SchoolPublicPagesTest extends TestCase
{
    private const PASSWORD = 'Le figuier du jardin 2026';

    private function setting(string $plan = 'essential'): array
    {
        $admin = $this->account('admin');
        $school = $this->school($plan, $admin, 'private_school_dz');
        $this->member($school, $admin);
        $this->signIn($admin)->assertOk();
        return [$school, $admin];
    }

    /** The token a sent link carries, after the panel's address. */
    private function token(string $purpose): string
    {
        $mail = collect([...Mail::sent(AccountLink::class), ...Mail::queued(AccountLink::class)])->last(fn (AccountLink $mail) => $mail->purpose === $purpose);
        $this->assertStringStartsWith(config('platform.front_url').'/'.$purpose.'#token=', $mail->url);
        return Str::after($mail->url, '#token=');
    }

    private function guest(): void
    {
        $this->deleteJson('/api/session');
        Filament::setCurrentPanel(Filament::getPanel('school'));
    }

    public function test_an_invitation_opens_the_account_in_the_browser_once(): void
    {
        Mail::fake();
        $this->setting();
        $email = 'karima-'.Str::lower(Str::random(6)).'@example.test';
        $this->postJson('/api/accounts', ['name' => 'Karima Haddad', 'email' => $email, 'method' => 'link'])->assertCreated();
        $token = $this->token('activation');
        $this->assertStringEndsWith('/school', config('platform.front_url'));

        $this->guest();
        $this->get('/school/activation')->assertOk()->assertSee(__('account.reading'));
        Livewire::test(Activation::class)->call('withoutToken')->assertSet('state', 'invalid');
        Livewire::test(Activation::class)->call('open', str_repeat('0', 64))->assertSet('state', 'invalid');
        Livewire::test(Activation::class)->call('open', $token)->assertSet('state', 'ready')->assertSee('Karima Haddad')
            ->set('data.password', 'court')->set('data.password_confirmation', 'court')->call('submit')->assertSet('state', 'ready')
            ->set('data.password', self::PASSWORD)->set('data.password_confirmation', self::PASSWORD)->call('submit')->assertSet('state', 'done');
        $this->assertSame(1, (int) DB::connection('owner')->table('school.membership')->join('users', 'users.id', '=', 'membership.user_id')
            ->where('users.email', $email)->where('membership.status', 'active')->count());
        // A link serves once.
        Livewire::test(Activation::class)->call('open', $token)->assertSet('state', 'invalid');
        $this->postJson('/api/session', ['login' => $email, 'password' => self::PASSWORD])->assertOk();
    }

    public function test_a_forgotten_password_is_reset_from_the_login_page(): void
    {
        Mail::fake();
        [, $admin] = $this->setting();
        $this->guest();
        $this->get('/school/login')->assertOk()->assertSee(__('account.forgot'));
        Livewire::test(Recovery::class)->call('withoutToken')->assertSet('state', 'request')
            ->set('data.email', strtoupper($admin['email']))->call('send')->assertSet('state', 'requested')->assertSee(__('messages.recovery_sent'));
        // An unknown address gets the same answer.
        Livewire::test(Recovery::class)->call('withoutToken')->set('data.email', 'nobody@example.test')->call('send')->assertSet('state', 'requested');
        $token = $this->token('recovery');
        Livewire::test(Recovery::class)->call('open', $token)->assertSet('state', 'ready')
            ->set('data.password', self::PASSWORD)->set('data.password_confirmation', self::PASSWORD)->call('submit')->assertSet('state', 'done');
        $this->postJson('/api/session', ['login' => $admin['email'], 'password' => self::PASSWORD])->assertOk();

        // Three requests an hour per address (NFR-SEC-05).
        $this->guest();
        foreach (range(1, 2) as $again) {
            Livewire::test(Recovery::class)->call('withoutToken')->set('data.email', $admin['email'])->call('send')->assertSet('state', 'requested');
        }
        Livewire::test(Recovery::class)->call('withoutToken')->set('data.email', $admin['email'])->call('send')
            ->assertSet('state', 'request')->assertNotified(__('errors.too_many_attempts'));
    }

    public function test_sites_exist_only_with_their_module_and_one_stays_in_use(): void
    {
        [$school, $admin] = $this->setting();
        $this->get('/school/home')->assertSee(Sites::getUrl(), false);
        $this->get('/school/sites')->assertOk()->assertSee('Site principal');
        $main = DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->value('id');
        $within = function (callable $test) use ($school, $admin) {
            $this->actingAs($this->person($admin));
            $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
            Filament::setCurrentPanel(Filament::getPanel('school'));
            DB::transaction(function () use ($school, $admin, $test) {
                app(CurrentSchool::class)->enter($school->id, $admin['id'], CurrentSchool::requestId(null));
                app(CurrentSchool::class)->setMode('active');
                $test();
            });
        };
        $within(fn () => Livewire::test(Sites::class)->callTableAction('archive', $main, data: ['reason' => 'Fermeture'])
            ->assertNotified(__('errors.last_site')));
        $within(fn () => Livewire::test(Sites::class)->callTableAction('create', data: ['code' => 'ANNEXE', 'labels' => ['fr' => null, 'ar' => 'الملحقة']])
            ->assertNotified(__('school.saved')));
        $within(fn () => Livewire::test(Sites::class)->callTableAction('archive', $main, data: ['reason' => 'Déménagement'])->assertNotified(__('school.saved')));
        $this->assertSame(['ANNEXE'], DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->whereNull('archived_at')->pluck('code')->all());
    }

    /** R09, in its own test: Filament builds the menu once per application, as a real request would. */
    public function test_without_their_module_neither_the_menu_nor_the_page_of_sites(): void
    {
        $this->setting('custom');
        $this->get('/school/home')->assertOk()->assertDontSee(Sites::getUrl(), false);
        $this->get('/school/sites')->assertForbidden();
    }
}
