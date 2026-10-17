<?php

namespace App\Support;

use App\Exceptions\Refusal;
use Illuminate\Support\Facades\DB;

/**
 * FR-PLN-01: a session given its start without its end lasts the length of a session of its
 * subject in its group's programme that day (FR-PRG-02), else the school's (`planning.session_minutes`,
 * SQL 028). One that would end past midnight is refused, as any session spanning two days.
 */
final class SessionLength
{
    /** The school's length, in minutes. */
    public static function minutes(): int
    {
        return (int) (DB::selectOne("SELECT school.setting_value('planning.session_minutes') #>> '{}' AS minutes")->minutes ?? 60);
    }

    /** Its end, `HH:MM`, at most 24:00. */
    public static function end(string $start, string $group, string $subject, string $day): string
    {
        $length = DB::selectOne("SELECT ps.session_duration_minutes AS minutes FROM school.study_group g JOIN school.program_subject ps
            ON ps.tenant_id = g.tenant_id AND ps.program_id = g.program_id AND ps.subject_id = ? AND ps.archived_at IS NULL
            AND ps.valid_from <= ?::date AND (ps.valid_to IS NULL OR ps.valid_to >= ?::date)
            WHERE g.tenant_id = school.tenant() AND g.id = ? ORDER BY ps.valid_from DESC LIMIT 1", [$subject, $day, $day, $group])?->minutes;
        [$hours, $minutes] = array_map('intval', explode(':', $start));
        $end = $hours * 60 + $minutes + (int) ($length ?? self::minutes());
        if ($end > 1440) throw Refusal::of('session_spans_days', 422);
        return sprintf('%02d:%02d', intdiv($end, 60), $end % 60);
    }
}
