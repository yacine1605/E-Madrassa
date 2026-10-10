<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * Integration tests against PostgreSQL 16, with the real roles: requests run as
 * school_runtime, the console as platform_admin. Each test creates its own accounts and
 * schools, so tests never depend on one another.
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $prepared = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$prepared) return;
        $owner = DB::connection('owner');
        if (!str_ends_with((string) $owner->getDatabaseName(), '_test')) {
            throw new RuntimeException('Tests run only on a disposable database whose name ends in _test.');
        }
        $owner->unprepared('DROP SCHEMA IF EXISTS school CASCADE; DROP SCHEMA IF EXISTS platform CASCADE;');
        Artisan::call('db:wipe', ['--database' => 'owner', '--force' => true]);
        Artisan::call('migrate', ['--database' => 'owner', '--force' => true]);
        Artisan::call('platform:roles');
        self::$prepared = true;
    }

    /**
     * An activated person, with MFA unless told otherwise: most roles here hold a sensitive
     * permission, and then MFA is compulsory (FR-IAM-02).
     *
     * @return array{id: int, email: string, password: string, mfa_secret: ?string}
     */
    protected function account(string $label = 'user', bool $mfa = true): array
    {
        $email = $label.'-'.Str::lower(Str::random(10)).'@example.test';
        $password = 'Secret-'.Str::random(12);
        $secret = $mfa ? (new Google2FA())->generateSecretKey(32) : null;
        $id = DB::connection('owner')->table('users')->insertGetId(['name' => $label, 'email' => $email,
            'password' => Hash::make($password), 'created_at' => now(), 'updated_at' => now(),
            'mfa_secret' => $secret ? Crypt::encryptString($secret) : null, 'mfa_enabled_at' => $mfa ? now() : null]);
        return ['id' => $id, 'email' => $email, 'password' => $password, 'mfa_secret' => $secret];
    }

    /** The current code of the account's authenticator; the last step is forgotten so that tests may sign in again at once. */
    protected function totp(array $account): string
    {
        DB::connection('owner')->table('users')->where('id', $account['id'])->update(['mfa_last_step' => null]);
        return (new Google2FA())->getCurrentOtp($account['mfa_secret']);
    }

    /** A school created through the console's own function, as the publisher would; its administrator, if any, is invited. */
    protected function school(string $plan = 'essential', ?array $admin = null): object
    {
        $slug = 'test-'.Str::lower(Str::random(12));
        $id = DB::connection('console')->selectOne('SELECT platform.create_tenant(?, ?, ?, ?, ?, ?::date, ?::date, ?, ?, ?, ?, ?) AS id',
            [(string) Str::uuid(), $slug, 'École '.$slug, 'fr', $plan, now()->toDateString(), null, 500, 1024, 'MAIN', 'Site principal', $admin['id'] ?? null])->id;
        return (object) ['id' => $id, 'slug' => $slug];
    }

    /** Sets the account's membership, and gives it these school roles for the whole school. */
    protected function member(object $school, array $account, string $status = 'active', array $roles = []): void
    {
        DB::connection('owner')->statement('INSERT INTO school.membership (tenant_id, user_id, status) VALUES (?, ?, ?)
            ON CONFLICT (tenant_id, user_id) DO UPDATE SET status = EXCLUDED.status', [$school->id, $account['id'], $status]);
        foreach ($roles as $role) $this->grant($school, $account, $role);
    }

    /** A role assignment written as provisioning would, with a scope of the whole school or of some sites. */
    protected function grant(object $school, array $account, string $role, string $scope = 'tenant', ?array $ids = null): string
    {
        return DB::connection('owner')->selectOne('INSERT INTO school.role_assignment (tenant_id, user_id, role_id, scope_type, scope_ids)
            SELECT ?, ?, id, ?, ?::uuid[] FROM school.role WHERE tenant_id = ? AND code = ? RETURNING id',
            [$school->id, $account['id'], $scope, $ids === null ? null : '{'.implode(',', $ids).'}', $school->id, $role])->id;
    }

    protected function roleId(object $school, string $role): string
    {
        return DB::connection('owner')->selectOne('SELECT id FROM school.role WHERE tenant_id = ? AND code = ?', [$school->id, $role])->id;
    }

    protected function console(string $sql, array $bindings = []): array
    {
        return DB::connection('console')->select($sql, $bindings);
    }

    /** Signs out whoever is signed in, then signs in this account. */
    protected function signIn(array $account, ?string $password = null): TestResponse
    {
        $this->deleteJson('/api/session');
        $response = $this->postJson('/api/session', ['login' => $account['email'], 'password' => $password ?? $account['password']]);
        if (!$response->json('mfa_required')) return $response;
        return $this->postJson('/api/session/mfa', ['code' => $this->totp($account)]);
    }
}
