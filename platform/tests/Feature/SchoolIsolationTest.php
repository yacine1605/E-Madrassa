<?php

namespace Tests\Feature;

use App\Exceptions\SchoolAccessRevoked;
use App\Support\CurrentSchool;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\CountSites;
use Tests\TestCase;

/** Isolation between schools and the school's states, through the HTTP API (TST-01, TST-04, FR-PLT-01/02). */
final class SchoolIsolationTest extends TestCase
{
    private function site(string $code = 'ANNEXE')
    {
        return $this->postJson('/api/sites', ['code' => $code, 'labels' => ['fr' => 'Annexe', 'ar' => 'الملحق']]);
    }

    public function test_sites_are_isolated_between_schools(): void
    {
        [$a, $b] = [$this->school(), $this->school()];
        [$userA, $userB] = [$this->account('a'), $this->account('b')];
        $this->member($a, $userA, 'active', ['academic_hr']);
        $this->member($b, $userB, 'active', ['academic_hr']);
        $this->signIn($userA);
        $siteA = $this->site()->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');
        $this->getJson('/api/sites')->assertOk()->assertJsonCount(2, 'data');

        $this->signIn($userB);
        $this->getJson('/api/sites')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissing(['id' => $siteA]);
        $this->putJson('/api/sites/'.$siteA, ['labels' => ['fr' => 'Piraté'], 'version' => 1])->assertNotFound();
        $this->postJson('/api/sites/'.$siteA.'/archive', ['reason' => 'Tentative'])->assertNotFound();
        $this->assertSame('Annexe', json_decode(DB::connection('owner')->table('school.site')->where('id', $siteA)->value('labels'), true)['fr']);
    }

