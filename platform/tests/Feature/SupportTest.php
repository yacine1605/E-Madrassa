<?php

namespace Tests\Feature;

use App\Filament\Console\Resources\TenantResource\Pages\ListTenants;
use App\Models\Console\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Support access with the school's consent (FR-PLT-04). */
final class SupportTest extends TestCase
{
    /** @return array{0: object, 1: array, 2: array} a school with an administrator, and an operator of the console */
    private function setting(): array
    {
        [$admin, $operator] = [$this->account('admin'), $this->account('operator')];
        $school = $this->school('essential', $admin, 'private_school_dz');
        $this->member($school, $admin);
        DB::connection('owner')->insert('INSERT INTO platform.operator (user_id) VALUES (?)', [$operator['id']]);
        return [$school, $admin, $operator];
    }

    private function consent(array $admin, int $hours = 24, bool $sensitive = false): string
    {
        $this->signIn($admin);
        return $this->postJson('/api/support-grants', ['hours' => $hours, 'include_sensitive' => $sensitive, 'reason' => 'Ticket 2001 : import bloqué'])
            ->assertCreated()->json('data.id');
    }

    /** The operator, signed in on the console, as the API sees them. */
    private function asOperator(array $operator): void
    {
        $this->deleteJson('/api/session');
        $this->actingAs(User::query()->findOrFail($operator['id']));
        $this->withSession(['auth.started_at' => now()->getTimestamp(), 'auth.seen_at' => now()->getTimestamp()]);
    }

    public function test_a_school_consents_for_72_hours_at_most_and_sees_every_consent(): void
    {
        [, $admin] = $this->setting();
        $this->signIn($admin);
        $this->postJson('/api/support-grants', ['hours' => 73, 'reason' => 'Trop long'])->assertUnprocessable();
        $grant = $this->consent($admin);
        $this->postJson("/api/support-grants/$grant/revoke")->assertOk()->assertJsonPath('data.revoked_at', fn ($at) => $at !== null);
        $this->consent($admin, 2);
        // AC3.
        $this->getJson('/api/support-grants')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.granted_by', 'admin');
    }

    public function test_without_consent_the_operator_reaches_nothing(): void
    {
        [$school, , $operator] = $this->setting();
        $this->asOperator($operator);
        // AC1: no consent, no data; an invented one changes nothing.
        $this->postJson('/api/support/session', ['tenant_id' => $school->id, 'grant_id' => (string) \Illuminate\Support\Str::uuid()])
            ->assertForbidden()->assertJsonPath('code', 'support_not_granted');
        $this->getJson('/api/sites')->assertStatus(409)->assertJsonPath('code', 'school_not_selected');
    }

    public function test_under_consent_the_operator_reads_read_only_and_each_consultation_is_journaled(): void
    {
        [$school, $admin, $operator] = $this->setting();
        $grant = $this->consent($admin);
        $this->asOperator($operator);
        $this->postJson('/api/support/session', ['tenant_id' => $school->id, 'grant_id' => $grant])->assertNoContent();
        $this->getJson('/api/school')->assertOk()->assertJsonPath('data.support.id', $grant)->assertJsonPath('data.support.include_sensitive', false);
        $this->getJson('/api/referential/nodes?type=cycle')->assertOk()->assertJsonCount(4, 'data');
        // Reading, yes; managing roles, never.
        $this->getJson('/api/accounts')->assertOk();
        $this->getJson('/api/roles')->assertForbidden();
        $this->postJson('/api/years', ['code' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'current'])->assertForbidden();
        $this->assertSame(0, DB::connection('owner')->table('school.academic_year')->where('tenant_id', $school->id)->count());
        $this->signIn($admin);
        // Every request of the session, refused ones included.
        $this->getJson('/api/support-grants')->assertJsonPath('data.0.consultations', 5);
        $journal = $this->getJson('/api/audit?object_type=support_grant&action=SUPPORT_READ')->assertOk()->assertJsonPath('data.0.actor_id', $operator['id'])->json('data');
        $this->assertSame(['POST /api/years', 'GET /api/roles', 'GET /api/accounts', 'GET /api/referential/nodes', 'GET /api/school'], array_column($journal, 'reason'));
    }

