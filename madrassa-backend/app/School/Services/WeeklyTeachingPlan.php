<?php
namespace App\School\Services;

final class WeeklyTeachingPlan
{
    public static function baseMinutes(string $cycle, string $grade): array
    {
        $ref = config('algerian_curriculum.weekly_reference');
        if ($cycle === 'PRESCOLAIRE') {
            $minutes = $ref['preschool'];
            if (in_array($cycle, $ref['language_support']['cycles'], true)) unset($minutes['PRE-ATELIER']);
            return $minutes;
        }
        if ($cycle !== 'PRIMAIRE' || !isset($ref['primary'][$grade])) return [];
        $minutes = $ref['primary'][$grade];
        foreach ($ref['private_supplements'][$grade] as $subject => $duration) {
            if (isset($minutes[$subject])) throw new \LogicException('Supplement duplicates a core subject.');
            $minutes[$subject] = $duration;
        }
        return $minutes;
    }

    public static function supportMinutes(string $cycle, string $grade): array
    {
        $ref = config('algerian_curriculum.weekly_reference');
        $base = self::baseMinutes($cycle, $grade);
        if (!$base || !in_array($cycle, $ref['language_support']['cycles'], true)) return [];
        $remaining = $ref['school_week_minutes'] - array_sum($base);
        $english = $ref['language_support']['english_minutes'];
        if ($remaining < $english) throw new \LogicException('Not enough workshop time for English support.');
        return array_filter(['PRI-EN'=>$english, 'PRI-FR'=>$remaining-$english], fn($minutes) => $minutes > 0);
    }

    public static function minutes(string $cycle, string $grade): array
    {
        $minutes = self::baseMinutes($cycle, $grade);
        foreach (self::supportMinutes($cycle, $grade) as $subject => $duration) {
            $minutes[$subject] = ($minutes[$subject] ?? 0) + $duration;
        }
        if ($minutes && array_sum($minutes) !== config('algerian_curriculum.weekly_reference.school_week_minutes')) {
            throw new \LogicException('Teaching plan must fill the school opening hours.');
        }
        return $minutes;
    }
}
