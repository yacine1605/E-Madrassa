<?php

namespace Tests\Feature;

use App\Support\DatabaseContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The request's context as the database believes it (SECURITY-REVIEW.md, F7): signed by the
 * application with the key `platform:roles` gave the database, on its own connection.
 */
final class DatabaseContextTest extends TestCase
{
    public function test_the_database_believes_the_context_the_application_signs(): void
    {
        $admin = $this->account('admin');
        $school = $this->school('essential', $admin);
        $this->member($school, $admin);
        $seen = DB::transaction(function () use ($school, $admin) {
            app(DatabaseContext::class)->enter($school->id, $admin['id'], null, 'req-context-0001', '192.0.2.7');
            return DB::selectOne('SELECT school.tenant() AS tenant, school.actor() AS person, school.allowed(?::uuid) AS allowed,
                (school.context()).request_id AS request, (school.context()).ip AS ip', [$school->id]);
        });
        $this->assertEquals((object) ['tenant' => $school->id, 'person' => $admin['id'], 'allowed' => true, 'request' => 'req-context-0001',
            'ip' => '192.0.2.7'], $seen);
    }

    public function test_a_context_forged_or_signed_for_another_connection_is_refused(): void
    {
        $admin = $this->account('admin');
        $school = $this->school('essential', $admin);
        $this->member($school, $admin);
        // The console's connection is another server process.
        $console = DB::connection('console');
        $elsewhere = $console->transaction(function () use ($console, $school, $admin) {
            app(DatabaseContext::class)->enter($school->id, $admin['id'], db: $console);
            return $console->selectOne("SELECT current_setting('app.context') AS c")->c;
        });
        $payload = json_encode(['tenant' => $school->id, 'user' => $admin['id'], 'support' => null, 'request' => null, 'ip' => null,
            'pid' => DB::selectOne('SELECT pg_backend_pid() AS p')->p, 'at' => (int) (microtime(true) * 1_000_000)]);
        foreach ([$elsewhere, $payload.'.'.hash_hmac('sha256', $payload, 'not the key'), $payload] as $token) {
            try {
                DB::transaction(function () use ($token) {
                    DB::select("SELECT set_config('app.context', ?, true)", [$token]);
                    DB::select('SELECT count(*) FROM school.site');
                });
                $this->fail('A context the application did not sign for this connection was believed.');
            } catch (QueryException $e) {
                $this->assertSame('42501', (string) $e->getCode());
                $this->assertStringContainsString('context_invalid', $e->getMessage());
            }
        }
    }

    public function test_the_unsigned_settings_of_lot_one_open_nothing(): void
    {
        $admin = $this->account('admin');
        $school = $this->school('essential', $admin);
        $this->member($school, $admin);
        $seen = DB::transaction(function () use ($school, $admin) {
            DB::select("SELECT set_config('app.tenant_id', ?, true), set_config('app.user_id', ?, true)", [$school->id, (string) $admin['id']]);
            return DB::selectOne('SELECT count(*) AS sites, school.actor() AS person FROM school.site');
        });
        $this->assertEquals((object) ['sites' => 0, 'person' => null], $seen);
    }
}