    public function test_a_support_session_writes_nothing_even_where_its_permissions_would_allow(): void
    {
        [$school, $admin, $operator] = $this->setting();
        $grant = $this->consent($admin);
        $this->asOperator($operator);
        $this->postJson('/api/support/session', ['tenant_id' => $school->id, 'grant_id' => $grant])->assertNoContent();
        // Exports are reading too, but they write a file: refused like any write.
        $this->postJson('/api/exports', ['kind' => 'referential', 'format' => 'csv'])->assertForbidden();
    }

    public function test_financial_attachments_need_the_distinct_consent(): void
    {
        Storage::fake('files');
        [$school, $admin, $operator] = $this->setting();
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $this->signIn($accountant);
        $file = $this->post('/api/files', ['category' => 'finance_attachment', 'file' => UploadedFile::fake()->createWithContent('recu.pdf', "%PDF-1.4\n%%EOF\n")],
            ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $narrow = $this->consent($admin);
        $this->asOperator($operator);
        $this->postJson('/api/support/session', ['tenant_id' => $school->id, 'grant_id' => $narrow])->assertNoContent();
        $this->getJson("/api/files/$file")->assertNotFound();
        $wide = $this->consent($admin, 4, true);
        $this->asOperator($operator);
        $this->postJson('/api/support/session', ['tenant_id' => $school->id, 'grant_id' => $wide])->assertNoContent();
        $this->getJson("/api/files/$file")->assertOk();
    }

    public function test_expired_or_revoked_the_consent_ends_the_support_session(): void
    {
        [$school, $admin, $operator] = $this->setting();
        $grant = $this->consent($admin, 1);
        $this->asOperator($operator);
        $this->postJson('/api/support/session', ['tenant_id' => $school->id, 'grant_id' => $grant])->assertNoContent();
        $this->getJson('/api/sites')->assertOk();
        // AC2: the database's clock decides; the consent's dates are moved into the past.
        DB::connection('owner')->unprepared("ALTER TABLE school.support_grant DISABLE TRIGGER guard_support_grant;
            UPDATE school.support_grant SET starts_at = now() - interval '2 hours', expires_at = now() - interval '1 hour' WHERE id = '$grant';
            ALTER TABLE school.support_grant ENABLE TRIGGER guard_support_grant;");
        $this->getJson('/api/sites')->assertForbidden()->assertJsonPath('code', 'support_ended');
        $this->getJson('/api/sites')->assertStatus(409)->assertJsonPath('code', 'school_not_selected');
        $this->assertAuthenticated();

        $other = $this->consent($admin, 4);
        $this->asOperator($operator);
        $this->postJson('/api/support/session', ['tenant_id' => $school->id, 'grant_id' => $other])->assertNoContent();
        DB::connection('owner')->update('UPDATE school.support_grant SET revoked_at = now() WHERE id = ?', [$other]);
        $this->getJson('/api/sites')->assertForbidden()->assertJsonPath('code', 'support_ended');
    }

    public function test_the_operator_opens_support_from_the_console(): void
    {
        [$school, $admin, $operator] = $this->setting();
        $this->consent($admin);
        $this->asOperator($operator);
        Filament::setCurrentPanel(Filament::getPanel('console'));
        Livewire::test(ListTenants::class)->searchTable($school->slug)
            ->callTableAction('support', Tenant::query()->findOrFail($school->id))
            ->assertNotified();
        $this->getJson('/api/school')->assertOk()->assertJsonPath('data.id', $school->id)->assertJsonPath('data.support.include_sensitive', false);
    }
}
