<?php
namespace App\School\Services;

/** Suggestions for new terms only; never mutates the curriculum or stored rules. */
final class ReportCardPresets
{
    public static function forClass(object $class): ?array
    {
        if (strtoupper($class->program_code) !== 'MOYEN' || $class->program_kind !== 'school'
            || substr($class->year_start, 0, 4) !== '2026' || substr($class->year_end, 0, 4) !== '2027'
            || !preg_match('/^([1-4])(?:AM)?$/D', strtoupper($class->level_code), $match)) return null;

        $profile = config('report_card_coefficients.middle_2026_2027');
        if (!$profile) return null;
        $profile['coefficients'] = array_map(fn($values) => $values[(int)$match[1]], $profile['coefficients']);
        return $profile;
    }
}
