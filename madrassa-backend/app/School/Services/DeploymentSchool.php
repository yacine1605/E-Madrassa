<?php
namespace App\School\Services;

use Illuminate\Support\Facades\DB;

final class DeploymentSchool
{
    public static function id(): ?string
    {
        return DB::table('school.deployment')->where('id', 1)->value('institution_id');
    }

    public static function membership(int $userId): ?object
    {
        $tenant = self::id();
        if (!$tenant) return null;
        DB::select("select set_config('app.user_id', ?, true)", [(string) $userId]);
        return DB::table('school.memberships')->where('tenant_id', $tenant)
            ->where('user_id', $userId)->where('active', true)->first();
    }
}
