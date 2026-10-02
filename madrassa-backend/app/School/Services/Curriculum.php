<?php
namespace App\School\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Curriculum
{
    public static function levelForCohort(string $tenant, string $cohort): ?string
    {
        return DB::table('school.cohorts as c')
            ->join('school.offerings as o', fn ($join) => $join->on('o.id', '=', 'c.offering_id')->on('o.tenant_id', '=', 'c.tenant_id'))
            ->where('c.tenant_id', $tenant)->where('c.id', $cohort)->value('o.level_id');
    }

    public static function validateSubject(string $tenant, string $cohort, string $subject): void
    {
        $level = self::levelForCohort($tenant, $cohort);
        if (!$level || !DB::table('school.curriculum')->where('tenant_id', $tenant)
            ->where('level_id', $level)->where('subject_id', $subject)->exists()) {
            throw ValidationException::withMessages(['subject_id'=>'La matière doit appartenir au programme du niveau de cette classe.']);
        }
    }
}
