<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * The publisher's console (W1.2) on its own database role, `platform_admin`: platform data and
 * the platform's functions, no school data (FR-PLT-04 AC1). Every change runs in a transaction
 * that names the operator and the reason, which the school's journal records (FR-PLT-01).
 */
final class Console
{
    private function db(): Connection
    {
        return DB::connection('console');
    }

    /** @template T @param callable(Connection): T $work @return T */
    private function as(?string $reason, callable $work): mixed
    {
        return $this->db()->transaction(function (Connection $db) use ($reason, $work) {
            $db->select("SELECT set_config('app.user_id', ?, true), set_config('app.reason', ?, true)", [(string) auth()->id(), (string) $reason]);
            return $work($db);
        });
    }

    /**
     * FR-PLT-01: the school, its default site, its profile, its plan's modules and its first
     * administrator invited by email. The slug decides the creation key: submitted twice, one
     * school (AC3).
     *
     * @return array{tenant: string, delivery: string, activation_url: ?string}
     */
    public function createSchool(array $data): array
    {
        $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'madrassa:school:'.$data['slug'])->toString();
        $tenant = $this->as('Création', function (Connection $db) use ($data, $key) {
            $admin = $db->selectOne('SELECT platform.ensure_user(?, ?) AS id', [$data['admin_email'], $data['admin_name']])->id;
            return $db->selectOne('SELECT platform.create_tenant(?, ?, ?, ?, ?, ?::date, ?::date, ?, ?, ?, ?, ?, ?) AS id', [
                $key, $data['slug'], $data['name'], $data['default_locale'], $data['plan'], $data['valid_from'], $data['valid_until'] ?? null,
                (int) $data['quota_active_students'], (int) $data['quota_storage_mb'], $data['site_code'] ?? 'MAIN', $data['site_name'] ?? 'Site principal',
                $admin, $data['profile']])->id;
        });
        return ['tenant' => $tenant, ...$this->inviteAdmin($tenant)];
    }

    /**
     * The activation link of the administrator named at creation; when sending fails the school
     * still exists and the link is shown once to hand over (FR-PLT-01, FR-IAM-01).
     *
     * @return array{delivery: string, activation_url: ?string}
     */
    public function inviteAdmin(string $tenant): array
    {
        [$token, $hash] = AccountLinks::token();
        $admin = $this->as('Invitation', fn (Connection $db) => $db->selectOne('SELECT email, name FROM platform.invite_admin(?, ?)', [$tenant, $hash]));
        $school = $this->db()->selectOne('SELECT name FROM platform.tenant WHERE id = ?', [$tenant])->name;
        if (AccountLinks::send('activation', $admin->email, $admin->name, $token, $school)) return ['delivery' => 'sent', 'activation_url' => null];
        return ['delivery' => 'failed', 'activation_url' => AccountLinks::url('activation', $token)];
    }

    /** Suspended, active again, or archived for good; always with a reason. */
    public function setStatus(string $tenant, string $status, string $reason): void
    {
        $this->as($reason, fn (Connection $db) => $db->select('SELECT platform.set_tenant_status(?, ?, ?)', [$tenant, $status, $reason]));
    }

    /** The school's modules become exactly these, their dependencies included (FR-PLT-02). */
    public function setModules(string $tenant, array $modules): void
    {
        $this->as('Modules', function (Connection $db) use ($tenant, $modules) {
            foreach ($modules as $module) $db->select('SELECT platform.enable_module(?, ?)', [$tenant, $module]);
            // The others go, a module required by one still enabled last: passes until nothing more can go.
            do {
                $enabled = array_column($db->select('SELECT module_key FROM platform.tenant_module WHERE tenant_id = ?', [$tenant]), 'module_key');
                $removed = 0;
                foreach ($this->removalOrder(array_diff($enabled, $modules, ['core'])) as $module) {
                    if ($this->requiredByEnabled($db, $tenant, $module)) continue;
                    $db->select('SELECT platform.disable_module(?, ?)', [$tenant, $module]);
                    $removed++;
                }
            } while ($removed > 0);
        });
    }

    public function setEntitlement(string $tenant, array $data): void
    {
        $this->as('Abonnement', fn (Connection $db) => $db->select('SELECT platform.set_entitlement(?, ?::date, ?::date, ?, ?, ?, ?)', [$tenant,
            $data['valid_from'], $data['valid_until'] ?? null, (int) $data['grace_days'], (int) $data['quota_active_students'],
            (int) $data['quota_storage_mb'], (int) ($data['quota_ai_requests_month'] ?? 0)]));
    }

    /** FR-PLT-03: a newer profile version, only on request; it adds and never overwrites. */
    public function applyProfile(string $tenant, string $profile): int
    {
        return (int) $this->as('Profil', fn (Connection $db) => $db->selectOne('SELECT platform.apply_profile(?, ?) AS v', [$tenant, $profile])->v);
    }

    /** FR-PLT-04: the school's consents, their dates and use; never the school's data. */
    public function supportGrants(string $tenant): array
    {
        return $this->db()->select('SELECT * FROM platform.support_grants(?)', [$tenant]);
    }

    /** FR-PLT-05: each school by its numbers. */
    public function supervision(): array
    {
        return $this->db()->select('SELECT * FROM platform.supervision()');
    }

    private function requiredByEnabled(Connection $db, string $tenant, string $module): bool
    {
        return (bool) $db->selectOne('SELECT 1 AS needed FROM platform.module_dependency d JOIN platform.tenant_module m ON m.tenant_id = ? AND m.module_key = d.module_key
            WHERE d.requires_key = ?', [$tenant, $module]);
    }

    /** Modules that others depend on come last. */
    private function removalOrder(array $modules): array
    {
        $dependents = array_column($this->db()->select('SELECT DISTINCT requires_key FROM platform.module_dependency'), 'requires_key');
        usort($modules, fn ($a, $b) => (int) in_array($a, $dependents, true) <=> (int) in_array($b, $dependents, true));
        return $modules;
    }
}
