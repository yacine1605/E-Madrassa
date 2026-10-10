<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * What the signed-in user may do in the current school (FR-IAM-03, FR-IAM-04): the union of
 * their roles valid today, for the modules the school has, with each grant's scope. Code
 * asks for a permission, never for a role (principle P4, TST-02). Read from the database on
 * each call, so a change of role applies to the very next check.
 */
final class Permissions
{
    /** Granted with any scope. */
    public function can(string $permission): bool
    {
        return (bool) DB::selectOne('SELECT school.can(?) AS granted', [$permission])->granted;
    }

    /** Granted for the whole school. */
    public function everywhere(string $permission): bool
    {
        return (bool) DB::selectOne('SELECT school.can_everywhere(?) AS granted', [$permission])->granted;
    }

    /**
     * Sites the permission covers: null for the whole school, else the sites named by the
     * user's site-scoped grants (an empty list when no grant reaches any site).
     *
     * @return list<string>|null
     */
    public function sites(string $permission): ?array
    {
        $sites = [];
        foreach ($this->grants($permission) as $grant) {
            if ($grant['scope_type'] === 'tenant') return null;
            if ($grant['scope_type'] === 'site') array_push($sites, ...$grant['scope_ids']);
        }
        return array_values(array_unique($sites));
    }

    /** @return list<array{permission: string, sensitive: bool, scope_type: string, scope_ids: list<string>|null}> */
    public function all(): array
    {
        return array_map(fn ($row) => [
            'permission' => $row->permission,
            'sensitive' => (bool) $row->sensitive,
            'scope_type' => $row->scope_type,
            'scope_ids' => $row->scope_ids === null ? null : json_decode($row->scope_ids, true, 4, JSON_THROW_ON_ERROR),
        ], DB::select('SELECT permission, sensitive, scope_type, to_json(scope_ids) AS scope_ids FROM school.my_permissions()'));
    }

    private function grants(string $permission): array
    {
        return array_filter($this->all(), fn ($grant) => $grant['permission'] === $permission);
    }
}