    public function test_a_disabled_module_answers_403_module_disabled(): void
    {
        $school = $this->school('custom');
        $account = $this->account();
        $this->member($school, $account, 'active', ['academic_hr']);
        $this->signIn($account);
        $this->getJson('/api/sites')->assertForbidden()->assertJsonPath('code', 'module_disabled');
        $this->console("SELECT platform.enable_module(?, 'sites_rooms')", [$school->id]);
        $this->getJson('/api/sites')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_suspended_school_ends_the_session(): void
    {
        $school = $this->school();
        $account = $this->account();
        $this->member($school, $account);
        $this->signIn($account);
        $this->getJson('/api/school')->assertOk();
        $this->console("SELECT platform.set_tenant_status(?, 'suspended', 'Abonnement impayé')", [$school->id]);
        $this->getJson('/api/school')->assertForbidden()->assertJsonPath('code', 'tenant_suspended');
        $this->getJson('/api/session/schools')->assertUnauthorized();
        $this->signIn($account)->assertForbidden()->assertJsonPath('code', 'no_school');
    }

    public function test_a_suspended_membership_ends_the_session(): void
    {
        $school = $this->school();
        $account = $this->account();
        $this->member($school, $account);
        $this->signIn($account);
        $this->member($school, $account, 'suspended');
        $this->getJson('/api/school')->assertForbidden()->assertJsonPath('code', 'membership_inactive');
        $this->getJson('/api/session/schools')->assertUnauthorized();
    }

    public function test_an_expired_school_reads_but_cannot_write(): void
    {
        $school = $this->school();
        $account = $this->account();
        $this->member($school, $account, 'active', ['academic_hr']);
        $this->console('SELECT platform.set_entitlement(?, ?::date, ?::date, 15, 500, 1024, 0)', [$school->id, '2025-01-01', '2025-06-30']);
        $this->signIn($account)->assertOk();
        $this->getJson('/api/school')->assertOk()->assertJsonPath('data.mode', 'read_only');
        $this->getJson('/api/sites')->assertOk()->assertJsonCount(1, 'data');
        $this->site()->assertForbidden()->assertJsonPath('code', 'tenant_read_only');
    }

    public function test_a_stale_version_is_a_conflict(): void
    {
        $school = $this->school();
        $account = $this->account();
        $this->member($school, $account, 'active', ['academic_hr']);
        $this->signIn($account);
        $id = $this->site()->json('data.id');
        $this->putJson('/api/sites/'.$id, ['labels' => ['fr' => 'Annexe nord'], 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/sites/'.$id, ['labels' => ['fr' => 'Annexe sud'], 'version' => 1])->assertStatus(409)->assertJsonPath('code', 'stale_version');
    }

    public function test_the_last_site_cannot_be_archived(): void
    {
        $school = $this->school();
        $account = $this->account();
        $this->member($school, $account, 'active', ['academic_hr']);
        $this->signIn($account);
        $annex = $this->site()->json('data.id');
        $main = collect($this->getJson('/api/sites')->json('data'))->firstWhere('code', 'MAIN')['id'];
        $this->postJson('/api/sites/'.$annex.'/archive', ['reason' => 'Fermeture'])->assertOk();
        $this->postJson('/api/sites/'.$main.'/archive', ['reason' => 'Fermeture'])->assertStatus(409)->assertJsonPath('code', 'last_site');
        $this->getJson('/api/sites?include_archived=1')->assertJsonCount(2, 'data');
    }

    public function test_changes_are_audited_with_author_reason_request_and_address(): void
    {
        $school = $this->school();
        $account = $this->account();
        $this->member($school, $account, 'active', ['academic_hr']);
        $this->signIn($account);
        $id = $this->site()->json('data.id');
        $this->withHeaders(['X-Request-Id' => 'req-audit-0001'])
            ->postJson('/api/sites/'.$id.'/archive', ['reason' => 'Bail terminé'])->assertOk()->assertHeader('X-Request-Id', 'req-audit-0001');
        $entry = DB::connection('owner')->selectOne("SELECT actor_id, reason, request_id, ip, changed_fields FROM school.audit_log
            WHERE object_id = ? AND action = 'UPDATE'", [$id]);
        $this->assertSame([$account['id'], 'Bail terminé', 'req-audit-0001', '127.0.0.1'],
            [(int) $entry->actor_id, $entry->reason, $entry->request_id, $entry->ip]);
    }

    public function test_jobs_run_in_their_school_and_recheck_access(): void
    {
        [$a, $b] = [$this->school(), $this->school()];
        $account = $this->account();
        $this->member($a, $account, 'active', ['academic_hr']);
        $this->member($b, $account);
        $this->signIn($account);
        $this->putJson('/api/session/school', ['tenant_id' => $a->id]);
        $this->site('JOB-A');

        CountSites::dispatch($a->id, $account['id']);
        $this->assertSame(2, CountSites::$seen);
        CountSites::dispatch($b->id, $account['id']);
        $this->assertSame(1, CountSites::$seen);

        $this->member($b, $account, 'suspended');
        $this->expectException(SchoolAccessRevoked::class);
        CountSites::dispatch($b->id, $account['id']);
    }

    public function test_cache_and_file_keys_are_separated_per_school(): void
    {
        [$a, $b] = [$this->school(), $this->school()];
        $keys = [];
        foreach ([$a, $b] as $school) {
            DB::transaction(function () use ($school, &$keys) {
                $current = new CurrentSchool();
                $current->enter($school->id, 1, 'req-keys-0001');
                $keys[] = [$current->cacheKey('menu'), $current->storagePath('photos/eleve.jpg')];
            });
        }
        $this->assertSame(['school:'.$a->id.':menu', 'schools/'.$a->id.'/photos/eleve.jpg'], $keys[0]);
        $this->assertNotSame($keys[0], $keys[1]);
        $this->expectException(LogicException::class);
        (new CurrentSchool())->cacheKey('menu');
    }

    public function test_the_console_cannot_read_school_data(): void
    {
        $this->school();
        try {
            $this->console('SELECT count(*) FROM school.site');
            $this->fail('The console read school data.');
        } catch (QueryException $e) {
            $this->assertSame('42501', (string) $e->getCode());
        }
    }
}
